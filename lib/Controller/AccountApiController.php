<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyFeeds;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\UploadFailedException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\AiContentService;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\CountsService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\FollowList\FollowListService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\HiddenCollectionsService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\MultipartBodyService;
use OCA\Social\Service\NotificationDeliveryService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\SensitiveMediaService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Nid;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Accounts: the caller's own (credentials, profile, preferences, sign-up) and
 * everybody else's — looking one up, their posts and follows, and the
 * relationship between the two: following, follow requests, blocks and mutes.
 */
class AccountApiController extends MastodonApiController {
	/** Accounts one `familiar_followers` call answers for; Mastodon's cap too. */
	private const FAMILIAR_FOLLOWERS_MAX = 20;

	public function __construct(
		IRequest $request,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		LoggerInterface $logger,
		ClientService $clientService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		StreamService $streamService,
		FollowService $followService,
		private RelationshipService $relationshipService,
		private PinService $pinService,
		private SearchService $searchService,
		private FilterService $filterService,
		private BannerService $bannerService,
		private AvatarService $avatarService,
		private AccountRelationService $accountRelationService,
		private SensitiveMediaService $sensitiveMediaService,
		private IAppManager $appManager,
		private NotificationService $notificationService,
		private NotificationDeliveryService $notificationDeliveryService,
		private MultipartBodyService $multipartBodyService,
		private AdminApiService $adminApiService,
		private AiContentService $aiContentService,
		private CountsService $countsService,
		private InstanceService $instanceService,
		private RemoteFetchQueue $remoteFetchQueue,
		private IdentityService $atprotoIdentities,
		private FollowListService $followLists,
		private BlueskyFeeds $blueskyFeeds,
		private HiddenCollectionsService $hiddenCollections,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/verify_credentials')]
	public function verifyCredentials() {
		try {
			$this->initViewer(true);

			return new DataResponse($this->accountEntity($this->viewer), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's profile update: `display_name`, `note` (the bio), `avatar` and
	 * `header` (multipart), `locked` (manually approve followers),
	 * `discoverable`, `indexable` and `bot` (the actor flags),
	 * `hide_collections` (`HiddenCollectionsService`), `source[privacy]` (the
	 * default audience) and `fields_attributes`. Returns the updated account.
	 *
	 * Only what was sent is written. The name and avatar belong to the
	 * Nextcloud account; a backend that owns them (LDAP, SAML) makes this a
	 * 422. Every refusal happens before anything is written, and a later part
	 * that fails is logged, so the request changes nothing or answers 200
	 * with what it stored.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/accounts/update_credentials')]
	public function updateCredentials(): DataResponse {
		try {
			$this->initViewer(true);
			$userId = $this->currentSession();

			// clients send this route as multipart whenever a picture is in it,
			// and PHP parses a multipart body by itself for a POST only
			$multipart = $this->multipartBodyService->read($this->request);
			$input = ($multipart === null)
				? $this->convertInput(file_get_contents('php://input'))
				: $multipart['fields'];
			$files = ($multipart === null) ? $_FILES : $multipart['files'];

			// everything that can be refused is refused here, before anything
			// is written
			$header = $files['header'] ?? [];
			$avatar = $files['avatar'] ?? [];
			$headerSent = $this->wasUploaded($header, 'header');
			$avatarSent = $this->wasUploaded($avatar, 'avatar');
			if ($avatarSent) {
				$this->avatarService->checkUpload($userId, $avatar);
			}

			// an absent display name is a client that did not mention it
			$displayNameSent = array_key_exists('display_name', $input);
			if ($displayNameSent) {
				$this->accountService->assertDisplayNameWritable($userId);
			}

			// `source[privacy]` is the audience this account posts with when a
			// client does not name one, and `statusNew()` reads it back
			$privacy = $input['source']['privacy'] ?? null;
			$privacy = (is_string($privacy) && $privacy !== '') ? $privacy : null;
			if ($privacy !== null) {
				$this->accountService->assertDefaultPrivacy($privacy);
			}

			// only the flags that were sent: a client updating the display
			// name must not reset the ones it did not mention
			$flags = [];
			foreach (['discoverable', 'indexable', 'bot'] as $flag) {
				if (array_key_exists($flag, $input)) {
					$flags[$flag] = $this->formBool($input[$flag]);
				}
			}

			// The parts in the order they are written. The banner goes first,
			// since whether it is an image this app stores is only known once
			// stored. The avatar is the Nextcloud account's picture.
			$parts = [];
			if ($headerSent) {
				$parts['header'] = fn () => $this->bannerService->setFromTempFile($userId, $header['tmp_name']);
			}
			if ($avatarSent) {
				$parts['avatar'] = fn () => $this->avatarService->setFromTempFile($userId, $avatar);
			}
			if ($displayNameSent) {
				$parts['display_name'] = fn () => $this->accountService->setDisplayName(
					$userId, (string)$input['display_name']
				);
			}
			// an absent `note` is a client that did not mention the bio, not a
			// client asking for an empty one
			if (array_key_exists('note', $input)) {
				$parts['note'] = fn () => $this->accountService->setSummary($userId, (string)$input['note']);
			}
			if ($privacy !== null) {
				$parts['source[privacy]'] = fn () => $this->accountService->setDefaultPrivacy($userId, $privacy);
			}
			if (array_key_exists('locked', $input)) {
				$parts['locked'] = fn () => $this->accountService->setLocked($userId, $this->formBool($input['locked']));
			}
			if ($flags !== []) {
				$parts['flags'] = fn () => $this->accountService->setActorFlags($userId, $flags);
			}
			if (array_key_exists('fields_attributes', $input) && is_array($input['fields_attributes'])) {
				// clients send either a list or an object keyed by index
				$parts['fields_attributes'] = fn () => $this->accountService->setFields(
					$userId, array_values($input['fields_attributes'])
				);
			}

			// a setting of this server's, not federated
			if (array_key_exists('hide_collections', $input)) {
				$this->hiddenCollections->setHides($userId, $this->formBool($input['hide_collections']));
			}

			if ($this->accountService->changingProfile($userId, fn (): bool => $this->applyProfileParts($parts))) {
				// refresh the viewer so the returned entity carries the change
				$this->viewer = $this->refreshedViewer();
			}

			return new DataResponse($this->accountEntity($this->viewer), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Writes the parts of a profile update in turn. A failure of the first
	 * part fails the request with nothing written; a later failure is logged
	 * and the rest still written, so the 200 shows what was stored.
	 *
	 * @param array<string, callable(): mixed> $parts by the field they write
	 * @return bool whether anything was written
	 * @throws Throwable the first part failed
	 */
	private function applyProfileParts(array $parts): bool {
		$written = false;
		foreach ($parts as $part => $apply) {
			try {
				$apply();
				$written = true;
			} catch (Throwable $e) {
				if (!$written) {
					throw $e;
				}

				$this->logger->warning('[' . static::class . '] update_credentials could not store ' . $part . ', the rest of the profile was stored', [
					'exception' => $e,
					'userId' => $this->currentSession(),
				]);
			}
		}

		return $written;
	}

	/**
	 * Whether a picture arrived in this `$_FILES`-shaped entry. A picture that
	 * was sent and did not arrive is an error, not nothing.
	 *
	 * @throws InvalidActionException
	 * @throws UploadFailedException the upload failed on this side
	 */
	private function wasUploaded(array $upload, string $field): bool {
		return $this->uploadArrived($upload, $field, $this->instanceService->imageSizeLimit());
	}

	/**
	 * Removes the account's avatar, leaving Nextcloud's generated initials
	 * (Mastodon's `DELETE /api/v1/profile/avatar`; multipart cannot send
	 * "none"). A backend that owns the avatar (LDAP, SAML) makes this a 422.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/profile/avatar')]
	public function profileAvatarDelete(): DataResponse {
		try {
			$this->initViewer(true);
			$this->avatarService->remove($this->currentSession());

			return new DataResponse($this->accountEntity($this->refreshedViewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Removes the account's banner, and tells the servers that hold a copy
	 * (Mastodon's `DELETE /api/v1/profile/header`), since the banner is part
	 * of the actor document.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/profile/header')]
	public function profileHeaderDelete(): DataResponse {
		try {
			$this->initViewer(true);
			$this->bannerService->remove($this->currentSession());

			return new DataResponse($this->accountEntity($this->refreshedViewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The viewer read again, so that an entity built after a change carries
	 * the change rather than what was cached when the request began.
	 */
	private function refreshedViewer(): Person {
		$viewer = $this->cacheActorService->getFromLocalAccount(
			$this->viewer->getPreferredUsername()
		);
		$viewer->setExportFormat(ACore::FORMAT_LOCAL);

		return $viewer;
	}

	/**
	 * The account's Bluesky handle and DID, for a local account that has
	 * them; null otherwise.
	 */
	private function blueskyOf(Person $account): ?array {
		if (!$account->isLocal()) {
			return null;
		}
		try {
			$identity = $this->atprotoIdentities->getByActorId($account->getId());
		} catch (AtprotoIdentityNotFoundException) {
			return null;
		} catch (Throwable $e) {
			// the Bluesky side must never take the account entity down with it
			$this->logger->error('could not read the Bluesky identity of an account', ['actor' => $account->getId(), 'exception' => $e]);

			return null;
		}
		if (!$identity->isActive()) {
			return null;
		}

		return ['handle' => $identity->handle, 'did' => $identity->did, 'url' => 'https://bsky.app/profile/' . $identity->handle];
	}

	/**
	 * Mastodon's CredentialAccount: the Account entity plus `source`, built
	 * only for the two credentials routes, since `source` is the account's own
	 * settings. An empty `last_status_at` becomes null, as Mastodon sends it.
	 */
	private function accountEntity(Person $account): array {
		// the viewer is already in local format, see initViewer()
		$data = $account->jsonSerialize();
		$data['source'] = $account->exportSourceAsLocal();

		// the default audience is kept per user, not on the actor
		if ($account->isLocal()) {
			$data['source']['privacy'] = $this->accountService->getDefaultPrivacy(
				$this->currentSession()
			);
			$data['source']['hide_collections'] = $this->hiddenCollections->hides($this->currentSession());
			$data['hide_collections'] = $data['source']['hide_collections'];
		}

		$data['role'] = $this->adminApiService->credentialRole($this->currentSession());
		// Mastodon lists the account's highlighted roles, without their
		// permissions, as its public `roles`; the one role here is one of
		// those or nobody's
		$data['roles'] = ($data['role']['highlighted'] ?? false)
			? [array_intersect_key($data['role'], ['id' => true, 'name' => true, 'color' => true])] : [];
		$data['bluesky'] = $this->blueskyOf($account);

		if (($data['last_status_at'] ?? null) === '') {
			$data['last_status_at'] = null;
		}

		return $data;
	}

	/**
	 * Mastodon's sign-up route, which this server does not have: accounts are
	 * Nextcloud accounts, created by the server's own provisioning. Answers 403
	 * in Mastodon's error shape, pointing at the server's registration page if
	 * it has one, so a client can show something a person can act on.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts')]
	public function accountNew(): DataResponse {
		return new DataResponse(
			[
				'error' => 'Account registration is not handled by this application',
				'details' => (object)[
					'base' => [
						(object)[
							'error' => 'ERR_BLOCKED',
							'description' => $this->registrationAdvice(),
						],
					],
				],
			],
			Http::STATUS_FORBIDDEN
		);
	}

	/** Where somebody who wanted to sign up should be sent instead. */
	private function registrationAdvice(): string {
		$signup = $this->instanceService->getLocal()->getRegistrationsUrl();
		if ($signup !== null) {
			return 'Sign up for an account on this server at ' . $signup . '.';
		}
		if ($this->appManager->isEnabledForUser('registration')) {
			return 'An account on this server is a Nextcloud account. Sign up at '
				. $this->urlGenerator->getAbsoluteURL('/apps/registration/')
				. ' and this application will give that account a fediverse identity.';
		}

		return 'An account on this server is a Nextcloud account, created by an '
			. 'administrator. Once it exists, this application gives it a fediverse '
			. 'identity; there is nothing to sign up for here.';
	}

	/**
	 * The viewer's own posting defaults, as Mastodon's `/api/v1/preferences`:
	 * default audience, sensitive flag, language, and whether media and
	 * spoilers are expanded.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/preferences')]
	public function preferences(): DataResponse {
		try {
			$this->initViewer(true);
			$source = $this->viewer->exportSourceAsLocal();

			return new DataResponse([
				'posting:default:visibility' => $this->accountService->getDefaultPrivacy(
					$this->currentSession()
				),
				'posting:default:sensitive' => (bool)($source['sensitive'] ?? false),
				'posting:default:language' => ($source['language'] ?? '') !== ''
					? $source['language'] : null,
				// PeerTube's three NSFW policies, under Mastodon's names for
				// the same states; see `SensitiveMediaService`
				'reading:expand:media' => $this->sensitiveMediaService->policyFor(
					$this->currentSession()
				),
				// a content warning is not sensitive media, and this app keeps
				// no preference about it
				'reading:expand:spoilers' => false,
				// this app's own: when the bell rings, read-only here and
				// written at `PATCH /api/v1/social/notification_delivery`
				'notifications:delivery' => $this->notificationDeliveryService->of(
					$this->currentSession()
				)->toArray(),
				// this app's own: whether AI-labelled posts are hidden from this
				// reader, written at `PATCH /api/v1/social/ai_content`
				'reading:hide:ai' => $this->aiContentService->hides($this->currentSession()),
				// this app's own: whether like, boost and follower numbers are
				// left out, read-only here and written at `PATCH /api/v1/social/counts`
				'reading:hide:counts' => $this->countsService->hides($this->currentSession()),
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Records what this account wants done with sensitive media. Not a
	 * Mastodon route, but Mastodon's values, so it agrees with
	 * `/api/v1/preferences`. `''` means follow the instance default.
	 * `PublicPage` without CSRF like every route of this controller:
	 * `currentSession()` authenticates, and checks CSRF for a session caller.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/preferences')]
	public function preferencesUpdate(string $expand_media = ''): DataResponse {
		try {
			$userId = $this->currentSession();
			if (!$this->sensitiveMediaService->choose($userId, $expand_media)) {
				return new DataResponse(
					['error' => 'expand_media must be show_all, default, hide_all, or empty'],
					Http::STATUS_UNPROCESSABLE_ENTITY
				);
			}

			return new DataResponse([
				'reading:expand:media' => $this->sensitiveMediaService->policyFor($userId),
				// as chosen, so a settings page can show "follow the instance"
				'choice' => $this->sensitiveMediaService->choiceOf($userId),
				'instance' => $this->sensitiveMediaService->instancePolicy(),
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * One account, by whatever reference the client holds.
	 *
	 * Declared in `appinfo/routes.php` rather than as a `#[FrontpageRoute]`:
	 * `{id}` accepts slashes, so it would swallow
	 * `/api/v1/accounts/{account}/lists` and `.../featured_tags`, and must be
	 * registered after those. See the comment in that file.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	public function accountGet(string $id): DataResponse {
		try {
			$this->initViewer(false);
			$account = $this->cacheActorService->resolve($id, $this->viewer !== null);
			$account->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($this->hiddenCollections->annotate($account), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The account behind a handle, without fetching it remotely. Mastodon's
	 * `/accounts/lookup` answers one account or a 404, never a list.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/lookup')]
	public function accountLookup(string $acct = ''): DataResponse {
		try {
			$this->initViewer(false);
			$acct = ltrim(trim($acct), '@');
			if ($acct === '') {
				throw new InvalidActionException('acct is required');
			}

			$account = $this->cacheActorService->getFromAccount($acct, false);
			$account->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($this->hiddenCollections->annotate($account), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's account search, which composers call to complete a `@handle`.
	 * `resolve` fetches an unknown account, only for a viewer and only for
	 * something shaped like an address or handle, as `/api/v2/search` does.
	 * `following` narrows to accounts the viewer follows, for completing a reply.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/search')]
	public function accountsSearch(
		string $q = '',
		int $limit = 40,
		bool $resolve = false,
		bool $following = false,
	): DataResponse {
		try {
			$this->initViewer(true);
			$q = trim($q);
			$limit = min(max($limit, 1), 80);

			if ($q === '') {
				return new DataResponse([], Http::STATUS_OK);
			}

			// the search itself is narrowed, not its answer, so followed
			// accounts outside the first `limit` matches are still found
			$found = $this->searchService->searchAccounts($q, $limit, $following ? $this->viewer->getId() : '', $resolve);
			if ($resolve && (str_starts_with($q, '@') || str_starts_with($q, 'http'))) {
				$resolved = $this->searchService->searchUri($q);
				if ($following) {
					$resolved = array_filter(
						$resolved,
						fn (Person $account): bool
							=> $this->followService->getRelationshipWith($account)->isFollowing()
					);
				}
				$found = array_merge($resolved, $found);
			}

			$accounts = [];
			foreach ($found as $account) {
				$accounts[$account->getId()] = $account->setExportFormat(ACore::FORMAT_LOCAL);
			}

			return new DataResponse(array_slice(array_values($accounts), 0, $limit), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Who, of the people the viewer follows, also follows each named account
	 * (Mastodon's `familiar_followers`, the "followed by X and 3 others" line).
	 *
	 * @param array<mixed>|string $id one or more account ids
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/familiar_followers')]
	public function familiarFollowers(array|string $id = []): DataResponse {
		try {
			$this->initViewer(true);
			$ids = is_array($id) ? $id : [$id];

			$familiar = [];
			foreach (array_slice($ids, 0, self::FAMILIAR_FOLLOWERS_MAX) as $one) {
				$target = $this->cacheActorService->resolve((string)$one, $this->viewer !== null);
				$accounts = $this->followService->familiarFollowers($this->viewer, $target);
				$familiar[] = [
					'id' => (string)$target->getNid(),
					'accounts' => array_map(
						static fn (Person $account): Person => $account->setExportFormat(ACore::FORMAT_LOCAL),
						$accounts
					),
				];
			}

			return new DataResponse($familiar, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The viewer's relationship with each of the accounts asked about. `$id`
	 * has a default because the dispatcher binds it before the method's try
	 * block, where a missing `id[]` would be a TypeError.
	 *
	 * @param array<mixed>|string $id
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/relationships')]
	public function relationships(array|string $id = []): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse($this->followService->getRelationships(self::listParam($id)), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The statuses of an account: its posts and, as on Mastodon, its boosts,
	 * each a status whose `reblog` is the post it repeats.
	 *
	 * Boosts are left out with `exclude_reblogs`, and wherever Mastodon's own
	 * query cannot find one: a page narrowed to media, a kind or a hashtag
	 * asks about the status itself, and a boost carries no attachments and no
	 * tags of its own. A boost of a post the reader may not see is left out.
	 * `exclude_replies` leaves out replies to other accounts and keeps the
	 * account's threads; `tagged` keeps the posts carrying that hashtag.
	 *
	 * @param string $account
	 * @param int $limit
	 * @param int|string $max_id
	 * @param int|string $min_id
	 * @param int|string $since_id
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account}/statuses', requirements: ['account' => '.+'])]
	public function accountStatuses(
		string $account,
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
		bool $pinned = false,
		bool $only_media = false,
		string $media_type = '',
		bool $only_short = false,
		bool $exclude_reblogs = false,
		bool $exclude_replies = false,
		string $tagged = '',
	): DataResponse {
		try {
			$this->initViewer(false);

			// `{account}` is whatever the client holds, usually the numeric id
			$local = $this->cacheActorService->resolve($account, $this->viewer !== null);

			if ($pinned) {
				return new DataResponse(
					$this->pinService->getPinnedPosts($local->getId(), $this->viewer),
					Http::STATUS_OK
				);
			}

			$profileFilling = false;
			$remoteProfileFilling = false;
			$syncState = null;
			$remoteProfile = !$local->isLocal() && !BlueskyActorService::isBluesky($local);
			// Bluesky profiles have no ActivityPub outbox: read their author
			// feed into the stream first, keeping AppView's cursor behind the
			// local max_id so paging works as usual
			if (BlueskyActorService::isBluesky($local) && !$min_id && !$since_id) {
				$profileFilling = $this->blueskyFeeds->authorPage($local, $max_id ? (string)$max_id : '', $limit, $this->viewer);
			} elseif ($remoteProfile && $this->viewer !== null && !$max_id && !$min_id && !$since_id) {
				// only for a caller with a session, so anonymous callers cannot
				// make this instance fetch and store any account's posts; only
				// for the first page, in the background, at most once a
				// quarter-hour per account
				$syncState = $this->remoteFetchQueue->refreshTimelineState($local);
				$remoteProfileFilling = in_array($syncState, ['queued', 'pending'], true);
				$profileFilling = $remoteProfileFilling;
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::ACCOUNT)
				->setAccountId($local->getId())
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setOnlyMedia($only_media)
				->setMediaType($media_type)
				->setOnlyShort($only_short)
				->setExcludeReplies($exclude_replies)
				->setTagged($tagged);
			$options->setWithBoosts(
				!$exclude_reblogs
				&& !$options->isOnlyMedia() && !$options->isOnlyVideo() && !$options->isOnlyShort()
				&& !$options->isOnlyNews() && $options->getMediaType() === '' && $tagged === ''
			);

			$posts = $this->streamService->getTimeline($options);
			$this->pinService->markPinned($posts, $local->getId());
			// the post a boost repeats is read only when the reader may see it
			$shown = array_values(array_filter(
				$posts,
				static fn (mixed $post): bool => !($post instanceof Announce) || $post->getObject() instanceof Stream
			));
			// A filtered first page can be empty even though the outbox cursor
			// has more pages (for example photos behind recent text-only posts).
			if ($remoteProfile && $this->viewer !== null && !$min_id && !$since_id
				&& ($max_id || $shown === []) && count($shown) < $options->getLimit()) {
				$remoteProfileFilling = $this->remoteFetchQueue->fillTimeline($local)
					|| $this->remoteFetchQueue->isTimelinePending($local->getId())
					|| $remoteProfileFilling;
				$profileFilling = $remoteProfileFilling || $profileFilling;
			}
			// the outbox job can finish before its media is cached: keep the
			// profile refreshing until the copies are local or refused
			if ($remoteProfile) {
				foreach ($shown as $post) {
					if (!$post instanceof Stream) {
						continue;
					}
					foreach ($post->getAttachments() as $media) {
						if ($media->getCacheError() === 0 && (string)$media->getUrl() === '' && (string)$media->getRemoteUrl() !== '') {
							$remoteProfileFilling = true;
							$profileFilling = true;
							break 2;
						}
					}
				}
			}

			$response = $this->paged(
				$this->filterService->apply($shown, Filter::CONTEXT_ACCOUNT, $this->viewer),
				$options->getLimit(),
				$posts,
				$this->streamService->lastTimelineRowCount()
			);
			if ($this->streamService->lastTimelineCountsFilling()) {
				$response->addHeader('X-Social-Filling', '1');
			}
			if ($profileFilling) {
				$response->addHeader('X-Social-Filling', '1');
				$response->addHeader('X-Social-Profile-Filling', '1');
			}
			if ($remoteProfileFilling) {
				$response->addHeader('X-Social-Remote-Profile-Filling', '1');
			}
			if ($remoteProfile && $this->viewer !== null) {
				$syncState ??= $this->remoteFetchQueue->timelineSyncFailed($local->getId()) ? 'failed' : 'idle';
				$syncState = $syncState === 'failed' ? 'failed' : ($profileFilling ? 'pending' : 'idle');
				$response->addHeader('X-Social-Profile-Sync-State', $syncState);
			}

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The accounts an account follows. For an account on another server
	 * this is what this server knows and, after it, whom the account's own
	 * network lists (`FollowListService`); see accountFollowers().
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account}/following', requirements: ['account' => '.+'])]
	public function accountFollowing(
		string $account,
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since = 0,
		int|string $since_id = 0,
	): DataResponse {
		return $this->followList($account, ProbeOptions::FOLLOWING, $limit, $max_id, $min_id, $this->sinceCursor($since_id, $since));
	}

	/**
	 * The accounts that follow an account. Those known here come first, paged
	 * by id; for a remote account, the ones its own network lists follow, read
	 * in the background and kept for an hour (`X-Social-Filling: 1` while
	 * being read), paged by the last account's `max_id`. Hidden lists stay
	 * hidden; a local account's hidden lists are shown to itself only.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account}/followers', requirements: ['account' => '.+'])]
	public function accountFollowers(
		string $account,
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since = 0,
		int|string $since_id = 0,
	): DataResponse {
		return $this->followList($account, ProbeOptions::FOLLOWERS, $limit, $max_id, $min_id, $this->sinceCursor($since_id, $since));
	}

	private function followList(
		string $account,
		string $direction,
		int $limit,
		int|string $max_id,
		int|string $min_id,
		int|string $since,
	): DataResponse {
		try {
			$this->initViewer(false);
			$actor = $this->cacheActorService->resolve($account, $this->viewer !== null);

			// Mastodon answers an empty list, not a refusal, to anybody but
			// the account itself
			if (!$this->hiddenCollections->mayList($actor, $this->viewer)) {
				return new DataResponse([], Http::STATUS_OK);
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe($direction)
				->setAccountId($actor->getId())
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since);

			// the remote list is read for a session caller only: it fetches from
			// another server, and every account it names not cached here too
			if ($this->viewer === null || $actor->isLocal()) {
				return $this->paged($this->cacheActorService->probeActors($options), $options->getLimit());
			}

			$page = $this->followLists->page($actor, $direction, $options);
			if ($page['next'] === null) {
				$response = $this->paged($page['accounts'], $options->getLimit());
			} else {
				$response = new DataResponse($page['accounts'], Http::STATUS_OK);
				if ($page['next'] !== '') {
					$response->addHeader('Link', '<' . $this->pageUrl(['max_id' => $page['next']]) . '>; rel="next"');
				}
			}
			if ($page['filling']) {
				$response->addHeader('X-Social-Filling', '1');
			}

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The accounts waiting for the viewer's approval to follow them, newest
	 * first, paged as Mastodon does (`limit` 40, at most 80, `max_id`,
	 * `min_id`, `Link` header) on the follow request's cursor; see
	 * FollowsRequest::getPendingByObjectId(). `paged()` does not fit.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/follow_requests')]
	public function followRequests(int $limit = 40, string $max_id = '', string $min_id = ''): DataResponse {
		try {
			$this->initViewer(true);

			$limit = ($limit < 1) ? 40 : min($limit, 80);
			$page = $this->followService->getPendingRequestPage($limit, $max_id, $min_id);
			foreach ($page['accounts'] as $account) {
				$account->setExportFormat(ACore::FORMAT_LOCAL);
			}

			$response = new DataResponse($page['accounts'], Http::STATUS_OK);
			if ($page['rows'] > 0) {
				$links = [];
				if ($page['rows'] >= $limit) {
					$links[] = '<' . $this->pageUrl(['max_id' => $page['last']]) . '>; rel="next"';
				}
				$links[] = '<' . $this->pageUrl(['min_id' => $page['first']]) . '>; rel="prev"';
				$response->addHeader('Link', implode(', ', $links));
			}

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/follow_requests/{id}/authorize', requirements: ['id' => '.+'])]
	public function followRequestAuthorize(string $id): DataResponse {
		return $this->followRequestAction($id, true);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/follow_requests/{id}/reject', requirements: ['id' => '.+'])]
	public function followRequestReject(string $id): DataResponse {
		return $this->followRequestAction($id, false);
	}

	private function followRequestAction(string $id, bool $authorize): DataResponse {
		try {
			$this->initViewer(true);
			$follower = $this->cacheActorService->resolve($id, $this->viewer !== null);

			if ($authorize) {
				$this->followService->authorizeFollowRequest($follower);
			} else {
				$this->followService->rejectFollowRequest($follower);
			}
			// the bell entry that asked comes down with the answer
			$this->notificationService->onFollowRequestAnswered($this->viewer, $follower->getId());

			return new DataResponse(
				$this->followService->getRelationshipWith($follower), Http::STATUS_OK
			);
		} catch (FollowNotFoundException $e) {
			return new DataResponse(['error' => 'no pending follow request'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Follows the account, or asks to (a locked account leaves the
	 * relationship in `requested`). Returns the updated relationship.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/follow', requirements: ['id' => '.+'])]
	public function accountFollow(
		string $id, ?bool $notify = null, ?bool $reblogs = null,
	): DataResponse {
		try {
			$this->initViewer(true);
			$target = $this->cacheActorService->resolve($id, $this->viewer !== null);

			// one counter moved by one (`AccountService::bumpActorCount()`), only
			// for a new follow: repeating it is how a client changes the switches
			if ($this->followService->followAccount($this->viewer, $target->getAccount())) {
				$this->accountService->bumpActorCount($this->viewer->getId(), 'count_following', 1);
			}

			// the bell on a profile, sent with the follow; absent means leave it
			if ($notify !== null) {
				$this->accountRelationService->setNotify($this->viewer, $target, $notify);
			}

			// whether this account's boosts go in the reader's timelines; absent
			// means leave it
			if ($reblogs !== null) {
				$this->accountRelationService->setShowReblogs($this->viewer, $target, $reblogs);
			}

			return new DataResponse(
				$this->followService->getRelationshipWith($target), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/unfollow', requirements: ['id' => '.+'])]
	public function accountUnfollow(string $id): DataResponse {
		try {
			$this->initViewer(true);
			$target = $this->cacheActorService->resolve($id, $this->viewer !== null);

			if ($this->followService->unfollowAccount($this->viewer, $target->getAccount())) {
				$this->accountService->bumpActorCount($this->viewer->getId(), 'count_following', -1);
			}

			return new DataResponse(
				$this->followService->getRelationshipWith($target), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/block', requirements: ['id' => '.+'])]
	public function accountBlock(string $id): DataResponse {
		return $this->relationshipAction($id, 'block');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/unblock', requirements: ['id' => '.+'])]
	public function accountUnblock(string $id): DataResponse {
		return $this->relationshipAction($id, 'unblock');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/mute', requirements: ['id' => '.+'])]
	public function accountMute(string $id, bool $notifications = true, int $duration = 0): DataResponse {
		return $this->relationshipAction($id, 'mute', $notifications, $duration);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/unmute', requirements: ['id' => '.+'])]
	public function accountUnmute(string $id): DataResponse {
		return $this->relationshipAction($id, 'unmute');
	}

	private function relationshipAction(
		string $id, string $action, bool $notifications = true, int $duration = 0,
	): DataResponse {
		try {
			$this->initViewer(true);
			$target = $this->cacheActorService->resolve($id, $this->viewer !== null);

			switch ($action) {
				case 'block':
					$this->relationshipService->block($this->viewer, $target);
					break;
				case 'unblock':
					$this->relationshipService->unblock($this->viewer, $target);
					break;
				case 'mute':
					$this->relationshipService->mute($this->viewer, $target, $notifications);
					// duration 0 is Mastodon's "until I say otherwise", and it
					// drops the expiry a previous timed mute left behind
					$this->accountRelationService->setMuteExpiry($this->viewer, $target, $duration);
					break;
				case 'unmute':
					$this->relationshipService->unmute($this->viewer, $target);
					$this->accountRelationService->clearMuteExpiry($this->viewer, $target);
					break;
			}

			// Mastodon clients expect the updated relationship entity back
			$this->followService->setViewer($this->viewer);

			return new DataResponse(
				$this->followService->getRelationshipWith($target), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/blocks')]
	public function blocks(int $limit = 40): DataResponse {
		return $this->listRelatedAccounts(ActorRelation::TYPE_BLOCK, $limit);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/mutes')]
	public function mutes(int $limit = 40): DataResponse {
		return $this->listRelatedAccounts(ActorRelation::TYPE_MUTE, $limit);
	}

	/**
	 * The `since_id` of a follower or following page; `since` is also
	 * accepted.
	 */
	private function sinceCursor(int|string $sinceId, int|string $since): int|string {
		return (Nid::compare($sinceId, '0') > 0) ? $sinceId : $since;
	}

	private function listRelatedAccounts(string $type, int $limit): DataResponse {
		try {
			$this->initViewer(true);
			$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));

			$related = $this->relationshipService->getRelated($this->viewer, $type, $limit);
			if ($type === ActorRelation::TYPE_MUTE) {
				// one query for the page: a mute whose expiry has passed is not
				// a mute, and nothing deleted the row to make that so
				$related = $this->accountRelationService->withoutExpiredMutes($this->viewer, $related);
			}

			$accounts = [];
			foreach ($related as $person) {
				$person->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[] = $person;
			}

			// no `Link` header: neither route takes a cursor, so a next page
			// would repeat this one
			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
