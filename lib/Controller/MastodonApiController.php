<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\AccountMovedException;
use OCA\Social\Exceptions\AccountNotSetUpException;
use OCA\Social\Exceptions\AccountSuspendedException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\BlockedByException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\FederationDeliveryException;
use OCA\Social\Exceptions\FollowLimitException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\HashtagDoesNotExistException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidHandleException;
use OCA\Social\Exceptions\InvalidResourceEntryException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RefusalException;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\TooManyRequestsException;
use OCA\Social\Exceptions\TranslationUnavailableException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Exceptions\UnknownProbeException;
use OCA\Social\Exceptions\UploadFailedException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What every controller of the Mastodon client API shares: who is asking, what
 * their token allows, how a failure is answered, and how a page of results is
 * linked to the next one.
 *
 * Token scopes are looked up by route method name in one table, so method
 * names must be unique across the controllers that extend this class.
 */
abstract class MastodonApiController extends Controller {
	use OwnAccount;
	use ListParameter;

	/**
	 * The entity tag of the poll being answered, held between `notModified()`
	 * and the answer so the answer carries it.
	 */
	protected string $pollTag = '';

	/** the 401 an app token gets from a route that acts for an account */
	public const APP_TOKEN_NAMES_NOBODY = 'this token belongs to an app and names no account: sign an account in';

	protected string $bearer = '';
	protected ?SocialClient $client = null;
	protected ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		protected IURLGenerator $urlGenerator,
		protected IUserSession $userSession,
		protected LoggerInterface $logger,
		protected ClientService $clientService,
		protected AccountService $accountService,
		protected CacheActorService $cacheActorService,
		protected StreamService $streamService,
		protected FollowService $followService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			[$authType, $authToken] = explode(' ', $authHeader);
			if (strtolower($authType) === 'bearer') {
				$this->bearer = $authToken;
			}
		}
	}

	/**
	 * A boolean as a Mastodon client sends it in a form or JSON body:
	 * `true`/`false`, `1`/`0`, or those as strings.
	 */
	protected function formBool(mixed $value): bool {
		return in_array($value, [true, 1, '1', 'true'], true);
	}

	/**
	 * @param bool $exception whether a request without a usable account is
	 *                        refused rather than answered as nobody
	 * @param bool $publicRoute a route anybody may read, that asked to refuse
	 *                          only a token that is bad: an app token is a
	 *                          good one that names nobody, and reads it as
	 *                          anybody does
	 *
	 * @return bool
	 * @throws ClientNotFoundException
	 */
	protected function initViewer(bool $exception = false, bool $publicRoute = false): bool {
		try {
			$userId = $this->currentSession();

			$this->logger->debug('[' . static::class . '] initViewer: ' . $userId);

			// Read, never create: the handle is chosen on the setup screen
			// (LocalController::accountCreate(), `occ social:account:create`). A token
			// is only issued to an account, so a session caller without one has not set
			// up yet.
			$account = ($this->bearer === '')
				? $this->ownAccountOf($userId)
				: $this->accountService->getActorFromUserId($userId);
			if ($this->clientService->isSuspendedAccount($account)) {
				throw new AccountSuspendedException();
			}
			$this->logger->debug('[' . static::class . '] Actor retrieved/created', [
				'userId' => $userId,
				'username' => $account->getPreferredUsername()
			]);

			// Try to get from cache, if it fails, cache it first
			try {
				$this->viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			} catch (Exception $e) {
				$this->logger->warning('[' . static::class . '] Actor not in cache, caching now', [
					'username' => $account->getPreferredUsername(),
					'exception' => $e->getMessage()
				]);
				// Cache the actor and retry
				$this->accountService->cacheLocalActorByUsername($account->getPreferredUsername());
				$this->viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			}

			$this->viewer->setExportFormat(ACore::FORMAT_LOCAL);
			// The cached local actor carries no user id and a bearer request has no
			// session, so per-user settings (the notification policy) need it set here.
			if ($this->viewer->getUserId() === '') {
				$this->viewer->setUserId($userId);
			}

			$this->streamService->setViewer($this->viewer);
			$this->followService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);

			$this->logger->debug('[' . static::class . '] Viewer initialized successfully', [
				'viewerId' => $this->viewer->getId()
			]);

			return true;
		} catch (InsufficientScopeException $e) {
			// the token is fine, its grant is not — tell the client which scope it lacks
			if ($exception) {
				throw $e;
			}
		} catch (AccountSuspendedException $e) {
			// A public route answers them as it answers anybody.
			if ($exception && !$publicRoute) {
				throw $e;
			}
		} catch (AccountNotSetUpException $e) {
			// Not set up yet: a public route answers them as it answers anybody.
			if ($exception && !$publicRoute) {
				throw $e;
			}
		} catch (Exception $e) {
			// A missing, stale or made-up token is ordinary scanner noise answered with
			// a 401, not logged as an error; any other failure here is.
			$credentials = ($e instanceof ClientNotFoundException
				|| $e instanceof AccountDoesNotExistException
				|| $e instanceof ActorDoesNotExistException);
			if ($credentials) {
				$this->logger->debug('[' . static::class . '] initViewer: no usable credentials', [
					'exception' => $e->getMessage()
				]);
			} else {
				$this->logger->warning('[' . static::class . '] initViewer failed', ['exception' => $e]);
			}

			$appToken = ($e->getMessage() === self::APP_TOKEN_NAMES_NOBODY);
			if ($exception && !($appToken && $publicRoute)) {
				throw new ClientNotFoundException($appToken ? $e->getMessage() : 'the access_token was revoked');
			}
		}

		return false;
	}

	/**
	 * @return string
	 * @throws AccountDoesNotExistException
	 * @throws ClientNotFoundException
	 */
	/**
	 * A bearer token wins over the session cookie, so an OAuth client stays
	 * inside its granted scopes. The cookie is only accepted with a valid CSRF
	 * token: these routes are #[NoCSRFRequired] for external clients, and a
	 * cross-site form POST must not act as the logged-in user.
	 */
	protected function currentSession(): string {
		if ($this->bearer !== '') {
			$this->client = $this->clientService->getFromTokenOfRequest($this->bearer);
			$this->checkTokenScope();

			if ($this->client->getAuthUserId() === '') {
				// An app token (`client_credentials`): good for public reads only.
				throw new AccountDoesNotExistException(self::APP_TOKEN_NAMES_NOBODY);
			}

			return $this->client->getAuthUserId();
		}

		$user = $this->userSession->getUser();
		if ($user !== null && $this->request->passesCSRFCheck()) {
			return $user->getUID();
		}

		throw new AccountDoesNotExistException('userId not defined');
	}

	/**
	 * For a route that takes the Nextcloud session (docs/API.md, "Which
	 * credentials a route takes"): a bearer token is refused whatever it was
	 * granted, which leaves the session `currentSession()` accepts — the web
	 * app's with its CSRF token, or an app password with `OCS-APIRequest`.
	 *
	 * @throws InsufficientScopeException
	 */
	protected function requireWebSession(): void {
		if ($this->bearer !== '') {
			throw new InsufficientScopeException('this route takes the Nextcloud session: the web app signed in to Nextcloud, or a Nextcloud app password sent with OCS-APIRequest: true');
		}
	}

	/**
	 * The scope every route of this controller asks a bearer token for, as the
	 * Mastodon API documents it.
	 *
	 * Reads are enumerated and a missing route resolves by its HTTP verb, only
	 * `GET` to a read scope, so a new state-changing route (such as generating
	 * an annual report) fails safe. An empty list means the route needs no
	 * scope beyond a valid token.
	 */
	private const ROUTE_SCOPES = [
		// -- reads ------------------------------------------------------------
		'appsCredentials' => [],
		'verifyCredentials' => ['read:accounts'],
		'accountLookup' => ['read:accounts'],
		'accountsSearch' => ['read:accounts'],
		'preferences' => ['read:accounts'],
		'annualReports' => ['read:accounts'],
		'annualReport' => ['read:accounts'],
		'annualReportState' => ['read:accounts'],
		'followRequests' => ['read:follows'],
		'relationships' => ['read:follows'],
		'familiarFollowers' => ['read:follows'],
		'accountFollowing' => ['read:follows'],
		'accountFollowers' => ['read:follows'],
		'blocks' => ['read:blocks'],
		'mutes' => ['read:mutes'],
		'favourites' => ['read:favourites'],
		'bookmarks' => ['read:bookmarks'],
		'notifications' => ['read:notifications'],
		'notificationsUnreadCount' => ['read:notifications'],
		'pushSubscriptionGet' => ['push'],
		'search' => ['read:search'],
		'searchV2' => ['read:search'],
		'savedSearches' => ['read:search'],
		'timelines' => ['read:statuses'],
		'tag' => ['read:statuses'],
		'accountStatuses' => ['read:statuses'],
		'statusGet' => ['read:statuses'],
		'statusCounts' => ['read:statuses'],
		'statusCalendar' => ['read:statuses'],
		'statusCard' => ['read:statuses'],
		'statusContext' => ['read:statuses'],
		'statusSource' => ['read:statuses'],
		'statusDelivery' => ['read:statuses'],
		'statusReactions' => ['read:statuses'],
		'statusFavouritedBy' => ['read:statuses'],
		'statusRebloggedBy' => ['read:statuses'],
		'statusQuotes' => ['read:statuses'],
		'pollGet' => ['read:statuses'],
		'markersGet' => ['read:statuses'],
		'scheduledStatuses' => ['read:statuses'],
		'scheduledStatusGet' => ['read:statuses'],
		'videosContinue' => ['read:statuses'],
		'mediaOpen' => ['read:statuses'],
		'mediaStream' => ['read:statuses'],
		'mediaPlaylist' => ['read:statuses'],
		'mediaPlaylistFile' => ['read:statuses'],
		'mediaLadder' => ['read:statuses'],
		'mediaLadderRung' => ['read:statuses'],
		'mediaLadderFile' => ['read:statuses'],
		// Mastodon puts the pending-upload read behind the media write scope.
		'mediaGet' => ['write:media'],
		// The instance's public documents and the emoji and GIF images they use.
		'instance' => ['read'],
		'instanceV2' => ['read'],
		'instanceRules' => ['read'],
		'instanceDomainBlocks' => ['read'],
		'instanceExtendedDescription' => ['read'],
		'instancePrivacyPolicy' => ['read'],
		'instanceTermsOfService' => ['read'],
		'instanceTranslationLanguages' => ['read'],
		'instancePeers' => ['read'],
		'instanceActivity' => ['read'],
		'customEmojis' => ['read'],
		'emojiOpen' => ['read'],
		'gifs' => ['read'],
		'gifOpen' => ['read'],
		'trendTags' => ['read'],
		'oembed' => ['read'],
		// A POST, but Mastodon documents `read:statuses`: it answers a post the
		// caller may already read, translated.
		'statusTranslate' => ['read:statuses'],

		// -- writes -----------------------------------------------------------
		'updateCredentials' => ['write:accounts'],
		'pushSubscriptionCreate' => ['push'],
		'pushSubscriptionUpdate' => ['push'],
		'pushSubscriptionDelete' => ['push'],
		'profileAvatarDelete' => ['write:accounts'],
		'profileHeaderDelete' => ['write:accounts'],
		'accountNew' => ['write:accounts'],
		'preferencesUpdate' => ['write:accounts'],
		'annualReportRead' => ['write:accounts'],
		'annualReportGenerate' => ['write:accounts'],
		'statusNew' => ['write:statuses'],
		'statusUpdate' => ['write:statuses'],
		'statusDelete' => ['write:statuses'],
		'statusInteractionPolicy' => ['write:statuses'],
		'statusQuoteRevoke' => ['write:statuses'],
		'statusWatched' => ['write:statuses'],
		'statusUnwatched' => ['write:statuses'],
		'pollVote' => ['write:statuses'],
		'markersSet' => ['write:statuses'],
		'scheduledStatusUpdate' => ['write:statuses'],
		'scheduledStatusDelete' => ['write:statuses'],
		'statusReact' => ['write:favourites'],
		'statusUnreact' => ['write:favourites'],
		'mediaNew' => ['write:media'],
		'mediaNewV2' => ['write:media'],
		'mediaUpdate' => ['write:media'],
		'mediaFromGif' => ['write:media'],
		'reportNew' => ['write:reports'],
		// `follow` is Mastodon's broad scope over the relationship routes.
		'accountFollow' => ['write:follows', 'follow'],
		'accountUnfollow' => ['write:follows', 'follow'],
		'followRequestAuthorize' => ['write:follows', 'follow'],
		'followRequestReject' => ['write:follows', 'follow'],
		'accountBlock' => ['write:blocks', 'follow'],
		'accountUnblock' => ['write:blocks', 'follow'],
		'accountMute' => ['write:mutes', 'follow'],
		'accountUnmute' => ['write:mutes', 'follow'],
	];

	/**
	 * The scope of each `act` of the catch-all status route; favouriting,
	 * pinning and muting are different permissions.
	 */
	private const STATUS_ACTION_SCOPES = [
		'favourite' => ['write:favourites'],
		'unfavourite' => ['write:favourites'],
		'reblog' => ['write:statuses'],
		'unreblog' => ['write:statuses'],
		'bookmark' => ['write:bookmarks'],
		'unbookmark' => ['write:bookmarks'],
		'pin' => ['write:accounts'],
		'unpin' => ['write:accounts'],
		'mute' => ['write:mutes'],
		'unmute' => ['write:mutes'],
	];

	/**
	 * Whether the token this request carries may make it.
	 *
	 * @throws InsufficientScopeException
	 */
	private function checkTokenScope(): void {
		$route = $this->routeParam('_route');
		$name = substr($route, strrpos($route, '.') + 1);

		$accepted = $this->scopesForRoute(
			$name,
			strtoupper($this->request->getMethod()),
			$this->statusAct((string)$this->request->getParam('act', ''))
		);

		foreach ($accepted as $scope) {
			// A granular scope is satisfied by itself or the broad scope containing it.
			$broad = strstr($scope, ':', true);
			$broad = ($broad === false) ? $scope : $broad;

			foreach ($this->client->getAuthScopes() as $granted) {
				if ($granted === $scope || $granted === $broad) {
					return;
				}
			}
		}

		if ($accepted !== []) {
			throw new InsufficientScopeException(
				'token scope does not allow this request (needs ' . implode(' or ', $accepted) . ')'
			);
		}
	}

	/**
	 * A parameter as the router took it from the path. `getParam()` merges the
	 * body over route values, so a body could name a `_route` or `act` the
	 * request was not dispatched to.
	 */
	protected function routeParam(string $key): string {
		/** @psalm-suppress NoInterfaceProperties IRequest documents urlParams as a magic property */
		return (string)(($this->request->urlParams ?? [])[$key] ?? '');
	}

	/**
	 * The `act` of the catch-all status route: the path's, which the scope is
	 * checked for, else what the dispatcher passed.
	 */
	protected function statusAct(string $passed): string {
		$act = $this->routeParam('act');

		return ($act === '') ? $passed : $act;
	}

	/**
	 * The scopes that satisfy one route, any of which is enough.
	 *
	 * @return string[]
	 */
	private function scopesForRoute(string $name, string $verb, string $act = ''): array {
		if ($name === 'statusAction') {
			// An unknown act is refused by the route; until then it counts as a write.
			return self::STATUS_ACTION_SCOPES[$act] ?? ['write'];
		}

		if (array_key_exists($name, self::ROUTE_SCOPES)) {
			return self::ROUTE_SCOPES[$name];
		}

		return $verb === 'GET' ? ['read'] : ['write'];
	}

	/**
	 * The HTTP status each failure maps to, tested in order; a client reads 401
	 * as "token gone", so only real credential failures get it.
	 * `InsufficientScopeException` is handled before this list because it
	 * extends `ClientException`.
	 */
	private const ERROR_STATUS = [
		// gone, or never existed
		[StreamNotFoundException::class, Http::STATUS_NOT_FOUND],
		[CacheActorDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ActorDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ItemNotFoundException::class, Http::STATUS_NOT_FOUND],
		[CacheDocumentDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[HashtagDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ReportNotFoundException::class, Http::STATUS_NOT_FOUND],
		[FollowNotFoundException::class, Http::STATUS_NOT_FOUND],
		[InstanceDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[NotFoundException::class, Http::STATUS_NOT_FOUND],
		// Blocked by the target, as Mastodon answers. Ahead of
		// InvalidActionException, which it extends.
		[BlockedByException::class, Http::STATUS_FORBIDDEN],
		// the request was understood and refused: retrying it unchanged cannot help
		[InvalidActionException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[UnknownProbeException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[InvalidResourceException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[InvalidResourceEntryException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[InvalidHandleException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[ItemUnknownException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[CacheContentMimeTypeException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		// The account moved away. Ahead of ClientException (422), which it extends.
		[AccountMovedException::class, Http::STATUS_FORBIDDEN],
		[ClientException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		// the credentials, not the request
		[ClientNotFoundException::class, Http::STATUS_UNAUTHORIZED],
		[AccountDoesNotExistException::class, Http::STATUS_UNAUTHORIZED],
		// allowed to ask, not allowed to have
		[UnauthorizedFediverseException::class, Http::STATUS_FORBIDDEN],
		[AccountSuspendedException::class, Http::STATUS_FORBIDDEN],
		[TooManyRequestsException::class, Http::STATUS_TOO_MANY_REQUESTS],
		[FollowLimitException::class, Http::STATUS_TOO_MANY_REQUESTS],
		// No translation provider right now: Mastodon answers 503, read as "later".
		[TranslationUnavailableException::class, Http::STATUS_SERVICE_UNAVAILABLE],
		// The edit is committed locally but its Update could not be queued; the
		// client may retry the delivery later.
		[FederationDeliveryException::class, Http::STATUS_SERVICE_UNAVAILABLE],
		// an upload this server failed to receive; the file itself was fine
		[UploadFailedException::class, Http::STATUS_INTERNAL_SERVER_ERROR],
		// somebody else's server let us down
		[RequestContentException::class, Http::STATUS_NOT_FOUND],
		[RequestNetworkException::class, Http::STATUS_BAD_GATEWAY],
		[RequestServerException::class, Http::STATUS_BAD_GATEWAY],
		[RequestResultNotJsonException::class, Http::STATUS_BAD_GATEWAY],
		[RequestResultSizeException::class, Http::STATUS_BAD_GATEWAY],
	];

	/**
	 * A failure as a Mastodon client can act on it: `{"error": "..."}` with a
	 * status that says what to do about it. An unrecognised failure answers 500,
	 * logged with its stack trace; its message is not sent, since every route
	 * here is a `#[PublicPage]` and it may name internals.
	 */
	protected function error(Throwable $e): DataResponse {
		if ($e instanceof RefusalException) {
			return new DataResponse($e->toArray(), Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if ($e instanceof InsufficientScopeException) {
			// No WWW-Authenticate: PHP turns any response carrying it into a 401, which
			// a client reads as its token being dead.
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		if ($e instanceof AccountNotSetUpException) {
			// A 403, as Mastodon answers a sign-in not usable yet.
			return self::notSetUp($e);
		}

		foreach (self::ERROR_STATUS as [$class, $status]) {
			if ($e instanceof $class) {
				$headers = ($status === Http::STATUS_UNAUTHORIZED)
					? ['WWW-Authenticate' => 'Bearer error="invalid_token"'] : [];

				return new DataResponse(
					['error' => $this->errorMessage($e, $status)], $status, $headers
				);
			}
		}

		$this->logger->error('[' . static::class . '] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => $this->routeParam('_route'),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}

	/**
	 * Whether a file arrived in this `$_FILES`-shaped entry: true for a file,
	 * false for none sent, and a refusal saying why for one PHP did not
	 * deliver. The reason is logged at warning level, since only an admin can
	 * raise the limit or fix the server behind it.
	 *
	 * @param string $what what the file is, as the refusal names it
	 * @param int $limit the size the refusal of a too-large file names, in bytes
	 *
	 * @throws InvalidActionException the file was too large, or arrived only in part (422)
	 * @throws UploadFailedException the server could not receive it (500)
	 */
	protected function uploadArrived(array $upload, string $what, int $limit): bool {
		$error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

		switch ($error) {
			case UPLOAD_ERR_OK:
				return true;
			case UPLOAD_ERR_NO_FILE:
				return false;
			case UPLOAD_ERR_INI_SIZE:
				throw $this->uploadTooLarge($what, $limit, 'over upload_max_filesize');
			case UPLOAD_ERR_FORM_SIZE:
				throw $this->uploadTooLarge($what, $limit, 'over the MAX_FILE_SIZE the client sent');
			case UPLOAD_ERR_PARTIAL:
				$this->logUploadRefusal($what, 'the upload was interrupted', $error);
				throw new InvalidActionException('the ' . $what . ' upload was interrupted, try again');
		}

		$reason = match ($error) {
			UPLOAD_ERR_NO_TMP_DIR => 'PHP has no temporary directory for uploads',
			UPLOAD_ERR_CANT_WRITE => 'PHP could not write the upload to disk',
			UPLOAD_ERR_EXTENSION => 'a PHP extension stopped the upload',
			default => 'PHP upload error ' . $error,
		};
		$this->logUploadRefusal($what, $reason, $error);

		throw new UploadFailedException(
			'the ' . $what . ' could not be received because of a problem on this server (' . $reason . ')'
		);
	}

	/**
	 * The refusal of a file over this server's size limit, logged with the
	 * PHP settings an admin would raise.
	 *
	 * @param int $limit the size the refusal names, in bytes
	 * @param string $reason which limit it went over, for the log
	 */
	protected function uploadTooLarge(string $what, int $limit, string $reason): InvalidActionException {
		$this->logUploadRefusal($what, 'larger than this server accepts, ' . $reason, null);

		return new InvalidActionException(
			'the ' . $what . ' is larger than this server accepts (' . Util::humanFileSize($limit) . ')'
		);
	}

	private function logUploadRefusal(string $what, string $reason, ?int $error): void {
		$this->logger->warning('[' . static::class . '] ' . $what . ' upload refused: ' . $reason, [
			'error' => $error,
			'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
			'post_max_size' => (string)ini_get('post_max_size'),
		]);
	}

	/**
	 * What to put in `error`; several failures carry no message, and an empty
	 * one tells a client nothing.
	 */
	private function errorMessage(Throwable $e, int $status): string {
		$message = trim($e->getMessage());
		if ($message !== '') {
			return $message;
		}

		return match ($status) {
			Http::STATUS_UNAUTHORIZED => 'the access_token is invalid',
			Http::STATUS_NOT_FOUND => 'not found',
			Http::STATUS_UNPROCESSABLE_ENTITY => 'the request could not be processed',
			default => 'request failed',
		};
	}

	/**
	 * The parameters of a request that carries its body itself. A body declared
	 * JSON that does not decode to an array is refused.
	 *
	 * @throws InvalidActionException
	 */
	protected function convertInput(string $input): array {
		$contentType = $this->request->getHeader('Content-Type');

		$pos = strpos($contentType, ';');
		if ($pos > 0) {
			$contentType = substr($contentType, 0, $pos);
		}

		switch ($contentType) {
			case 'application/json':
				$result = json_decode($input, true);
				if (!is_array($result)) {
					throw new InvalidActionException('the request body is not valid JSON');
				}

				return $result;
			case 'application/x-www-form-urlencoded':
				return $this->request->getParams();
			default: // in case of no header ...
				$result = json_decode($input, true);
				if (is_array($result)) {
					return $result;
				}

				return $this->request->getParams();
		}
	}

	/**
	 * The filter context a timeline is read in. Favourites, bookmarks and direct
	 * are not Mastodon filter contexts, but their statuses still carry an empty
	 * `filtered`, since its absence is read as an answer.
	 */
	protected function filterContext(string $timeline): string {
		return match (strtolower($timeline)) {
			ProbeOptions::HOME => Filter::CONTEXT_HOME,
			ProbeOptions::PUBLIC => Filter::CONTEXT_PUBLIC,
			default => '',
		};
	}

	/**
	 * Answers `304` when the caller already has this version of a poll.
	 *
	 * Clients poll the home timeline and unread count every thirty seconds, and
	 * the tag (the newest id the viewer can see) costs one index-only probe
	 * instead of the page. `private` because the answer is one account's;
	 * `no-cache` so the browser revalidates.
	 *
	 * @return JSONResponse|null the 304 to return, or null to carry on
	 */
	protected function notModified(string $tag): ?JSONResponse {
		if ($tag === '') {
			return null;
		}

		$etag = '"' . $tag . '"';
		if (Revalidation::matches($this->request, $etag)) {
			return Revalidation::notModified($etag);
		}

		$this->pollTag = $etag;

		return null;
	}

	/**
	 * Puts the tag on the answer that earned it.
	 *
	 * Returns a `JSONResponse` because Nextcloud's json responder rebuilds a
	 * `DataResponse` with the default `Cache-Control: no-store`, which
	 * overwrites ours and stops the browser from ever sending `If-None-Match`.
	 * Only the headers the caller set are copied; the framework's are recomputed.
	 */
	protected function tagged(DataResponse $response): JSONResponse {
		$json = Revalidation::asJson($response);

		if ($this->pollTag !== '') {
			$json->addHeader('ETag', $this->pollTag);
			$json->addHeader('Cache-Control', Revalidation::CACHE_CONTROL);
			$this->pollTag = '';
		}

		return $json;
	}

	/**
	 * A page of entities, with the `Link` header Mastodon pages with, which
	 * masto.js (Elk, Phanpy) needs to page at all: `next` while a further page
	 * may exist, `prev` whenever the page is not empty.
	 *
	 * @param array|null $page the rows the query returned, where `$items` is a
	 *                         filtered subset of them
	 */
	protected function paged(array $items, int $limit, ?array $page = null, ?int $rows = null): DataResponse {
		$response = new DataResponse($items, Http::STATUS_OK);

		// What the query returned says whether there is more; $items may be filtered.
		$page ??= $items;
		// $page may be shorter than the rows read, where a duplicate boost was dropped.
		$rows ??= count($page);

		$ids = $this->pageIds($page);
		if ($ids === []) {
			return $response;
		}

		$links = [];
		if ($rows >= $limit) {
			// the next page is older than everything here
			$links[] = '<' . $this->pageUrl(['max_id' => (string)min($ids)]) . '>; rel="next"';
		}
		$links[] = '<' . $this->pageUrl(['min_id' => (string)max($ids)]) . '>; rel="prev"';

		$response->addHeader('Link', implode(', ', $links));

		return $response;
	}

	/**
	 * The paging ids of a page of entities. Streams and actors both carry the
	 * numeric id the API pages by; an already-serialised entity carries it as
	 * its string `id`.
	 *
	 * @return string[]
	 */
	private function pageIds(array $items): array {
		$ids = [];
		foreach ($items as $item) {
			$nid = '0';
			if (is_object($item) && method_exists($item, 'getNid')) {
				$nid = (string)$item->getNid();
			} elseif (is_array($item)) {
				$nid = (string)($item['id'] ?? '0');
			}

			if (\OCA\Social\Tools\Nid::compare($nid, '0') > 0) {
				$ids[] = $nid;
			}
		}

		return $ids;
	}

	/**
	 * This request's own URL with the cursor replaced, so the client's other
	 * filters (`limit`, `types`, `only_media`, …) survive into the next page.
	 */
	protected function pageUrl(array $cursor): string {
		$uri = $this->request->getRequestUri();
		$path = $uri;
		$query = [];

		$pos = strpos($uri, '?');
		if ($pos !== false) {
			$path = substr($uri, 0, $pos);
			parse_str(substr($uri, $pos + 1), $query);
		}

		unset($query['max_id'], $query['min_id'], $query['since_id'], $query['_route']);

		return $this->urlGenerator->getAbsoluteURL($path) . '?'
			. http_build_query(array_merge($query, $cursor));
	}
}
