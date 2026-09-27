<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnknownProbeException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DeliveryService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\GifService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\MarkerService;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\PeerTubeService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\QuoteService;
use OCA\Social\Service\ReactionService;
use OCA\Social\Service\ReactionSummaryService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\ScheduledStatusService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\SensitiveMediaService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\TeamService;
use OCA\Social\Service\TimelineRevisionService;
use OCA\Social\Service\TranslationService;
use OCA\Social\Service\ViewCountService;
use OCA\Social\Service\WatchService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\ISession;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class ApiController
 *
 * @package OCA\Social\Controller
 */
class ApiController extends MastodonApiController {
	use ApiInstance;
	use ApiMedia;

	/** where a used Idempotency-Key is remembered, and for how long */
	private const IDEMPOTENCY_CACHE = 'social_idempotency';

	/** Accounts one `familiar_followers` call answers for; Mastodon's cap too. */
	private const FAMILIAR_FOLLOWERS_MAX = 20;
	private const IDEMPOTENCY_TTL = 3600;

	/**
	 * How long `/media/{uuid}` may be cached: a year, the conventional
	 * "forever" for content whose URL names its bytes and never changes.
	 */
	private const MEDIA_CACHE_SECONDS = 31536000;

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
		private InstanceService $instanceService,
		private CacheDocumentService $cacheDocumentService,
		private DocumentService $documentService,
		private RelationshipService $relationshipService,
		private ActionService $actionService,
		private PostService $postService,
		private PollService $pollService,
		private ISession $session,
		private PinService $pinService,
		private HashtagService $hashtagService,
		private MarkerService $markerService,
		private StreamRequest $streamRequest,
		private ReportService $reportService,
		private SearchService $searchService,
		private ConfigService $configService,
		private CurlService $curlService,
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private FilterService $filterService,
		private BannerService $bannerService,
		private AvatarService $avatarService,
		private AccountRelationService $accountRelationService,
		private ScheduledStatusService $scheduledStatusService,
		private PostReviewService $postReviewService,
		private SensitiveMediaService $sensitiveMediaService,
		private ViewCountService $viewCountService,
		private TeamService $teamService,
		private EmojiService $emojiService,
		private IAppManager $appManager,
		private FediverseService $fediverseService,
		private PlaceService $placeService,
		private DeliveryService $deliveryService,
		private ReactionService $reactionService,
		private ReactionSummaryService $reactionSummaryService,
		private GifService $gifService,
		private NotificationService $notificationService,
		private TranslationService $translationService,
		private NotificationPolicyService $notificationPolicyService,
		private QuoteService $quoteService,
		private WatchService $watchService,
		private IFactory $l10nFactory,
		private TimelineRevisionService $timelineRevisionService,
		private DurableCache $durableCache,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/apps/verify_credentials')]
	public function appsCredentials() {
		try {
			$this->initViewer(true);

			// `vapid_key` is always present, because a client reads it out of
			// this response before it decides whether to offer push at all; it
			// is empty because this app has no Web Push endpoint, which is the
			// answer that makes a client stop asking.
			if ($this->client === null) {
				return new DataResponse(
					[
						'name' => 'Nextcloud Social',
						'website' => 'https://github.com/nextcloud/social/',
						'vapid_key' => ''
					], Http::STATUS_OK
				);
			} else {
				return new DataResponse(
					[
						'name' => $this->client->getAppName(),
						// null rather than '' for the same reason the
						// registration route sends null: a client that decodes
						// this as a URL fails on the empty string
						'website' => $this->client->getAppWebsite() === ''
							? null : $this->client->getAppWebsite(),
						'vapid_key' => ''
					], Http::STATUS_OK
				);
			}
		} catch (Throwable $e) {
			return $this->error($e);
		}
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
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/accounts/update_credentials')]
	public function updateCredentials(): DataResponse {
		try {
			$this->initViewer(true);

			$changed = false;
			$input = $this->convertInput(file_get_contents('php://input'));
			if (array_key_exists('locked', $input)) {
				$this->accountService->setLocked($this->currentSession(), $this->formBool($input['locked']));
				$changed = true;
			}

			// an absent `note` is a client that did not mention the bio, not a
			// client asking for an empty one
			if (array_key_exists('note', $input)) {
				$this->accountService->setSummary($this->currentSession(), (string)$input['note']);
				$changed = true;
			}

			// `source[privacy]` is the audience this account posts with when a
			// client does not name one, and `statusNew()` reads it back
			$privacy = $input['source']['privacy'] ?? null;
			if (is_string($privacy) && $privacy !== '') {
				$this->accountService->setDefaultPrivacy($this->currentSession(), $privacy);
				$changed = true;
			}

			// an absent display name is a client that did not mention it. A
			// backend that owns the name raises, and the client sees the
			// refusal rather than a 200 over an unchanged profile
			if (array_key_exists('display_name', $input)) {
				$this->accountService->setDisplayName(
					$this->currentSession(), (string)$input['display_name']
				);
				$changed = true;
			}

			// only the flags that were sent: a client updating the display
			// name must not reset the ones it did not mention
			$flags = [];
			foreach (['discoverable', 'indexable', 'bot'] as $flag) {
				if (array_key_exists($flag, $input)) {
					$flags[$flag] = $this->formBool($input[$flag]);
				}
			}
			if ($flags !== []) {
				$this->accountService->setActorFlags($this->currentSession(), $flags);
				$changed = true;
			}

			// Mastodon sends both pictures multipart on this same route: the
			// banner as `header`, the avatar as `avatar`. The avatar is the
			// Nextcloud account's picture — the same one the whole server shows
			// — so it is written there, and a backend that owns it raises
			// rather than answering 200 over an unchanged picture.
			$header = $_FILES['header'] ?? [];
			if ($header !== [] && ($header['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
				$this->bannerService->setFromTempFile($this->currentSession(), $header['tmp_name']);
				$changed = true;
			}

			$avatar = $_FILES['avatar'] ?? [];
			if ($avatar !== [] && ($avatar['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
				$this->avatarService->setFromTempFile($this->currentSession(), $avatar);
				$changed = true;
			}

			if (array_key_exists('fields_attributes', $input) && is_array($input['fields_attributes'])) {
				// clients send either a list or an object keyed by index
				$this->accountService->setFields(
					$this->currentSession(), array_values($input['fields_attributes'])
				);
				$changed = true;
			}

			if ($changed) {
				// refresh the viewer so the returned entity carries the change
				$this->viewer = $this->refreshedViewer();
			}

			return new DataResponse($this->accountEntity($this->viewer), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
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

		if (($data['last_status_at'] ?? null) === '') {
			$data['last_status_at'] = null;
		}

		$placeholder = null;
		foreach (['avatar', 'avatar_static', 'header', 'header_static'] as $image) {
			if (($data[$image] ?? null) !== '') {
				continue;
			}

			$placeholder ??= $this->placeholderImage($account);
			$data[$image] = $placeholder;
		}

		return $data;
	}

	/**
	 * The picture shown for an account that has none cached yet: for a local
	 * account Nextcloud's own avatar, which every user has (generated from the
	 * initials when nothing was uploaded), the app icon otherwise.
	 */
	private function placeholderImage(Person $account): string {
		if ($account->isLocal()) {
			return $this->urlGenerator->linkToRouteAbsolute(
				'core.avatar.getAvatar', ['userId' => $account->getPreferredUsername(), 'size' => 128]
			);
		}

		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'social.svg')
		);
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

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/polls/{nid}')]
	public function pollGet(int|string $nid): DataResponse {
		try {
			$this->initViewer(true);

			$poll = $this->pollService->getPoll($nid, $this->viewer);

			return new DataResponse($this->pollService->exportPoll($poll), Http::STATUS_OK);
		} catch (StreamNotFoundException $e) {
			return new DataResponse(['error' => 'poll not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Votes on a poll. On a poll from another server the choices go to its
	 * author as ActivityPub vote notes and the authoritative counts come back
	 * later as an Update from the origin; on a poll of this instance's own the
	 * vote is counted here and the new counts go out to the author's followers.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/polls/{nid}/votes')]
	public function pollVote(int|string $nid): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$choices = $input['choices'] ?? [];
			if (!is_array($choices)) {
				$choices = [$choices];
			}

			$poll = $this->pollService->vote($this->viewer, $nid, $choices);

			return new DataResponse($this->pollService->exportPoll($poll), Http::STATUS_OK);
		} catch (StreamNotFoundException $e) {
			return new DataResponse(['error' => 'poll not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidActionException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Files a moderation report about an account (and optionally some of its
	 * statuses) for the instance admins.
	 *
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/reports')]
	public function reportNew(): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$accountId = (string)($input['account_id'] ?? '');
			if ($accountId === '') {
				return new DataResponse(['error' => 'account_id is required'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$target = $this->cacheActorService->resolve($accountId, $this->viewer !== null);
			if ($target->getId() === $this->viewer->getId()) {
				return new DataResponse(['error' => 'you cannot report yourself'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$statusIds = $input['status_ids'] ?? [];
			if (!is_array($statusIds)) {
				$statusIds = [$statusIds];
			}
			$statusIds = array_map('strval', $statusIds);

			$report = $this->reportService->reportFromLocal(
				$this->viewer,
				$target,
				$statusIds,
				(string)($input['comment'] ?? ''),
				(string)($input['category'] ?? Report::CATEGORY_OTHER),
				// Mastodon's `forward`: the report also goes to the instance
				// that hosts the account, which is the only one that can act
				// on it. Ignored for a local account -- ReportForwardService
				// decides, so no entry point can forward what must not be
				$this->formBool($input['forward'] ?? false)
			);
			$target->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($report, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
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
	 * The emoji this instance publishes.
	 *
	 * Answered `[]` unconditionally until the instance had any: emoji from
	 * every other server rendered here and this one could publish none, which
	 * is the asymmetry somebody moving here notices first. Only the ones
	 * marked visible are listed — that is what the field means, and a picker
	 * is what reads this route.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/custom_emojis')]
	public function customEmojis(): JSONResponse {
		return Revalidation::byContent(
			$this->request, new DataResponse($this->emojiService->visible(), Http::STATUS_OK)
		);
	}

	/**
	 * The picture behind a shortcode.
	 *
	 * Unauthenticated, like `mediaOpen()` and for the same reason: this is
	 * what a remote server dereferences out of an `Emoji` tag on a post it
	 * received, and it has no token of ours to present. An emoji is published
	 * by definition — it is on every post that uses it, everywhere that post
	 * went — so there is nothing here to keep from anybody.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/emoji/{shortcode}')]
	public function emojiOpen(string $shortcode): Response {
		try {
			$emoji = $this->emojiService->byShortcode($shortcode);
			if ($emoji === null) {
				return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
			}

			$response = new FileDisplayResponse(
				$this->emojiService->picture($shortcode),
				Http::STATUS_OK,
				['Content-Type' => $emoji->getMediaType()]
			);
			// the shortcode names one picture and replacing it is a deliberate
			// act, so a day is cheap; a shared cache may keep it, since the
			// route answers everybody the same bytes
			$response->cacheFor(86400, false, true);

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The instance's GIF library, or the part of it that matches `q`.
	 *
	 * A viewer is required: this is a picker inside the composer, not
	 * something the public page needs, and there is no reason to hand the
	 * whole library to anybody who asks.
	 *
	 * Paged, which it did not need to be while the library was whatever an
	 * administrator had added: with the animated emoji in it there are 881,
	 * and a grid of 881 is 881 pictures this instance would go and fetch
	 * because somebody opened the picker. `limit` is how many to answer with
	 * and `offset` where to carry on from, so the picker asks for the next
	 * screenful when the reader scrolls to it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/gifs')]
	public function gifs(string $q = '', int $limit = 24, int $offset = 0): DataResponse {
		try {
			$this->initViewer(true);

			$limit = max(1, min(200, $limit));
			$found = $this->gifService->search($q);

			return new DataResponse([
				'gifs' => array_slice($found, max(0, $offset), $limit),
				'total' => count($found),
				// said wherever the pack is shown, because CC BY asks for it
				'attribution' => $this->gifService->attribution(),
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The bytes behind a slug.
	 *
	 * Unauthenticated, like `emojiOpen()`: the picker draws a grid of these
	 * and they are the same bytes for everybody on the instance. Nothing here
	 * is private — a library picture is one an administrator put there for
	 * everybody — and requiring a session would mean the grid could not be
	 * cached by anything.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/gif/{slug}')]
	public function gifOpen(string $slug): Response {
		try {
			// Nothing here reads the session, and holding it is what made the
			// picker unusable: PHP serialises requests that hold one, so the
			// sixty thumbnails a picker draws went out one at a time — and for
			// one of the shipped emoji this instance has not seen, each of
			// those is a fetch from Google. Measured on a cold instance, the
			// search that followed them waited forty seconds for its turn.
			// Letting it go makes them parallel and costs nothing: these are
			// the same bytes for everybody, which is why the route is public.
			$this->session->close();

			$gif = $this->gifService->bySlug($slug);
			if ($gif === null) {
				return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
			}

			$response = new FileDisplayResponse(
				$this->gifService->file($slug),
				Http::STATUS_OK,
				['Content-Type' => $gif->getMediaType()]
			);
			// the slug names one picture and replacing it is a deliberate act,
			// so a day is cheap; a shared cache may keep it, since the route
			// answers everybody the same bytes
			$response->cacheFor(86400, false, true);

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Attaches a picture from the instance's library to a post being written.
	 *
	 * A copy, through the same `storeAttachment()` an upload and a Files pick
	 * go through — so the sniffing, the size guard and the resizing are one
	 * path rather than three that can drift. A copy rather than a reference
	 * for the reason `mediaFromFile()` gives: a post keeps the picture it was
	 * published with, and an administrator removing something from the library
	 * must not empty a post that has already federated.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/media/from-gif')]
	public function mediaFromGif(): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$slug = trim((string)($input['slug'] ?? $this->request->getParam('slug', '')));
			if ($slug === '') {
				throw new InvalidActionException('no picture named');
			}

			$gif = $this->gifService->bySlug($slug);
			if ($gif === null) {
				throw new InvalidActionException('no such picture');
			}

			$file = $this->gifService->file($slug);
			$this->refuseOversized($file->getSize(), $gif->getMediaType());

			$tmpPath = $this->tempManager->getTemporaryFile();
			if ($tmpPath === false) {
				throw new InvalidActionException('no temporary file to copy into');
			}

			if (file_put_contents($tmpPath, $file->getContent()) === false) {
				throw new InvalidActionException('the picture could not be copied');
			}

			// the title is the description unless the writer gave one: a
			// library picture arriving with no alt text at all is the thing
			// the ALT badge exists to complain about
			$description = trim((string)($input['description'] ?? $this->request->getParam('description', '')));
			if ($description === '') {
				$description = $gif->getTitle();
			}

			return new DataResponse(
				$this->storeAttachment($tmpPath, $description, '', $gif->getFilename()),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/saved_searches/list.json')]
	public function savedSearches(): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses')]
	public function statusNew(): DataResponse {
		try {
			$this->initViewer(true);

			$input = file_get_contents('php://input');
			$this->logger->debug('[' . static::class . '] statusNew: ' . $input);

			$data = $this->convertInput($input);
			$status = new Status();
			$status->import($data);

			// A `scheduled_at` is not a slow post: Mastodon answers it with a
			// ScheduledStatus entity and publishes nothing until the time
			// comes. The field used to be parsed and ignored, so a post
			// scheduled for next Tuesday went out at once -- and the client was
			// told it had been scheduled. This sits before the idempotency
			// lookup, which remembers a published status by nid and has nothing
			// to remember here.
			if ($this->scheduledStatusService->requestedTime($data) > 0) {
				return new DataResponse(
					$this->scheduledStatusService->schedule(
						$this->accountService->getActorFromUserId($this->currentSession()),
						$status,
						$data
					),
					Http::STATUS_OK
				);
			}

			// Tusky and Ivory send an Idempotency-Key and retry the post when
			// the connection drops, so on a flaky mobile link the same post used
			// to be created — and federated to every follower — several times.
			$idempotencyKey = $this->idempotencyKey();
			$already = $this->statusForIdempotencyKey($idempotencyKey);
			if ($already !== null) {
				return new DataResponse($already, Http::STATUS_OK);
			}

			// Use the viewer that was already initialized
			$author = $this->accountService->getActorFromUserId($this->currentSession());

			// A post written as a team is attributed to the team's account and
			// signed with its key: the team speaks, not the person at the
			// keyboard. Who that person was is recorded below rather than
			// published — outside the team, one voice is the point of having a
			// team account; inside it, and to a moderator, the trail is.
			//
			// A handle that is not a team account of this instance, and one
			// whose group this account is not in, are the same 404: which
			// groups exist and what they post as is not a thing to confirm to
			// somebody outside them.
			$actor = $author;
			$postAs = $status->getPostAs();
			if ($postAs !== '') {
				$actor = $this->teamService->assertMayPostAs($this->currentSession(), $postAs);
			}

			$post = new Post($actor);
			$post->setContent($status->getStatus());
			$post->setPoll($status->getPoll());
			$post->setSpoilerText($status->getSpoilerText());
			$post->setSensitive($status->isSensitive());
			$post->setType($this->visibilityOf($status));
			$post->setLanguage($status->getLanguage());
			$post->setPlaceId(
				$this->placeService->resolve(
					$status->getPlaceId(),
					$status->getPlaceName(),
					$status->getPlaceCountry(),
					$status->getPlaceLat(),
					$status->getPlaceLon()
				)?->getId() ?? 0
			);

			if (!empty($status->getMediaIds())) {
				// the uploader's own media, not the team's: an upload belongs
				// to the person who made it whatever account the post ends up
				// attributed to, and looking it up as the team would find
				// nothing
				$documents = $this->documentService->getMediaFromArray(
					$status->getMediaIds(),
					$this->viewer->getPreferredUsername()
				);
				$this->scopeMediaToVisibility($documents, $post->getType());
				$post->setMedias(
					array_map(function (Document $document): MediaAttachment {
						return $document->convertToMediaAttachment(
							$this->urlGenerator,
							ACore::FORMAT_ACTIVITYPUB
						);
					}, $documents)
				);
			}

			if ($status->getInReplyToId() > 0) {
				try {
					$replyTo = $this->streamService->getStreamByNid($status->getInReplyToId());
					$post->setReplyTo($replyTo->getId());
				} catch (StreamNotFoundException $e) {
					$this->logger->debug('reply to post not found');
				}
			}

			$post->setQuotedId($status->getQuotedId());
			$post->setQuotePolicy($status->getQuotePolicy());
			$post->setVideoMeta($status->getVideoMeta());

			// Before anything is written: a post a rule holds is stored as a
			// request and never reaches `social_stream`, so there is no row for
			// a timeline to find. The 422 is what a client can be told with the
			// vocabulary Mastodon's API has — `held_for_review` beside it is
			// what this app's own composer reads to say something better than
			// "failed".
			// assessed against the person who wrote it, not the team: first-post
			// review is about an account nobody has vouched for yet, and a team
			// account exists because an administrator made it
			$reason = $this->postReviewService->assess(
				$author,
				$post->getContent(),
				$post->getType(),
				// asked of the attachments that were actually resolved, not of
				// the ids the client sent: an id that named nothing, or
				// somebody else's upload, is not a video on this post
				PeerTubeService::soleVideo($post->getMedias()) !== null
			);
			if ($reason !== '') {
				$held = $this->postReviewService->hold(
					$author, $this->postReviewService->paramsOf($status, $post->getType()), $reason
				);

				return new DataResponse([
					'error' => 'This post is waiting for a moderator to look at it. '
						. 'It has been kept — there is no need to write it again.',
					'held_for_review' => true,
					'reason' => $held->getReason(),
					'held_post' => $held,
				], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$activity = $this->postService->createPost($post);

			if ($postAs !== '') {
				$this->teamService->recordAuthor($activity->getObjectId(), $author);
			}

			$item = $this->streamService->getStreamById(
				$activity->getObjectId(),
				true,
				ACore::FORMAT_LOCAL
			);

			$this->rememberIdempotencyKey($idempotencyKey, $item->getNid());

			$this->logger->info('[' . static::class . '] Status created successfully', [
				'postId' => $activity->getObjectId()
			]);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The visibility a new status is posted with.
	 *
	 * A client that leaves the field out means "whatever this account posts
	 * with", which is the account's own default — `source.privacy`, set through
	 * `/api/v1/accounts/update_credentials` and `public` until someone changes
	 * it. Anything this app does not know is refused rather than posted:
	 * `Stream::visibilityFromClient()` maps an unknown value to `direct`, and a
	 * direct message gets no recipient added, so those posts used to answer 200
	 * and be delivered to nobody.
	 *
	 * @throws InvalidActionException
	 */
	private function visibilityOf(Status $status): string {
		$visibility = trim($status->getVisibility());
		if ($visibility === '') {
			return $this->accountService->getDefaultPrivacy($this->currentSession());
		}

		if (!Stream::isKnownClientVisibility($visibility)) {
			throw new InvalidActionException('unknown visibility: ' . $visibility);
		}

		return $visibility;
	}

	/**
	 * Records on a post's attachments whether the post itself is world-readable.
	 *
	 * The `public` flag on a cached document is a hint about the audience, not
	 * access control: `/media/{uuid}` serves any local copy to whoever holds its
	 * unguessable uuid, the way Mastodon does (see `mediaOpen()`), because that
	 * is how remote servers fetch attachments for their own readers. What the
	 * flag decides is how the bytes may be cached on the way — a shared proxy
	 * may keep a public attachment, only the reader's browser a non-public one.
	 * Which post an upload belongs to is only known when that post is created,
	 * which is where this runs.
	 *
	 * @param Document[] $documents
	 */
	private function scopeMediaToVisibility(array $documents, string $visibility): void {
		$public = in_array($visibility, [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true);

		foreach ($documents as $document) {
			if ($document->isPublic() === $public) {
				continue;
			}

			$document->setPublic($public);
			$this->cacheDocumentsRequest->update($document);
		}
	}

	/**
	 * This request's Idempotency-Key, scoped to whoever sent it.
	 *
	 * The key is only meaningful together with the credential that used it —
	 * two clients are free to pick the same one — so what is stored is a digest
	 * of both, which also keeps the bearer token itself out of the cache.
	 */
	private function idempotencyKey(): string {
		$key = trim($this->request->getHeader('Idempotency-Key'));
		if ($key === '') {
			return '';
		}

		$owner = ($this->bearer !== '') ? $this->bearer : (string)$this->viewer?->getId();

		return hash('sha256', $owner . '|' . $key);
	}

	/**
	 * The status a previous request with this Idempotency-Key created, if it is
	 * still on record and still exists.
	 */
	private function statusForIdempotencyKey(string $key): ?Stream {
		if ($key === '') {
			return null;
		}

		$nid = $this->durableCache->get(self::IDEMPOTENCY_CACHE, $key);
		if ((!is_string($nid) && !is_int($nid)) || !ctype_digit((string)$nid) || \OCA\Social\Tools\Nid::compare($nid, '0') < 1) {
			return null;
		}

		try {
			$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
		} catch (Exception $e) {
			// deleted since, or never really written: let the post go through
			return null;
		}

		$item->setExportFormat(ACore::FORMAT_LOCAL);

		return $item;
	}

	private function rememberIdempotencyKey(string $key, int|string $nid): void {
		if ($key === '' || $nid < 1) {
			return;
		}

		// in `DurableCache`, so a retried post is still recognised on an
		// instance with no memory cache
		$this->durableCache->set(self::IDEMPOTENCY_CACHE, $key, (string)$nid, self::IDEMPOTENCY_TTL);
	}

	/**
	 *
	 * @param int|string $nid
	 *
	 * @return DataResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/statuses/{nid}')]
	public function statusUpdate(int|string $nid): DataResponse {
		try {
			$this->initViewer(true);

			$input = file_get_contents('php://input');
			$status = new Status();
			$status->import($this->convertInput($input));

			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			$item = $this->postService->editPost(
				$nid,
				$actor,
				$status->getStatus(),
				$status->getSpoilerText() !== '' ? $status->getSpoilerText() : null,
				$status->isSensitive(),
				$status->getLanguage() !== '' ? $status->getLanguage() : null
			);
			$item->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param string $timeline
	 * @param bool $local
	 * @param int $limit
	 * @param int|string $max_id
	 * @param int|string $min_id
	 * @param int|string $since_id
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/{timeline}/')]
	public function timelines(
		string $timeline,
		bool $local = false,
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
		bool $only_media = false,
		bool $only_video = false,
		bool $only_news = false,
	): Response {
		$this->logger->debug('[' . static::class . '] timelines called', [
			'timeline' => $timeline,
			'local' => $local,
			'limit' => $limit,
			'max_id' => $max_id,
			'min_id' => $min_id,
			'since_id' => $since_id
		]);
		try {
			// Mastodon's public timeline is readable without a token, and a
			// client asks for it before it has one — to show the reader what is
			// here before they log in. Every other timeline is about somebody,
			// so it still needs a viewer. A token that *was* presented still has
			// to be a good one: a client whose token has been revoked has to
			// learn that, not quietly get the anonymous view instead.
			$this->initViewer(
				$this->bearer !== '' || strtolower($timeline) !== ProbeOptions::PUBLIC
			);
			$this->logger->debug('[' . static::class . '] Viewer initialized', [
				'viewerId' => $this->viewer?->getId()
			]);

			if (!in_array(
				strtolower($timeline),
				[
					ProbeOptions::HOME,
					ProbeOptions::ACCOUNT,
					ProbeOptions::PUBLIC,
					ProbeOptions::DIRECT,
					ProbeOptions::FAVOURITES
				]
			)) {
				$this->logger->error('[' . static::class . '] Unknown timeline requested', [
					'timeline' => $timeline
				]);
				throw new UnknownProbeException('unknown timeline');
			}

			// A client polls this every thirty seconds and the answer is
			// almost always the one it already holds. The newest id the viewer
			// can see changes exactly when that stops being true, and costs one
			// index-only probe — see `notModified()`. Only the head of the home
			// timeline is worth tagging: a page reached with `max_id` is
			// historical and a client asks for it once.
			if ($timeline === ProbeOptions::HOME && $max_id === '0' && $min_id === '0') {
				// the newest id says whether anything arrived; the revision
				// says whether the reader has changed what they are shown —
				// a follow, a block, a mute, a filter, a followed hashtag —
				// none of which moves an id. See TimelineRevisionService.
				$notModified = $this->notModified(
					'h' . (($this->viewer === null) ? '0' : $this->streamRequest->newestHomeNid($this->viewer))
					. '-' . $limit
					. '-' . $this->timelineRevisionService->of($this->currentSession())
				);
				if ($notModified !== null) {
					return $notModified;
				}
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe($timeline)
				->setLocal($local)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setOnlyMedia($only_media)
				->setOnlyVideo($only_video)
				->setOnlyNews($only_news);

			$posts = $this->streamService->getTimeline($options);
			$this->logger->debug('[' . static::class . '] Timeline retrieved', [
				'timeline' => $timeline,
				'postsCount' => count($posts)
			]);

			// the unfiltered page is what says whether a further page exists: a
			// page shortened by a `hide` filter says nothing about what is older
			return $this->tagged($this->paged(
				$this->filterService->apply($posts, $this->filterContext($timeline), $this->viewer),
				$options->getLimit(),
				$posts,
				$this->streamService->lastTimelineRowCount()
			));
		} catch (Throwable $e) {
			$this->logger->error('[' . static::class . '] Timeline request failed', [
				'timeline' => $timeline,
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param int|string $nid
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}')]
	public function statusGet(int|string $nid): DataResponse {
		try {
			$this->initViewer(false);

			$item = $this->streamService->attachCard($this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid)));
			$item->setExportFormat(ACore::FORMAT_LOCAL);
			// opening a post's own page is the one thing this app counts as
			// having read it: not an impression in a timeline, which is a post
			// scrolled past rather than read
			$this->viewCountService->seen($item, $this->viewer);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The link preview of one status, on its own.
	 *
	 * The card is already inlined in the status entity, which is what most
	 * clients read; this route was a **405** rather than a 404, because the
	 * path matched the POST-only action route below and nothing answered a
	 * GET. A status with no link answers `{}` — Mastodon's own answer, and not
	 * an error: most statuses have no card.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/card')]
	public function statusCard(int|string $nid): DataResponse {
		try {
			$this->initViewer(false);

			$card = $this->streamService
				->attachCard($this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid)))
				->getCard();

			return new DataResponse($card ?? (object)[], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param int|string $nid
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/context')]
	public function statusContext(int|string $nid): DataResponse {
		try {
			$this->initViewer(false);
			$context = $this->streamService->getContextByNid($nid);

			return new DataResponse(
				[
					'ancestors' => $this->filterService->apply(
						$context['ancestors'] ?? [], Filter::CONTEXT_THREAD, $this->viewer
					),
					'descendants' => $this->filterService->apply(
						$context['descendants'] ?? [], Filter::CONTEXT_THREAD, $this->viewer
					),
				],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Deletes one of the viewer's own statuses.
	 *
	 * The app's own frontend has always had a delete (`DELETE /api/v1/post`,
	 * behind the session and a CSRF token), but a client holding a bearer token
	 * had no way to reach it: the route Mastodon deletes with did not exist, so
	 * every client's delete button failed. Mastodon answers with the status that
	 * was removed — that is what "delete & redraft" puts back in the composer.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/statuses/{nid}')]
	public function statusDelete(int|string $nid): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
			if ($item->getAttributedTo() !== $actor->getId()) {
				// the same answer an unknown id gets: whether somebody else's
				// post exists is not this route's to tell
				throw new StreamNotFoundException('Stream not found');
			}

			// exported before the delete, while the row is still there to read
			$item->setExportFormat(ACore::FORMAT_LOCAL);
			$deleted = $item->exportAsLocal();

			$this->streamService->deleteLocalItem($item, $item->getType());

			return new DataResponse($deleted, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The text of one of the viewer's own statuses, as it was written.
	 *
	 * Mastodon's StatusSource entity. Tusky and Ivory will not offer an edit
	 * button without it, even though `PUT /api/v1/statuses/{id}` has worked
	 * here all along: they fetch the source first, to have something to put in
	 * the editor. The stored content is the HTML that was rendered from the
	 * original text, so it is turned back: the line breaks that `nl2br()` wrote
	 * become newlines again and the entities are decoded.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/source')]
	public function statusSource(int|string $nid): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
			if ($item->getAttributedTo() !== $actor->getId()) {
				throw new StreamNotFoundException('Stream not found');
			}

			return new DataResponse(
				[
					'id' => (string)$item->getNid(),
					'text' => $this->asSourceText($item->getContent()),
					'spoiler_text' => $item->getSpoilerText(),
				], Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Where one of the viewer's own posts got to: `/api/v1/statuses/{nid}/delivery`.
	 *
	 * This app's own route, not Mastodon's. When a post does not appear on
	 * another server the author has no way of knowing whether it was sent, is
	 * still queued, was refused or was given up on; the queue knows, and this is
	 * the author asking it. Answered only for the author, as `/source` is --
	 * which servers a post reached is a fact about their own account and nobody
	 * else's -- and a 404 for anybody else, the same 404 as a post that does not
	 * exist.
	 *
	 * The answer is as good as the retention: a delivered request is kept for
	 * `RequestQueueService::RETENTION_SECONDS` and then purged, so the reply says
	 * how long, and an old post reports nothing rather than reporting wrongly.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/delivery')]
	public function statusDelivery(int|string $nid): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
			if ($item->getAttributedTo() !== $actor->getId()) {
				throw new StreamNotFoundException('Stream not found');
			}

			return new DataResponse(
				['id' => (string)$item->getNid()] + $this->deliveryService->forObject($item->getId()),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The rendered content of a status, back as close to its source as it goes. */
	private function asSourceText(string $content): string {
		$text = preg_replace('#<br\s*/?>#i', "\n", $content);
		$text = strip_tags((string)$text);

		return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/**
	 * Reacts to a status with an emoji.
	 *
	 * **Declared before `statusAction()` on purpose.** That method's route is
	 * `POST /api/v1/statuses/{nid}/{act}`, which matches this path too; within
	 * one controller the attribute routes are offered to the matcher in
	 * method-declaration order, so this one has to come first or it is never
	 * reached. Moving it below `statusAction()` would turn a reaction into an
	 * unknown action, which is a **400** and looks like a client bug.
	 *
	 * The emoji is a parameter rather than a path segment: one emoji can be a
	 * long grapheme cluster, and percent-encoding a family-with-skin-tones
	 * into a URL to get it back out again is a round trip with nothing to gain.
	 *
	 * Answers the status, so a client redraws the card from one response.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/react')]
	public function statusReact(int|string $nid, string $emoji = ''): DataResponse {
		return $this->react($nid, $emoji, true);
	}

	/** Takes a reaction back. Declared before `statusAction()` for the same reason. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/unreact')]
	public function statusUnreact(int|string $nid, string $emoji = ''): DataResponse {
		return $this->react($nid, $emoji, false);
	}

	private function react(int|string $nid, string $emoji, bool $add): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActor($this->viewer->getPreferredUsername());
			$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));

			if ($add) {
				$this->reactionService->create($actor, $post->getId(), $emoji);
			} else {
				$this->reactionService->delete($actor, $post->getId(), $emoji);
			}

			// read back rather than patched in memory, so the count the client
			// redraws is the one the next reader will be served
			$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
			$item->setReactions($this->reactionSummaryService->summaryOf($item->getId(), $actor->getId()));
			$item->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The emoji reactions on a status: each emoji, how many used it, and
	 * whether the caller is one of them.
	 *
	 * The status is resolved through the visibility filter first, so one the
	 * caller may not read is a **404** and none of its reactions is looked at
	 * — who reacted to a post is as private as the post, the same rule
	 * `favourited_by` follows.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/reactions')]
	public function statusReactions(int|string $nid): DataResponse {
		try {
			$this->initViewer(false);
			$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));

			return new DataResponse(
				$this->reactionSummaryService->summaryOf($post->getId(), $this->viewer?->getId() ?? ''),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * One status, in the reader's language.
	 *
	 * Declared before `statusAction()`, which routes
	 * `/api/v1/statuses/{nid}/{act}` and would otherwise match this path
	 * first: within a controller the order the methods are written in is the
	 * order the routes are tried in.
	 *
	 * Answers Mastodon's Translation entity — not a Status. The two are
	 * different things on purpose: a translation has no id, no author and no
	 * counters, and a client shows it under the post rather than in place of
	 * it.
	 *
	 * What translates it is whatever translation provider this Nextcloud has;
	 * an instance with none says so in `configuration.translation.enabled` and
	 * answers this with a 503, which is what Mastodon answers when its own
	 * provider is unavailable. It never answers with the original text: that
	 * is what this route used to do, and a reader could not tell.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/translate')]
	public function statusTranslate(int|string $nid, string $lang = ''): DataResponse {
		try {
			$this->initViewer(true);
			$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));

			// the language asked for, else the one this reader reads Nextcloud
			// in: a client that offers "translate" without a language picker
			// means "into mine"
			$target = ($lang !== '')
				? $lang
				: $this->l10nFactory->getUserLanguage($this->userSession->getUser());

			return new DataResponse(
				$this->translationService->translateStatus(
					$post, $target, $this->userSession->getUser()?->getUID()
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// --- where somebody stopped watching ----------------------------------

	/**
	 * Remembers where the reader got to in a video.
	 *
	 * A two-hour talk watched in three sittings is three sittings of finding
	 * the place again, which is what this is for. It is a fact about the
	 * reader: never federated, never shown to anybody else, never counted into
	 * anything.
	 *
	 * Declared ahead of `statusAction()`, whose `/api/v1/statuses/{nid}/{act}`
	 * matches this path too: the routes of one controller are matched in
	 * declaration order, and a trait's methods come after the class's own.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// a player reports as it goes, so this is asked for often and is cheap
	#[AnonRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/watched')]
	public function statusWatched(int|string $nid, int $position = 0, int $duration = 0): DataResponse {
		try {
			$this->initViewer(true);
			$post = $this->streamService->getStreamByNid($nid);
			$this->watchService->remember($post, $this->viewer, $position, $duration);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Takes a video off the reader's own "continue watching" list. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[UserRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/statuses/{nid}/watched')]
	public function statusUnwatched(int|string $nid): DataResponse {
		try {
			$this->initViewer(true);
			$post = $this->streamService->getStreamByNid($nid);
			$this->watchService->forget($post, $this->viewer);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param int|string $nid
	 * @param string $action
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/{act}')]
	public function statusAction(int|string $nid, string $act): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActor($this->viewer->getPreferredUsername());
			$item = $this->actionService->action($actor, $nid, $act);

			if ($item === null) {
				$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
			}

			$item->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The posts this account has asked to have published later, soonest first.
	 *
	 * No `Link` header: a ScheduledStatus is not a Stream and carries no nid
	 * for `paged()` to page on. The three cursors are honoured, so a client
	 * that builds its own still pages.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/scheduled_statuses')]
	public function scheduledStatuses(
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			return new DataResponse(
				$this->scheduledStatusService->getAll($actor, $limit, $max_id, $min_id, $since_id),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One waiting post. One that is not the viewer's is a 404, not a refusal. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/scheduled_statuses/{id}')]
	public function scheduledStatusGet(int $id): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			return new DataResponse($this->scheduledStatusService->getOne($actor, $id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Moves a waiting post to another time; the five-minute rule applies again. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/scheduled_statuses/{id}')]
	public function scheduledStatusUpdate(int $id): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());
			$data = $this->convertInput(file_get_contents('php://input'));

			return new DataResponse(
				$this->scheduledStatusService->reschedule($actor, $id, $data), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Cancels a waiting post. Mastodon answers an empty object. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/scheduled_statuses/{id}')]
	public function scheduledStatusDelete(int $id): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());
			$this->scheduledStatusService->delete($actor, $id);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The accounts that favourited a status, newest first.
	 *
	 * Mastodon's `favourited_by`, and the reason a tap on a favourite count is
	 * not a dead end. The status is resolved through the visibility filter
	 * first, so one the caller may not read is a **404** and no reaction of it
	 * is looked at — the list of who liked a post is as private as the post.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/favourited_by')]
	public function statusFavouritedBy(int|string $nid, int $limit = 40): DataResponse {
		return $this->reactedBy($nid, Like::TYPE, $limit);
	}

	/**
	 * The posts that quote one status, newest first — Mastodon 4.5's
	 * `GET /api/v1/statuses/{id}/quotes`.
	 *
	 * The posts this server *holds*: a local quote, and a remote one that
	 * reached somebody here. A quote written on a server nobody here follows
	 * was approved and is real and is not in this list, because there is no
	 * status entity to put in it — Mastodon's own answer has the same edge.
	 * Read as the viewer, so a quote inside somebody's followers-only post is
	 * not handed to a reader by a list about their own post.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/quotes')]
	public function statusQuotes(int|string $nid, int $limit = 20, int|string $max_id = 0): DataResponse {
		try {
			$this->initViewer(false);
			$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));

			$quotes = $this->quoteService->quotesOf($post, $limit, $max_id);
			foreach ($quotes as $quote) {
				$quote->setExportFormat(ACore::FORMAT_LOCAL);
			}

			return new DataResponse($quotes, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Who may quote one of the caller's own posts — Mastodon 4.5's
	 * `PUT /api/v1/statuses/{id}/interaction_policy`.
	 *
	 * `quote_approval_policy` is `public`, `followers` or `nobody`. It decides
	 * what happens to requests that arrive **from now on**; it does not reach
	 * back and withdraw the permissions already given, because a quote that
	 * has been published and read is not undone by a switch being flipped.
	 * Taking one back is the route below, which says so and tells the other
	 * server.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[UserRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/statuses/{nid}/interaction_policy')]
	public function statusInteractionPolicy(int|string $nid): DataResponse {
		try {
			$this->initViewer(true);

			$status = new Status();
			$status->import($this->convertInput((string)file_get_contents('php://input')));

			$actor = $this->accountService->getActorFromUserId($this->currentSession());
			$item = $this->quoteService->setPolicy($nid, $actor, $status->getQuotePolicy());
			$item->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Takes one quote of one of the caller's own posts back — Mastodon 4.5's
	 * `POST /api/v1/statuses/{id}/quotes/{quoting}/revoke`.
	 *
	 * A `Reject` naming the request that was accepted goes to the quoting
	 * server, which is how FEP-044f withdraws a permission; the quote then
	 * shows there as revoked rather than refused. A local quote is withdrawn
	 * here directly, because there is nobody to tell.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[UserRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/quotes/{quoting}/revoke')]
	public function statusQuoteRevoke(int|string $nid, int|string $quoting): DataResponse {
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			if (!$this->quoteService->revoke($nid, $actor, $quoting)) {
				return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The accounts that boosted a status, newest first. Mastodon's `reblogged_by`. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/reblogged_by')]
	public function statusRebloggedBy(int|string $nid, int $limit = 40): DataResponse {
		return $this->reactedBy($nid, Announce::TYPE, $limit);
	}

	private function reactedBy(int|string $nid, string $type, int $limit): DataResponse {
		try {
			$this->initViewer(false);
			$limit = min(max($limit, 1), 80);
			$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));

			return new DataResponse(
				$this->actionService->reactedBy($post, $type, $limit), Http::STATUS_OK
			);
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
	 * The instances this one has heard of.
	 *
	 * Mastodon's `/api/v1/instance/peers`, which instance browsers and
	 * "about this server" pages read. A bare array of hostnames, which is what
	 * the peer of every cached remote actor amounts to, and the same walk the
	 * `domain_count` statistic uses — two walks would be two answers.
	 *
	 * Public, as Mastodon's is: it says who this instance federates with, not
	 * who its users are.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/peers')]
	public function instancePeers(): DataResponse {
		try {
			return new DataResponse($this->instanceService->getPeers(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's weekly activity series: twelve weeks of statuses, logins and
	 * registrations.
	 *
	 * `registrations` is always `0` and says so in the docs: an account here is
	 * a Nextcloud user, created by the server rather than by this app, so there
	 * is no registration for it to count. `logins` is likewise not this app's
	 * to know. What it does know is how many statuses were published in a week,
	 * which is the series a client actually plots.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/activity')]
	public function instanceActivity(): DataResponse {
		try {
			return new DataResponse($this->instanceService->getWeeklyActivity(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
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

	/**
	 * Mastodon's v1 search, which is the v2 one without `statuses` being
	 * optional. `/api/v1/search` used to be the app's own web-UI search — a
	 * Nextcloud envelope, `content` where a client looks for `statuses`, `search=`
	 * where a client sends `q=`, and no bearer token accepted — so a client got
	 * a 200 it could make no sense of, which is worse than a 404. The web UI's
	 * own search goes through `/api/v2/search` like any client's.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/search')]
	public function search(string $q = '', string $type = '', int $limit = 20, bool $resolve = false): DataResponse {
		return $this->searchV2($q, $type, $limit, $resolve);
	}

	/**
	 * Mastodon's search endpoint: accounts, statuses (the viewer-bounded
	 * full-text search) and hashtags, optionally narrowed with `type`.
	 *
	 * `resolve` is accepted and ignored on purpose: it asks the server to go
	 * and fetch an account or status it has never seen, and every account
	 * search here is already `LIKE '%term%'` over the actor cache. Following an
	 * unknown handle up remotely on an anonymous request would make this route
	 * an outbound-fetch amplifier.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/search')]
	public function searchV2(string $q = '', string $type = '', int $limit = 20, bool $resolve = false): DataResponse {
		try {
			$this->initViewer(true);
			$q = trim($q);
			$limit = min(max($limit, 1), 40);

			$accounts = [];
			if ($type === '' || $type === 'accounts') {
				$found = array_merge(
					$this->searchService->searchUri($q),
					$this->searchService->searchAccounts($q)
				);
				$unique = [];
				foreach ($found as $account) {
					$unique[$account->getId()] = $account->setExportFormat(ACore::FORMAT_LOCAL);
				}
				$accounts = array_slice(array_values($unique), 0, $limit);
			}

			$statuses = [];
			if ($type === '' || $type === 'statuses') {
				$statuses = array_slice($this->searchService->searchStreamContent($q), 0, $limit);

				// `resolve` is the reader saying "I have a link, go and get
				// it". Without it a post found in a browser cannot be replied
				// to or boosted here, because nothing has ever had a reason to
				// ask its server for it. Only on the reader's say-so: this
				// fetches an address they chose.
				if ($resolve && $statuses === []) {
					// as the reader: the content search above is viewer-scoped,
					// so a post they may not see finds nothing there and falls
					// through to here — which used to hand it over in full
					$resolved = $this->searchService->resolveStatus($q, true);
					if ($resolved !== null) {
						$resolved->setExportFormat(ACore::FORMAT_LOCAL);
						$statuses = [$resolved];
					}
				}
			}

			$hashtags = [];
			if ($type === '' || $type === 'hashtags') {
				foreach (array_slice($this->searchService->searchHashtags($q), 0, $limit) as $hashtag) {
					$hashtags[] = [
						'name' => $hashtag['hashtag'],
						'url' => $this->urlGenerator->linkToRouteAbsolute(
							'social.Navigation.timeline', ['path' => 'tags/' . $hashtag['hashtag']]
						),
						'history' => [],
					];
				}
			}

			return new DataResponse(
				['accounts' => $accounts, 'statuses' => $statuses, 'hashtags' => $hashtags],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The hashtags used most on this instance lately, as Mastodon's Tag
	 * entities. The counts come from the trend the cron already keeps for
	 * every hashtag; this instance counts uses rather than distinct accounts,
	 * so `accounts` is always 0.
	 *
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/trends/tags')]
	public function trendTags(int $limit = 10, string $period = HashtagService::PERIOD_DEFAULT): Response {
		try {
			$this->initViewer(false);
			$limit = max(1, min(20, $limit));

			// the same builder the tag lookup and the follow answers use, so a
			// Tag entity cannot mean one thing here and another there;
			// `following` is left out, as it must be on a public route
			$tags = [];
			foreach ($this->hashtagService->getTrending($limit, $period) as $hashtag) {
				$tags[] = $this->hashtagService->tagEntity($hashtag['hashtag'], null, $period);
			}

			return Revalidation::byContent($this->request, new DataResponse($tags, Http::STATUS_OK));
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
	 * The handle to judge an account's remoteness by.
	 *
	 * The route takes either a handle or a numeric id; only the handle carries
	 * the host, so for an id it comes from the account that was resolved.
	 */
	private function handleOf(string $reference, Person $actor): string {
		if (is_numeric($reference)) {
			return $actor->getAccount();
		}

		return $reference;
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
	 * @param int|string $since
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
			// posts of any account they can name
			if ($this->viewer !== null) {
				$this->streamService->syncRemoteTimeline($local);
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
	 *
	 * @param string $account
	 *
	 * @return DataResponse
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
		try {
			$this->initViewer(false);
			$actor = $this->cacheActorService->resolve($account, $this->viewer !== null);

			$parts = explode('@', $this->handleOf($account, $actor));
			$domain = end($parts);
			$cloudHost = $this->configService->getCloudHost();
			$socialAddress = $this->configService->getSocialAddress();
			// the remote collection is read only for a caller with a session:
			// it is a fetch of a URL from another server followed by a lookup
			// of every actor on the page, each of which this instance would
			// fetch and store. An anonymous caller gets what is known here.
			if ($this->viewer !== null
				&& $domain !== '' && $domain !== $cloudHost && $domain !== $socialAddress) {
				$followingUrl = $actor->getFollowing();
				if (!empty($followingUrl)) {
					$result = $this->fetchRemoteCollection($followingUrl, $limit);
					return new DataResponse($result, Http::STATUS_OK);
				}
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FOLLOWING)
				->setAccountId($actor->getId())
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since);

			return $this->paged($this->cacheActorService->probeActors($options), $options->getLimit());
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param string $account
	 *
	 * @return DataResponse
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
		try {
			$this->initViewer(false);

			$actor = $this->cacheActorService->resolve($account, $this->viewer !== null);

			$parts = explode('@', $this->handleOf($account, $actor));
			$domain = end($parts);
			$cloudHost = $this->configService->getCloudHost();
			$socialAddress = $this->configService->getSocialAddress();
			// see accountFollowing(): resolving a remote collection is a fetch
			// per entry, so it needs a session
			if ($this->viewer !== null
				&& $domain !== '' && $domain !== $cloudHost && $domain !== $socialAddress) {
				$followersUrl = $actor->getFollowers();
				if (!empty($followersUrl)) {
					$result = $this->fetchRemoteCollection($followersUrl, $limit);
					return new DataResponse($result, Http::STATUS_OK);
				}
			}

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FOLLOWERS)
				->setAccountId($actor->getId())
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since);

			return $this->paged($this->cacheActorService->probeActors($options), $options->getLimit());
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @param int $limit
	 * @param int|string $max_id
	 * @param int|string $min_id
	 * @param int|string $since_id
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/favourites/')]
	public function favourites(
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::FAVOURITES)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id);

			$posts = $this->streamService->getTimeline($options);

			return $this->paged(
				$this->filterService->apply($posts, '', $this->viewer), $options->getLimit(), $posts
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/bookmarks')]
	public function bookmarks(
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::BOOKMARKS)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id);

			$posts = $this->streamService->getTimeline($options);

			// not a filter context in Mastodon either: the statuses carry an
			// empty `filtered`, because its absence is read as an answer
			return $this->paged(
				$this->filterService->apply($posts, '', $this->viewer), $options->getLimit(), $posts
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * How many notifications have arrived since the reader last looked.
	 *
	 * The sidebar badge asks for this; a client that keeps markers gets the
	 * same answer from the same place.
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/unread_count')]
	public function notificationsUnreadCount(): Response {
		try {
			$this->initViewer(true);
			$userId = $this->currentSession();
			$marker = $this->markerService->lastReadId($userId, 'notifications');

			// the count changes when a notification arrives or the marker
			// moves, and nothing else; both are in the tag
			$newest = $this->streamRequest->newestNidFor(
				[md5($this->viewer->getId())], 'notif'
			);
			$notModified = $this->notModified($newest . '-' . $marker);
			if ($notModified !== null) {
				return $notModified;
			}

			return $this->tagged(new DataResponse([
				'count' => $this->streamRequest->countNotificationsSince($this->viewer, $marker),
			], Http::STATUS_OK));
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * How far through each timeline the reader has got.
	 *
	 * @param array $timeline the timelines asked about; all of them when empty
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/markers')]
	public function markersGet(array $timeline = []): DataResponse {
		try {
			$this->initViewer(true);

			// an object, never a list: a fresh account has no markers at all,
			// and `[]` is not something a client can read `home.last_read_id`
			// out of
			return new DataResponse(
				(object)$this->markerService->get($this->currentSession(), $timeline),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Moves one or more markers forward.
	 *
	 * The body is Mastodon's: `{"notifications": {"last_read_id": "42"}}`, or
	 * the same thing form-encoded as `notifications[last_read_id]=42`.
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/markers')]
	public function markersSet(): DataResponse {
		try {
			$this->initViewer(true);
			$userId = $this->currentSession();

			$input = $this->convertInput(file_get_contents('php://input'));
			$updated = [];
			foreach (MarkerService::TIMELINES as $timeline) {
				$lastReadId = $input[$timeline]['last_read_id'] ?? null;
				if ($lastReadId === null || $lastReadId === '') {
					continue;
				}

				$updated[$timeline] = $this->markerService->set($userId, $timeline, (string)$lastReadId);
			}

			return new DataResponse((object)$updated, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications')]
	public function notifications(
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
		array $types = [],
		array $exclude_types = [],
		string $accountId = '',
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe(ProbeOptions::NOTIFICATIONS)
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setTypes($types)
				->setExcludeTypes($exclude_types)
				->setAccountId($accountId);

			// A notification whose sub-type Mastodon has no name for would
			// serialise as `"type": ""`, which a client with a closed enum
			// cannot decode — and one undecodable entry loses the whole page.
			// It is dropped instead: there is nothing a client could show for it.
			$page = $this->streamService->getTimeline($options);
			$posts = array_values(
				array_filter(
					$page,
					static fn (Stream $post): bool
						=> Stream::notificationTypeOfSubType($post->getSubType()) !== ''
				)
			);

			// paged() is told what the query returned, not what survived the
			// filter: a page shortened here says nothing about whether older
			// notifications exist, and a client that pages on the `Link` header
			// stopped there with the rest of the list still in the database.
			// and then what the notification policy holds back — Mastodon
			// 4.3's `filter` and `drop`, which are about the *sender* rather
			// than about the notification. An account that has not touched the
			// policy pays nothing for this: partition() answers at once when
			// every one of the five questions is `accept`.
			return $this->paged(
				$this->filterService->applyToNotifications(
					$this->notificationPolicyService->partition($this->viewer, $posts)['shown'],
					$this->viewer
				),
				$options->getLimit(),
				$page
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/tag/{hashtag}')]
	public function tag(
		string $hashtag,
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
		bool $local = false,
		bool $only_media = false,
		bool $only_video = false,
		bool $only_news = false,
	): DataResponse {
		try {
			$this->initViewer(true);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setProbe('hashtag')
				->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setLocal($local)
				->setOnlyMedia($only_media)
				->setOnlyVideo($only_video)
				->setOnlyNews($only_news)
				->setArgument($hashtag);

			$posts = $this->streamService->getTimeline($options);

			return $this->paged(
				$this->filterService->apply($posts, Filter::CONTEXT_PUBLIC, $this->viewer),
				$options->getLimit(),
				$posts
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * @param string $url
	 * @param int $limit
	 *
	 * @return array
	 */
	private function fetchRemoteCollection(string $url, int $limit = 20): array {
		// A remote page can carry far more entries than asked for, and each
		// unresolved id below is a remote fetch, so both the limit and the number of
		// entries walked are bounded — an anonymous caller must not be able to turn
		// one request into thousands of outbound fetches.
		$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));

		try {
			$collectionData = $this->curlService->retrieveObject($url);
		} catch (Exception $e) {
			return [];
		}

		$pageData = $collectionData;
		if (isset($collectionData['first'])) {
			$pageUrl = is_array($collectionData['first'])
				? ($collectionData['first']['id'] ?? '')
				: $collectionData['first'];
			if (!empty($pageUrl) && is_string($pageUrl)) {
				try {
					$pageData = $this->curlService->retrieveObject($pageUrl);
				} catch (Exception $e) {
					return [];
				}
			} elseif (is_array($collectionData['first'])) {
				$pageData = $collectionData['first'];
			}
		}

		$items = $pageData['orderedItems'] ?? $pageData['items'] ?? [];
		if (!is_array($items)) {
			return [];
		}

		$actors = [];
		$count = 0;
		// array_slice bounds the walk itself: the $count guard alone only limits
		// successes, so a page of unresolvable ids would still be fetched one by one.
		foreach (array_slice($items, 0, $limit) as $item) {
			if ($count >= $limit) {
				break;
			}

			// An entry is either the actor's id or the actor inline; either way
			// it is resolved through the cache rather than built from the page.
			// An actor assembled straight from the remote JSON is not stored, so
			// it has no numeric id — and a page of accounts all sharing id "0"
			// is one a client cannot open, follow or mute, because every id in
			// the API addresses exactly one account.
			$actorId = '';
			if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
				$actorId = $item['id'];
			} elseif (is_string($item)) {
				$actorId = $item;
			}

			if ($actorId === '') {
				continue;
			}

			try {
				$person = $this->cacheActorService->getFromId($actorId);
			} catch (Exception $e) {
				continue;
			}

			if ($person->getNid() <= 0) {
				continue;
			}

			$person->setExportFormat(ACore::FORMAT_LOCAL);
			$actors[] = $person;
			$count++;
		}

		return $actors;
	}

}
