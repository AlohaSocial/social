<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\BlueskySearch;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\AccountNotSetUpException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\BlockedByException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheContentDecodeException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheContentSizeException;
use OCA\Social\Exceptions\InvalidHandleException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\RefusalException;
use OCA\Social\External\ExternalUserBackend;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\Details;
use OCA\Social\Response\RangedFileResponse;
use OCA\Social\Security\RemoteAddress;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Throwable;

class LocalController extends Controller {
	/** Ceiling for a banner fetched by URL. */
	private const BANNER_MAX_SIZE = 10 * 1024 * 1024;

	use OwnAccount;
	use TArrayTools;
	use TNCDataResponse;

	private ?string $userId = null;
	private ?Person $viewer = null;
	private ?bool $mayAskOtherServers = null;

	public function __construct(
		IRequest $request,
		?string $userId,
		private AccountService $accountService,
		private CacheActorService $cacheActorService,
		private CacheActorsRequest $cacheActorsRequest,
		private HashtagService $hashtagService,
		private FollowService $followService,
		private StreamService $streamService,
		private DocumentService $documentService,
		private ConfigService $configService,
		private LoggerInterface $logger,
		private CacheDocumentService $cacheDocumentService,
		private BannerService $bannerService,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IdentityService $atprotoIdentities,
		private ?BlueskySearch $blueskySearch = null,
		private ?\OCA\Social\Atproto\Profile\ProfileLinks $profileLinks = null,
		private ?\OCA\Social\Service\HiddenCollectionsService $hiddenCollections = null,
		private ?ClientService $clientService = null,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->userId = $userId;
	}

	/**
	 * Upload a banner/header image for the current user's profile.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/banner')]
	public function uploadBanner(): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$path = $this->uploadedBannerPath();
			$this->ownAccount();

			$image = $this->bannerService->setFromTempFile($this->userId, $path);

			$this->logger->info('[LocalController] Banner uploaded', [
				'userId' => $this->userId,
				'url' => $image->getUrl()
			]);

			return $this->success([
				'url' => $image->getUrl(),
				'id' => $image->getId()
			]);
		} catch (
			CacheContentDecodeException
			|CacheContentMimeTypeException
			|CacheContentSizeException
			|InvalidResourceException $e
		) {
			// the file is the problem, not the server: not a picture, a refused
			// type, or too big. `message` carries the reason, which `fail()`
			// leaves out of `error` on a public route; these four say nothing
			// but what is wrong with the caller's own file.
			return $this->fail(
				$e,
				['message' => $this->uploadFailureMessage($e)] + $this->uploadFailureCode($e),
				Http::STATUS_BAD_REQUEST,
				false
			);
		} catch (Exception $e) {
			$this->logger->error('[LocalController] uploadBanner failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return $this->failFor($e);
		}
	}

	/**
	 * The uploaded banner's temporary path, or an exception saying what the
	 * reader can do about it. An empty `$_FILES` may mean the body exceeded
	 * `post_max_size`, so the real limit (the smallest of three) is read
	 * rather than assumed.
	 *
	 * @throws CacheContentSizeException the file is larger than this server takes
	 * @throws RefusalException there is no usable file in the request: no-file, upload-interrupted
	 * @throws Exception the upload failed on this side
	 */
	private function uploadedBannerPath(): string {
		$file = $_FILES['file'] ?? [];

		if ($file === []) {
			$sent = (int)$this->request->getHeader('Content-Length');
			if ($sent > 0 && $sent > $this->iniBytes('post_max_size')) {
				throw new CacheContentSizeException($this->tooBig());
			}

			throw new RefusalException('No file was sent.', 'no-file');
		}

		return match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
			UPLOAD_ERR_OK => $file['tmp_name'] ?? '',
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE
				=> throw new CacheContentSizeException($this->tooBig()),
			UPLOAD_ERR_NO_FILE => throw new RefusalException('No file was sent.', 'no-file'),
			UPLOAD_ERR_PARTIAL => throw new RefusalException(
				'The upload did not finish. Try again.', 'upload-interrupted'
			),
			// no tmp dir, cannot write, an extension refused it: this side's
			// problem, and the only one of these that is a 500
			default => throw new Exception(
				'the upload failed on the server, error ' . $file['error']
			),
		};
	}

	/** The largest banner this server will take, as the reader should read it. */
	private function tooBig(): string {
		return 'That picture is larger than this server accepts (at most '
			. Util::humanFileSize($this->bannerCeiling()) . ').';
	}

	/** The largest banner this server will take, in bytes. */
	private function bannerCeiling(): int {
		return min(
			$this->iniBytes('upload_max_filesize'),
			$this->iniBytes('post_max_size'),
			$this->configService->getAppValueInt(ConfigService::SOCIAL_MAX_SIZE) * 1024 * 1024
		);
	}

	/** A php.ini size, in bytes. `0` there means no limit, which min() must not win. */
	private function iniBytes(string $key): int {
		$size = Util::computerFileSize((string)ini_get($key));
		$size = ($size === false) ? 0 : (int)$size;

		return ($size > 0) ? $size : PHP_INT_MAX;
	}

	/**
	 * The code of a file this app would not take (API.md), with the largest
	 * size there is for one that was too big.
	 *
	 * @return array<string, string>
	 */
	private function uploadFailureCode(Exception $e): array {
		return match (true) {
			$e instanceof RefusalException => ['code' => $e->errorCode] + array_map('strval', $e->params),
			$e instanceof CacheContentSizeException => [
				'code' => 'banner-too-large',
				'limit' => Util::humanFileSize($this->bannerCeiling()),
			],
			default => ['code' => 'banner-unusable'],
		};
	}

	/** What to tell the caller about a file this app would not take. */
	private function uploadFailureMessage(Exception $e): string {
		$message = trim($e->getMessage());

		// CacheContentMimeTypeException is raised bare in places, and an empty
		// string on screen is worse than no message at all
		return ($message === '')
			? 'That file cannot be used as a banner.'
			: $message;
	}

	/**
	 *
	 * @param string $url
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/banner/url')]
	public function uploadBannerByUrl(string $url = ''): DataResponse {
		$tmpFile = null;
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			if ($url === '') {
				throw new Exception('No URL provided');
			}

			// A user hands us this URL, so it must not become a way to read the
			// server's own network. Only http(s) to a non-local host, no local
			// addresses unless the admin opted in, and a hard size ceiling.
			$parsed = parse_url($url);
			$scheme = strtolower($parsed['scheme'] ?? '');
			$host = $parsed['host'] ?? '';
			if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
				throw new Exception('Unsupported banner URL');
			}
			$allowLocal = $this->configService->isLocalNetworkAllowed();
			if (!$allowLocal && RemoteAddress::isLocalHost($host)) {
				throw new Exception('Unsupported banner URL');
			}

			$this->ownAccount();
			$this->logger->info('[LocalController] Banner upload by URL', [
				'userId' => $this->userId,
				'host' => $host,
			]);

			// the app's own HTTP client re-checks the target on every redirect
			// and pins the resolved address, so a 302 to 127.0.0.1 or a
			// metadata endpoint is refused; it also applies the access list
			// and the size ceiling
			$content = $this->cacheDocumentService->retrieveContent($url);
			if (strlen($content) > self::BANNER_MAX_SIZE) {
				throw new Exception('Banner image is too large');
			}

			$tmpFile = tempnam(sys_get_temp_dir(), 'social_banner_');
			if ($tmpFile === false || file_put_contents($tmpFile, $content) === false) {
				throw new Exception('Cannot store the downloaded banner');
			}

			$image = $this->bannerService->setFromTempFile($this->userId, $tmpFile);

			$this->logger->info('[LocalController] Banner uploaded via URL', [
				'userId' => $this->userId,
				'url' => $image->getUrl(),
			]);

			return $this->success([
				'url' => $image->getUrl(),
				'id' => $image->getId(),
			]);
		} catch (CacheContentDecodeException|CacheContentMimeTypeException|CacheContentSizeException $e) {
			// what is at the other end of the address is the problem, not the
			// server — see the same catch on the upload above
			return $this->fail($e, [], Http::STATUS_BAD_REQUEST, false);
		} catch (Exception $e) {
			$this->logger->error('[LocalController] uploadBannerByUrl failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			return $this->failFor($e);
		} finally {
			if (is_string($tmpFile) && $tmpFile !== '' && file_exists($tmpFile)) {
				unlink($tmpFile);
			}
		}
	}

	/**
	 * Delete your own post.
	 *
	 * @param string $id
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/post')]
	public function postDelete(string $id): DataResponse {
		try {
			$actor = $this->ownAccount();
			$note = $this->streamService->getStreamById($id);
			if ($note->getAttributedTo() !== $actor->getId()) {
				throw new InvalidResourceException('user have no rights');
			}

			// by its own type: a poll is a `Question`, and a delete guarded to
			// `Note` would federate the Delete and leave the poll in place
			$this->streamService->deleteLocalItem($note, $note->getType());

			return $this->success();
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * Creates the reader's account, with the handle they chose
	 * (`AccountService::assertHandleAvailable()`), from the setup screen. The
	 * account comes back as the client entity so the page needs no reload.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/account/create')]
	public function accountCreate(string $username = ''): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}
			$username = trim($username);
			if ($username === '') {
				$username = $this->accountService->generateHandleFromUserId($this->userId);
			}
			$this->accountService->assertHandleAvailable($this->userId, $username);
			$this->accountService->createActor($this->userId, $username);

			$viewer = $this->accountService->getCachedLocalActor($username);
			$viewer->setExportFormat(ACore::FORMAT_LOCAL);

			return $this->success(['account' => $viewer]);
		} catch (InvalidHandleException|AccountAlreadyExistsException $e) {
			// the reason is the answer: which handle to try instead
			return new DataResponse(['status' => -1] + $e->toArray(), Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * Names the account the reader already has elsewhere on their Nextcloud
	 * profile, and creates nothing here: the `fediverse` profile field is
	 * where Discover looks.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/account/link')]
	public function accountLink(string $handle = ''): DataResponse {
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}

			return $this->success(['handle' => $this->accountService->linkExternalHandle($this->userId, $handle)]);
		} catch (InvalidHandleException $e) {
			return new DataResponse(['status' => -1] + $e->toArray(), Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * Deletes the reader's own Social account, keeping their Nextcloud one,
	 * unless they are a self-registered external user, whose Nextcloud account
	 * exists for Social only and is deleted with it (`userDeleted`).
	 *
	 * The same deletion as `occ social:account:delete`: posts and follows go,
	 * and a `Delete` goes out to every server that knew the account.
	 * `confirm` is the handle, not a password, since SSO accounts have none.
	 * Cannot be undone; the handle is held for the retention hour.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 3, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/account/delete')]
	public function accountDelete(string $confirm = ''): DataResponse {
		$sessionEnded = false;
		$externalDeletion = false;
		try {
			if ($this->userId === null) {
				throw new AccountDoesNotExistException('User not logged in');
			}

			$user = $this->userManager->get($this->userId);
			if (ExternalUserBackend::isExternal($user)) {
				$externalDeletion = true;
				// Nextcloud owns the deletion: its UserDeletedEvent listener
				// removes and federates the Social actor once the account is gone
				$this->accountService->assertOwnAccountDeletionConfirmed($this->userId, $confirm);
				$this->userSession->logout();
				$sessionEnded = true;
				if (!$user->delete()) {
					throw new \RuntimeException('Nextcloud did not delete the external user');
				}

				return $this->success(['deleted' => true, 'userDeleted' => true]);
			}

			$this->accountService->deleteOwnAccount($this->userId, $confirm);

			return $this->success(['deleted' => true]);
		} catch (InvalidResourceException $e) {
			// the message names the handle to type, which is the whole of the
			// help there is
			return new DataResponse(['status' => -1, 'error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			// listeners can throw an Error too; if the user is already gone,
			// finish the session and report completion
			if ($externalDeletion && $this->userId !== null && $this->userManager->get($this->userId) === null) {
				if (!$sessionEnded) {
					$this->userSession->logout();
				}

				return $this->success(['deleted' => true, 'userDeleted' => true]);
			}
			if ($e instanceof Exception) {
				return $this->failFor($e);
			}

			$this->logger->error('self-service account deletion failed', [
				'userId' => $this->userId,
				'exception' => $e,
			]);

			return new DataResponse(['status' => -1, 'error' => 'request failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/current/follow')]
	public function actionFollow(string $account): DataResponse {
		try {
			$actor = $this->ownAccount();
			if ($this->followService->followAccount($actor, $account)) {
				$this->accountService->bumpActorCount($actor->getId(), 'count_following', 1);
			}

			return $this->success([]);
		} catch (BlockedByException $e) {
			// the reason is the person's to read, not an internal failure
			return new DataResponse(['status' => -1, 'error' => $e->getMessage(), 'blocked_by' => true], Http::STATUS_FORBIDDEN);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/current/follow')]
	public function actionUnfollow(string $account): DataResponse {
		try {
			$actor = $this->ownAccount();
			if ($this->followService->unfollowAccount($actor, $account)) {
				$this->accountService->bumpActorCount($actor->getId(), 'count_following', -1);
			}

			return $this->success([]);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * Replace the current user's profile metadata fields (the name/value
	 * table under the bio, at most four entries).
	 *
	 * @param array $fields [['name' => string, 'value' => string], …]
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/account/fields')]
	public function accountFields(array $fields = []): DataResponse {
		try {
			$local = $this->ownAccount();
			$this->accountService->setFields($this->userId, $fields);

			$actor = $this->cacheActorService->getFromLocalAccount($local->getPreferredUsername());

			return $this->success(['account' => $actor]);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * One local account, as a profile page shows it. Rate limited, more
	 * loosely than `globalAccountInfo`, so the route cannot be used to read
	 * out the user list. A name nobody holds is a 404 and is not logged.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/account/{username}/info')]
	public function accountInfo(string $username): DataResponse {
		try {
			$this->initViewer();

			$actor = $this->getLocalAccountWithCacheFallback($username);
			$actor->setCompleteDetails(true);
			$actor->setExportFormat(ACore::FORMAT_LOCAL);

			// what the profile page needs to show the counts without the lists
			$this->hiddenCollections?->annotate($actor);

			return new DataResponse($this->attachBluesky($actor), Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * Everything known about one account, resolving an unknown handle
	 * remotely: up to six outbound requests, hence throttled like
	 * `OStatusController::getLink`. A GET without CSRF, so other servers are
	 * only asked when `mayAskOtherServers()` says the request is genuine;
	 * otherwise it answers from what this server holds.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 300)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/global/account/info')]
	public function globalAccountInfo(string $account): DataResponse {
		$this->logger->debug('[LocalController] globalAccountInfo called', ['account' => $account]);
		try {
			$this->initViewer();

			// Check if this is a local account and ensure actor exists
			$account = ltrim($account, '@');
			$parts = explode('@', $account, 2);
			$username = $parts[0];
			$domain = $parts[1] ?? '';

			// If this is a local account, ensure the actor is created
			$isLocal = $domain === '';
			if (!$isLocal) {
				try {
					$cloudHost = $this->configService->getCloudHost();
					$socialAddress = $this->configService->getSocialAddress();
					$isLocal = ($domain === $cloudHost || $domain === $socialAddress);
				} catch (Exception $e) {
					$this->logger->debug('[LocalController] Could not get cloud config', ['exception' => $e->getMessage()]);
				}
			}

			if ($isLocal && $this->userId === $username) {
				$this->logger->debug('[LocalController] Local account detected', ['username' => $username]);
				try {
					// reading a profile does not create one; the route is
					// public, so it must never mint an identity for a user
					$this->accountService->getActorFromUserId($username);
					$this->accountService->cacheLocalActorByUsername($username);
					$this->logger->debug('[LocalController] Local actor ensured', ['username' => $username]);
				} catch (Exception $e) {
					$this->logger->warning('[LocalController] Failed to ensure local actor', [
						'username' => $username,
						'exception' => $e->getMessage()
					]);
				}
			}

			if ($isLocal) {
				try {
					$actor = $this->getLocalAccountWithCacheFallback($username);
				} catch (Exception $e) {
					// a local user keeps a dotted name; one nobody here holds
					// that is shaped like a handle is a Bluesky account
					if ($domain !== '' || !BlueskyIds::isHandle($account)) {
						throw $e;
					}
					$actor = $this->cacheActorService->getFromAccount($account, $this->mayAskOtherServers());
				}
			} else {
				$actor = $this->cacheActorService->getFromAccount($account, $this->mayAskOtherServers());
			}
			$actor->setExportFormat(ACore::FORMAT_LOCAL);

			// For remote actors, fetch follower/following/post counts
			if (!$actor->isLocal() && $this->mayAskOtherServers()) {
				try {
					$this->cacheActorService->addRemoteActorDetailCount($actor);
				} catch (Exception $e) {
					$this->logger->debug('[LocalController] Failed to fetch remote actor details', [
						'account' => $account,
						'error' => $e->getMessage()
					]);
				}
			}

			$this->logger->debug('[LocalController] Actor info retrieved', ['actorId' => $actor->getId()]);
			// what the profile page needs to show the counts without the lists
			$this->hiddenCollections?->annotate($actor);

			return new DataResponse($this->attachBluesky($actor), Http::STATUS_OK);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * The actor an id names, resolving it remotely only for a caller with a
	 * session. The routes below are public and the id comes from the query
	 * string, so resolving for anyone would let them make this instance
	 * fetch and store arbitrary URLs.
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws Exception
	 */
	private function knownActor(string $id): Person {
		$posAnchor = strpos($id, '#');
		if ($posAnchor !== false) {
			$id = substr($id, 0, $posAnchor);
		}

		try {
			return $this->cacheActorsRequest->getFromId($id);
		} catch (CacheActorDoesNotExistException $e) {
			if ($this->userId === null) {
				$this->logger->debug('[LocalController] refusing to resolve an unknown actor id', [
					'id' => $id,
				]);

				throw new CacheActorDoesNotExistException('unknown actor');
			}

			return $this->cacheActorService->getFromId($id);
		}
	}

	/**
	 * The cached copy of a local account, rebuilt when it has gone missing
	 * (in `AccountService`, which the rendered page uses too).
	 *
	 * @throws CacheActorDoesNotExistException
	 */
	private function getLocalAccountWithCacheFallback(string $username): Person {
		return $this->accountService->getCachedLocalActor($username);
	}

	/**
	 * One cached actor's avatar, rate limited like `/media/{uuid}`. Generous,
	 * since a timeline draws forty to sixty at once.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 3000, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/global/actor/avatar')]
	public function globalActorAvatar(string $id): Response {
		try {
			$actor = $this->knownActor($id);
			if ($actor->hasIcon()) {
				$avatar = $actor->getIcon();
				$mime = '';
				$document = $this->documentService->getFromCache($avatar->getId(), $mime);

				$response
					= new FileDisplayResponse($document, Http::STATUS_OK, ['Content-Type' => $mime]);
				RangedFileResponse::guardStoredFile($response, $mime);
				$response->cacheFor(86400);

				return $response;
			} else {
				throw new InvalidResourceException('no avatar for this Actor');
			}
		} catch (Exception $e) {
			return $this->fail($e, [], Http::STATUS_NOT_FOUND, false);
		}
	}

	/**
	 * Accounts known here by a name or handle, as Mastodon Account entities:
	 * the same shape every other route serves an account in, with a remote
	 * bio sanitised and no copy of the actor document it was cached from.
	 *
	 * @throws Exception
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/global/accounts/search')]
	public function globalAccountsSearch(string $search): DataResponse {
		$this->initViewer();

		if (substr($search, 0, 1) === '@') {
			$search = substr($search, 1);
		}

		if ($search === '') {
			return $this->success(['accounts' => [], 'exact' => []]);
		}

		/* Look for an exactly matching account */
		$match = null;
		try {
			$match = $this->cacheActorService->getFromAccount($search, false);
			$match->setExportFormat(ACore::FORMAT_LOCAL);
		} catch (Exception $e) {
		}

		try {
			$accounts = $this->cacheActorService->searchCachedAccounts($search);
			if ($this->blueskySearch !== null) {
				$accounts = SearchService::withBluesky($accounts, $this->blueskySearch->typeahead($search));
			}
			foreach ($accounts as $account) {
				$account->setExportFormat(ACore::FORMAT_LOCAL);
			}

			return $this->success(['accounts' => $accounts, 'exact' => $match]);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * @throws Exception
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/global/tags/search')]
	public function globalTagsSearch(string $search): DataResponse {
		$this->initViewer();

		if (substr($search, 0, 1) === '#') {
			$search = substr($search, 1);
		}

		if ($search === '') {
			return $this->success(['tags' => [], 'exact' => []]);
		}

		$match = null;
		try {
			$match = $this->hashtagService->getHashtag($search);
		} catch (Exception $e) {
		}

		try {
			$tags = $this->hashtagService->searchHashtags($search, false);

			return $this->success(['tags' => $tags, 'exact' => $match]);
		} catch (Exception $e) {
			return $this->failFor($e);
		}
	}

	/**
	 * Whether this request may have other servers asked on its behalf: one a
	 * signed-in reader's web app sent with its request token, or one with a
	 * client's bearer token that is still valid. A link or a page on another
	 * site can forge neither, and an anonymous script holds neither.
	 */
	private function mayAskOtherServers(): bool {
		if ($this->mayAskOtherServers !== null) {
			return $this->mayAskOtherServers;
		}

		if ($this->userId !== null && $this->request->passesCSRFCheck()) {
			return $this->mayAskOtherServers = true;
		}

		$header = trim($this->request->getHeader('Authorization'));
		if ($this->clientService === null || stripos($header, 'bearer ') !== 0) {
			return $this->mayAskOtherServers = false;
		}

		try {
			$this->clientService->getFromToken(trim(substr($header, 7)));

			return $this->mayAskOtherServers = true;
		} catch (Exception $e) {
			return $this->mayAskOtherServers = false;
		}
	}

	/**
	 * The reader's own account, for a route that acts as them.
	 *
	 * @throws AccountDoesNotExistException nobody is signed in
	 * @throws AccountNotSetUpException they have not set up their account here yet
	 */
	private function ownAccount(): Person {
		if ($this->userId === null) {
			throw new AccountDoesNotExistException('User not logged in');
		}

		return $this->ownAccountOf($this->userId);
	}

	private function initViewer(bool $exception = false) {
		if (!isset($this->userId)) {
			if ($exception) {
				throw new AccountDoesNotExistException('userId not defined');
			}

			return;
		}

		try {
			$this->viewer = $this->accountService->getActorFromUserId($this->userId);

			$this->streamService->setViewer($this->viewer);
			$this->followService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);
		} catch (Exception $e) {
			if ($exception) {
				throw new AccountDoesNotExistException(
					'unable to initViewer - ' . get_class($e) . ' - ' . $e->getMessage()
				);
			}
		}
	}

	/**
	 * Gives a local account its Bluesky handle and DID to show, when it has
	 * them; the entity carries `bluesky: null` otherwise.
	 */
	private function attachBluesky(Person $actor): Person {
		if (!$actor->isLocal()) {
			return $actor;
		}
		try {
			$identity = $this->atprotoIdentities->getByActorId($actor->getId());
			if ($identity->state !== Identity::STATE_TOMBSTONED) {
				$actor->setDetailArray(Details::BLUESKY, [
					'handle' => $identity->handle, 'did' => $identity->did,
					'url' => 'https://bsky.app/profile/' . $identity->handle,
					'native' => false, 'active' => $identity->isActive(),
					'links' => $this->profileLinks?->forIdentity($identity) ?? [],
				]);
			}
		} catch (AtprotoIdentityNotFoundException) {
		} catch (Throwable $e) {
			// the Bluesky side must never take the profile down with it
			$this->logger->error('could not read the Bluesky identity of an account', ['actor' => $actor->getId(), 'exception' => $e]);
		}

		return $actor;
	}
}
