<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\UploadFailedException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
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
	 * `source[privacy]` (the default audience) and `fields_attributes` (the
	 * profile metadata). Returns the updated account entity.
	 *
	 * Every field is optional and only what was sent is written, which is what
	 * lets a client that edits one thing leave the rest alone. Three of them
	 * used to be accepted and dropped — `display_name`, `avatar` and `bot` —
	 * so a client's profile editor, which sends the whole form in one PATCH,
	 * got a 200 and showed the name and picture unchanged.
	 *
	 * The name and the picture belong to the Nextcloud account rather than to
	 * the actor, so they are written there and the actor cache is refreshed. A
	 * backend that owns either of them (LDAP, SAML, anything provisioned from
	 * elsewhere) makes this a **422** rather than a silent success: the profile
	 * looks the same afterwards either way, and only one of those two tells the
	 * user why.
	 *
	 * Every refusal is made before anything is written, and a part that fails
	 * after the first was stored is logged rather than answered: the request
	 * either changes nothing or answers 200 with what it stored.
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

			// Everything that can be refused is refused here, before anything
			// is written: a picture that was sent and did not arrive, one that
			// is not a picture, a name or an avatar the backend owns, an
			// audience no post can have.
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

			// The parts in the order they are written. The banner goes first:
			// whether its bytes are an image this app stores is only known
			// once they are stored, so a banner that is refused is refused
			// before anything else is written. Mastodon sends both pictures
			// on this route; the avatar is the Nextcloud account's picture,
			// the one the whole server shows, and is written there.
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
	 * Writes the parts of a profile update in turn.
	 *
	 * A failure of the first part fails the request with nothing written. A
	 * failure after that is logged and the rest is still written: the answer
	 * is then a 200 whose account entity shows what was stored, which a client
	 * can show, where a 500 after half a profile left it unable to tell what
	 * had changed.
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
	 * was sent and did not arrive is an error rather than nothing: skipping it
	 * answered 200 over the old picture.
	 *
	 * @throws InvalidActionException
	 * @throws UploadFailedException the upload failed on this side
	 */
	private function wasUploaded(array $upload, string $field): bool {
		return $this->uploadArrived($upload, $field, $this->instanceService->imageSizeLimit());
	}

	/**
	 * Removes the account's avatar, leaving Nextcloud's generated initials.
	 *
	 * Mastodon's `DELETE /api/v1/profile/avatar`. `update_credentials` can
	 * only replace a picture with another one — multipart has no way to send
	 * "none" — so without this route a client can offer "change picture" and
	 * not "remove picture", and an account that wanted none was stuck with
	 * whatever it uploaded last.
	 *
	 * The avatar is the Nextcloud account's, shown by the whole server rather
	 * than only here, which is why a backend that owns it (LDAP, SAML) refuses
	 * this with a 422 instead of answering 200 over an unchanged picture.
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
	 * Removes the account's banner, and tells the servers that hold a copy.
	 *
	 * Mastodon's `DELETE /api/v1/profile/header`. The banner is part of the
	 * actor document, so taking it off this instance and not federating the
	 * change would leave the profile with a banner everywhere else.
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
	 * A local account as Mastodon's Account entity, with the gaps a brand-new
	 * account has filled the way Mastodon fills them.
	 *
	 * `Person::exportAsLocal()` writes `""` where it has nothing: for
	 * `last_status_at` when no post exists yet, for `avatar`/`header` while no
	 * icon is cached. Mastodon sends `null` for the date and never an empty
	 * image URL — a placeholder picture instead — and a strict decoder that
	 * expects a date or a URL there fails the whole Account, which is the
	 * first thing a client asks for after login. The stored source of the date
	 * is `AccountService::addLocalActorDetailCount()`, the export is
	 * `Person::exportAsLocal()`; until both emit what Mastodon does, this is
	 * where the credentials routes put it right.
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * Mastodon's **CredentialAccount**: the Account entity plus `source`.
	 *
	 * Only the two credentials routes build one, because `source` is the
	 * account's own copy of its settings and `source.follow_requests_count` is
	 * nobody's business but theirs. It used to come out of the model on every
	 * Account this app emitted, including other people's and anonymous reads.
	 */
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

	private function accountEntity(Person $account): array {
		// the viewer is already in local format, see initViewer()
		$data = $account->jsonSerialize();
		$data['source'] = $account->exportSourceAsLocal();

		// the default audience is the one setting this app keeps per user
		// rather than on the actor: the model has no way to know it
		if ($account->isLocal()) {
			$data['source']['privacy'] = $this->accountService->getDefaultPrivacy(
				$this->currentSession()
			);
		}

		$data['role'] = $this->adminApiService->credentialRole($this->currentSession());
		$data['bluesky'] = $this->blueskyOf($account);

		if (($data['last_status_at'] ?? null) === '') {
			$data['last_status_at'] = null;
		}

		return $data;
	}

	/**
	 * Mastodon's sign-up route, which this server does not have.
	 *
	 * An account here is a Nextcloud account: the server creates it, through
	 * whatever provisioning it is configured with, and this app is given one
	 * that already exists. So there is nothing for this route to create — and
	 * a **404** was the wrong way to say so, because a client reads it as "this
	 * server is broken" and shows nothing a person can act on.
	 *
	 * A 403 in Mastodon's own error shape is read, shown, and says where to go
	 * instead: the server's registration page when it has one, and otherwise
	 * that an administrator creates accounts here. `registrations: false` in
	 * the instance entity already says the same thing to a client that looks
	 * before it asks; this is for the one that asks.
	 *
	 * The approval queue, the invites and the email confirmation Mastodon
	 * builds on top of its sign-up are the server's too, for the same reason.
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
	 * The viewer's own posting defaults, as Mastodon's `/api/v1/preferences`.
	 *
	 * Every value here is one the account already has somewhere — the default
	 * audience it posts with, whether its posts are marked sensitive, the
	 * language, and whether media and spoilers are expanded — and a client that
	 * cannot read them guesses, which is how a client ends up posting publicly
	 * for somebody whose default is followers-only.
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
				// PeerTube's three NSFW policies, under the names Mastodon
				// already has for the same three states: what this account
				// chose, or what the instance does for somebody who has not.
				// See `SensitiveMediaService`.
				'reading:expand:media' => $this->sensitiveMediaService->policyFor(
					$this->currentSession()
				),
				// still Mastodon's default: a content warning is a different
				// thing from sensitive media and this app keeps no preference
				// about it
				'reading:expand:spoilers' => false,
				// this app's own: when the bell rings, read-only here and
				// written at `PATCH /api/v1/social/notification_delivery`
				'notifications:delivery' => $this->notificationDeliveryService->of(
					$this->currentSession()
				)->toArray(),
				// this app's own: whether posts labelled as made with AI are
				// kept out of what this reader is shown, read-only here and
				// written at `PATCH /api/v1/social/ai_content`
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
	 * Records what this account wants done with sensitive media.
	 *
	 * Not a Mastodon route — Mastodon has no write for preferences, and its
	 * own reading preferences are set on its web front end rather than through
	 * the API. The value is Mastodon's all the same, so a client that reads
	 * `/api/v1/preferences` and a client that writes here agree about what the
	 * three words mean.
	 *
	 * `''` is a fourth thing and not a fourth policy: it puts the account back
	 * to following whatever the instance does, which is different from
	 * choosing what the instance happens to do today.
	 *
	 * `PublicPage` with no CSRF like every other route of this controller:
	 * `currentSession()` is what authenticates, and it checks the CSRF token
	 * itself for a caller with a session rather than a bearer token.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/preferences')]
	public function preferencesUpdate(string $expandMedia = ''): DataResponse {
		try {
			$userId = $this->currentSession();
			if (!$this->sensitiveMediaService->choose($userId, $expandMedia)) {
				return new DataResponse(
					['error' => 'expand_media must be show_all, default, hide_all, or empty'],
					Http::STATUS_UNPROCESSABLE_ENTITY
				);
			}

			return new DataResponse([
				'reading:expand:media' => $this->sensitiveMediaService->policyFor($userId),
				// what was chosen, as it was chosen: a settings page has to be
				// able to show "follow the instance" as the state it is
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
	 * Every entity this app emits addresses an account by its numeric id, and
	 * until this route existed there was nothing to do with one: tapping an
	 * author, a mention, a boost or a notification asked for a profile that no
	 * route answered.
	 *
	 * The only route of this app that is not a `#[FrontpageRoute]`: `{id}`
	 * accepts slashes, so this url also matches `/api/v1/accounts/{account}/lists`
	 * and `/api/v1/accounts/{account}/featured_tags`, which belong to other
	 * controllers, and a route has to be offered to the matcher after the
	 * routes it can swallow. Attribute routes are contributed one controller at
	 * a time in whatever order the filesystem lists them, so it is declared in
	 * `appinfo/routes.php` instead, which the server loads after all of them.
	 * See the comment in that file.
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

			return new DataResponse($account, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The account behind a handle, without following it up remotely.
	 *
	 * Mastodon's `/accounts/lookup` is the cheap counterpart to `/search`: it
	 * answers with an account or a 404, never with a list, and never reaches
	 * out to another server. Clients use it to turn a `@user@host` someone
	 * typed or pasted into something they can open.
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

			return new DataResponse($account, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's account search: what a composer calls to complete a `@handle`
	 * as somebody types it.
	 *
	 * `/api/v2/search` answers accounts too, but no client uses it for
	 * autocomplete — they call this one, and this app did not have it, so
	 * mention completion failed in every client that offers it.
	 *
	 * `resolve` asks this instance to go and find an account it has never seen,
	 * which is what makes completing a handle from another server work at all.
	 * It fires only for a viewer, and only for something shaped like an
	 * address or a handle, the same rule `/api/v2/search` follows.
	 *
	 * `following` narrows the answer to accounts the viewer follows, which is
	 * what a client asks for when it is completing a reply rather than a search.
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

			// `following=true` is a client completing a reply rather than
			// searching: it wants the people already in the conversation's
			// reach, not everybody this instance has ever cached. The search
			// itself is narrowed rather than its answer: filtering the first
			// `limit` matches found nobody whenever the followed account was
			// not among them.
			$found = $this->searchService->searchAccounts($q, $limit, $following ? $this->viewer->getId() : '');
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
	 * Who, of the people the viewer follows, also follows each named account.
	 *
	 * Mastodon's `familiar_followers`, which draws the "followed by X and 3
	 * others you know" line on a profile. Absent, that line is simply missing
	 * from every profile a client shows.
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
	 * The viewer's relationship with each of the accounts asked about.
	 *
	 * `$id` carries its own default because a request-bound array parameter is
	 * filled in by the dispatcher, before the method's own try block: a client
	 * that asks with no `id[]` at all used to raise a TypeError there and get a
	 * Nextcloud error page instead of `{"error": …}`.
	 *
	 * @param array $id
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/relationships')]
	public function relationships(array $id = []): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse($this->followService->getRelationships($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
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
	): DataResponse {
		try {
			$this->initViewer(false);

			// `{account}` is whatever the client holds, which for every entity
			// this app emits is the numeric id — not the acct handle this route
			// used to insist on.
			$local = $this->cacheActorService->resolve($account, $this->viewer !== null);

			if ($pinned) {
				return new DataResponse(
					$this->pinService->getPinnedPosts($local->getId(), $this->viewer),
					Http::STATUS_OK
				);
			}

			// only for a caller with a session: a sync fetches the account's
			// outbox from its server and stores every post of it, so an
			// anonymous caller could make this instance fetch and keep the
			// posts of any account they can name. Only for the first page,
			// which is where new posts appear, and in the background, at most
			// once a quarter-hour per account: this page answers with what is
			// stored, and the next look has what the sync found.
			if ($this->viewer !== null && !$max_id && !$min_id && !$since_id) {
				$this->remoteFetchQueue->syncTimeline($local);
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
				->setMediaType($media_type);

			$posts = $this->streamService->getTimeline($options);
			$this->pinService->markPinned($posts, $local->getId());

			return $this->paged(
				$this->filterService->apply($posts, Filter::CONTEXT_ACCOUNT, $this->viewer),
				$options->getLimit(),
				$posts
			);
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
	): DataResponse {
		return $this->followList($account, ProbeOptions::FOLLOWING, $limit, $max_id, $min_id, $since);
	}

	/**
	 * The accounts that follow an account.
	 *
	 * The ones this server knows come first, paged by their ids as always.
	 * For an account on another server the ones its own network lists follow
	 * after the last of them, read in the background and kept for an hour
	 * (`X-Social-Filling: 1` while that read was just asked for); a page that
	 * reaches into those is continued by the `max_id` of its last account.
	 * An account that hides its list where it lives has it hidden here too.
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
	): DataResponse {
		return $this->followList($account, ProbeOptions::FOLLOWERS, $limit, $max_id, $min_id, $since);
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

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe($direction)
				->setAccountId($actor->getId())
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since);

			// what the account's network lists is read for a caller with a
			// session only: the read is a fetch from another server, and every
			// account it names not cached here is fetched too
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
	 * first, a page at a time.
	 *
	 * Paged the way Mastodon pages it — `limit` (40, at most 80), `max_id` and
	 * `min_id`, and a `Link` header — and, as in Mastodon, the cursor is the
	 * follow request's and not the account's: see
	 * FollowsRequest::getPendingByObjectId() for what it holds. `paged()` does
	 * not fit, since it pages on the entities' own ids.
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

			// one counter moved by one, rather than all three recomputed with
			// aggregate queries: see `AccountService::bumpActorCount()`. Only
			// for a follow that is new: the same call on an existing one is how
			// a client changes the switches below
			if ($this->followService->followAccount($this->viewer, $target->getAccount())) {
				$this->accountService->bumpActorCount($this->viewer->getId(), 'count_following', 1);
			}

			// the bell on a profile, which Mastodon sends *with* the follow.
			// Absent means "leave it as it is": a client re-following to change
			// nothing else must not silently turn the bell off
			if ($notify !== null) {
				$this->accountRelationService->setNotify($this->viewer, $target, $notify);
			}

			// and the other switch Mastodon sends with a follow: whether this
			// account's boosts belong in the reader's timelines. Absent means
			// the same thing it means for the bell
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

			// No `Link` header: neither route takes a cursor, and the next page
			// `paged()` would advertise is the one just sent — a client paging
			// on the header scrolled the same block of blocked accounts for
			// ever. Answering one page is the honest shape until
			// RelationshipService can be asked for a cursored one.
			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
