<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\PostHeldException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\Counts\CountService;
use OCA\Social\Service\DeliveryService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FeedDisplayService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\Interaction\InteractionService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PostDecorationService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\QuoteService;
use OCA\Social\Service\ReactionService;
use OCA\Social\Service\ReactionSummaryService;
use OCA\Social\Service\ReplyRuleService;
use OCA\Social\Service\ScheduledStatusService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\TeamService;
use OCA\Social\Service\TranslationService;
use OCA\Social\Service\VideoViewService;
use OCA\Social\Service\ViewCountService;
use OCA\Social\Service\WatchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Statuses: posting, editing and deleting one, reading it with its context,
 * card, source and delivery state, reacting to it, translating it, the people
 * who favourited, boosted or quoted it, its poll, and the one catch-all route
 * for favourite, boost, bookmark, pin and mute.
 *
 * `statusAction()`'s `/api/v1/statuses/{nid}/{act}` also matches the paths
 * of the routes declared before it, so it must stay last: a controller's
 * routes are matched in declaration order.
 */
class StatusApiController extends MastodonApiController {

	/** where a used Idempotency-Key is remembered, and for how long */
	private const IDEMPOTENCY_CACHE = 'social_idempotency';

	private const IDEMPOTENCY_TTL = 3600;

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
		private DocumentService $documentService,
		private ActionService $actionService,
		private PostService $postService,
		private PollService $pollService,
		private FilterService $filterService,
		private ScheduledStatusService $scheduledStatusService,
		private ModerationService $moderationService,
		private ViewCountService $viewCountService,
		private TeamService $teamService,
		private PlaceService $placeService,
		private PostDecorationService $postDecorationService,
		private DeliveryService $deliveryService,
		private ReactionService $reactionService,
		private ReactionSummaryService $reactionSummaryService,
		private TranslationService $translationService,
		private QuoteService $quoteService,
		private WatchService $watchService,
		private VideoViewService $videoViewService,
		private IFactory $l10nFactory,
		private DurableCache $durableCache,
		private Publisher $atprotoPublisher,
		private ReplyRuleService $replyRules,
		private InteractionService $interactions,
		private CountService $counts,
		private ?BlockedByService $blockedBy = null,
		private ?FeedDisplayService $feedDisplayService = null,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonymousRateLimit(limit: 30, period: 60)]
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
			if (array_key_exists('event', $data)) {
				$event = $status->getEvent();
				if ($event === null
					|| $event['start_time'] === null
					|| trim($event['name']) === ''
					|| $status->getPoll() !== null) {
					return new DataResponse(['error' => 'Events need a title and valid start time, and cannot be polls'], Http::STATUS_UNPROCESSABLE_ENTITY);
				}
			}

			// Mastodon answers `scheduled_at` with a ScheduledStatus and publishes
			// nothing yet; checked before the idempotency lookup, which only remembers
			// published statuses. Reviewed now, while its author is there to be told.
			if ($this->scheduledStatusService->requestedTime($data) > 0) {
				try {
					return new DataResponse(
						$this->scheduledStatusService->schedule(
							$this->accountService->getActorFromUserId($this->currentSession()),
							$status,
							$data
						),
						Http::STATUS_OK
					);
				} catch (PostHeldException $e) {
					return self::heldForReview($e);
				}
			}

			// Tusky and Ivory retry with the same Idempotency-Key when the connection
			// drops; without this the post would be created several times.
			$idempotencyKey = $this->idempotencyKey();
			$already = $this->statusForIdempotencyKey($idempotencyKey);
			if ($already !== null) {
				return new DataResponse($this->served($already), Http::STATUS_OK);
			}

			// Use the viewer that was already initialized
			$author = $this->accountService->getActorFromUserId($this->currentSession());

			// A post written as a team is attributed to the team's account and signed
			// with its key; the writer is recorded below, not published. An unknown team
			// handle and a team the account is not in are the same 404.
			$actor = $author;
			$postAs = $status->getPostAs();
			if ($postAs !== '') {
				$actor = $this->teamService->assertMayPostAs($this->currentSession(), $postAs);
			}

			$post = new Post($actor);
			$post->setContent($status->getStatus());
			$post->setPoll($status->getPoll());
			$post->setEvent($status->getEvent());
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

			// Before the media is scoped: a reply to a direct message is direct whatever
			// `visibility` says, so its attachments must not become world-readable.
			if ($status->getInReplyToId() > 0) {
				try {
					$replyTo = $this->streamService->getStreamByNid($status->getInReplyToId());
					$post->setReplyTo($replyTo->getId());
					$post->setType(PostService::visibilityOfReply($post->getType(), $replyTo));
				} catch (StreamNotFoundException $e) {
					$this->refuseHiddenByBlock($author, $status->getInReplyToId());
					throw new StreamNotFoundException(PostService::REPLY_PARENT_NOT_FOUND);
				}
			}

			if (!empty($status->getMediaIds())) {
				// The uploader's own media, even when posting as a team, in the client's
				// order; an id that is not one of their uploads refuses the post.
				$post->setMedias($this->documentService->mediaAttachmentsForEdit(
					$status->getMediaIds(),
					$this->viewer->getPreferredUsername(),
					$post->getType()
				));
			}

			$post->setQuotedId($status->getQuotedId());
			$post->setQuotePolicy($status->getQuotePolicy());
			$post->setReplyRule($status->getReplyRule());
			$post->setVideoMeta($status->getVideoMeta());

			// A moved account may not post; checked before review would hold the post.
			$this->moderationService->assertNotMoved($author);
			// Reviewed as the writer, not the team: first-post review is about an
			// account nobody has vouched for.
			$post->setAuthor($author);

			// A held post is stored as a request and never reaches `social_stream`. The
			// 422 is what Mastodon's API can say; `held_for_review` beside it is for
			// this app's composer.
			try {
				$activity = $this->postService->createPost($post);
			} catch (PostHeldException $e) {
				return self::heldForReview($e);
			}

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

			return new DataResponse($this->served($item), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The answer to a post a review rule holds: the request is kept for a
	 * moderator and the client is told so, with the held request beside it.
	 */
	private static function heldForReview(PostHeldException $e): DataResponse {
		return new DataResponse([
			'error' => 'This post is waiting for a moderator to look at it. '
				. 'It has been kept — there is no need to write it again.',
			'held_for_review' => true,
			'reason' => $e->getHeld()?->getReason() ?? $e->getReason(),
			'held_post' => $e->getHeld(),
		], Http::STATUS_UNPROCESSABLE_ENTITY);
	}

	/**
	 * The visibility a new status is posted with: the account's default
	 * (`source.privacy`) when the client sends none. Unknown values are refused,
	 * since `Stream::visibilityFromClient()` would map them to a `direct` post
	 * with no recipient.
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
	 * This request's Idempotency-Key, scoped to the credential that sent it, as
	 * a digest of both so the bearer token stays out of the cache.
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

		return $item;
	}

	private function rememberIdempotencyKey(string $key, int|string $nid): void {
		if ($key === '' || $nid < 1) {
			return;
		}

		// `DurableCache`, so retries are recognised without a memory cache.
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
			$fields = $this->convertInput($input);
			$status->import($fields);
			$event = $status->getEvent();
			if (array_key_exists('event', $fields) && $fields['event'] !== null
				&& (!is_array($fields['event']) || $event === null || $event['start_time'] === null || trim($event['name']) === '')) {
				return new DataResponse(['error' => 'Events need a title and valid start time'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$actor = $this->accountService->getActorFromUserId($this->currentSession());
			$mediaIds = array_key_exists('media_ids', $fields)
				? $this->editMediaIds($fields['media_ids'])
				: null;

			$item = $this->postService->editPost(
				$nid,
				$actor,
				$status->getStatus(),
				array_key_exists('spoiler_text', $fields) ? $status->getSpoilerText() : null,
				array_key_exists('sensitive', $fields) ? $status->isSensitive() : null,
				$status->getLanguage() !== '' ? $status->getLanguage() : null,
				$this->mediaAttributes($fields['media_attributes'] ?? []),
				$event,
				array_key_exists('event', $fields),
				$mediaIds
			);

			return new DataResponse($this->served($item), Http::STATUS_OK);
		} catch (InvalidActionException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** @return list<int|string> */
	private function editMediaIds(mixed $raw): array {
		if (!is_array($raw) || !array_is_list($raw) || count($raw) > Stream::MAX_ATTACHMENTS) {
			throw new InvalidActionException('Invalid media attachment list');
		}

		$ids = [];
		foreach ($raw as $id) {
			if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id)
				|| \OCA\Social\Tools\Nid::compare((string)$id, '0') < 1) {
				throw new InvalidActionException('Invalid media attachment list');
			}
			$normalizedId = \OCA\Social\Tools\Nid::normalize((string)$id);
			if (isset($ids[$normalizedId])) {
				throw new InvalidActionException('Invalid media attachment list');
			}
			$ids[$normalizedId] = $normalizedId;
		}

		return array_values($ids);
	}

	/**
	 * Mastodon's `media_attributes`, one entry per attachment.
	 *
	 * A form body (`media_attributes[][id]=1&media_attributes[][description]=…`)
	 * opens a new entry for every `[]` in PHP, unlike Rails, so an entry naming
	 * an id starts an attachment and the following entries fill it in.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function mediaAttributes(mixed $raw): array {
		if (!is_array($raw)) {
			return [];
		}

		$attributes = [];
		$current = null;
		foreach ($raw as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			if (array_key_exists('id', $entry)) {
				if ($current !== null) {
					$attributes[] = $current;
				}
				$current = $entry;
			} elseif ($current !== null) {
				$current = array_merge($current, $entry);
			}
		}
		if ($current !== null) {
			$attributes[] = $current;
		}

		return $attributes;
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
	public function statusGet(int|string $nid, bool $refresh_counts = true, bool $force_counts = false): DataResponse {
		try {
			$this->initViewer(false);

			$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
			// A boost of a status the viewer may not see is not found, as on Mastodon.
			if ($item->getType() === Announce::TYPE && !($item->getObject() instanceof Stream)) {
				throw new StreamNotFoundException();
			}
			// Refresh the post from its origin when opened; the background count
			// queue is much slower than an interactive read.
			$refreshed = $refresh_counts && $this->counts->refreshVisibleIfDue($item, $force_counts);
			if ($refreshed) {
				$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
			}
			$item = $this->served($item);
			// Opening a post's page counts as reading it; a timeline impression does not.
			$this->viewCountService->seen($item, $this->viewer);
			// and a video starts where the reader left it
			$this->watchService->attachProgress($item, $this->viewer);
			// Remote counts are fetched in the background; a client that knows the
			// header asks again a little later.
			$response = new DataResponse($item, Http::STATUS_OK);
			$queued = $refresh_counts && !$refreshed && $this->counts->seen([$item]);
			$countedPost = $item->getType() === Announce::TYPE && $item->getObject() instanceof Stream
				? $item->getObject()
				: $item;
			$refreshPending = $refreshed && $this->counts->visibleRefreshPending($countedPost->getId());
			if ($refreshPending || $queued) {
				$response->addHeader('X-Social-Filling', '1');
			}

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The counts of the statuses on a reader's screen, which the web app polls.
	 * Each id is resolved as `statusGet()` resolves it, unreadable ones left
	 * out; counts kept on another network are refreshed when due
	 * (`CountService::refreshVisibleMany()`). Records no view.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/status_counts')]
	public function statusCounts(mixed $ids = []): DataResponse {
		try {
			$this->initViewer(false);

			$posts = [];
			foreach ($this->countIds($ids) as $nid) {
				try {
					$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
				} catch (StreamNotFoundException) {
					continue;
				}
				if ($item->getType() === Announce::TYPE && !($item->getObject() instanceof Stream)) {
					continue;
				}
				$posts[$nid] = $item;
			}

			$result = $this->counts->refreshVisibleMany($posts);
			$refreshed = array_flip($result['refreshed']);
			$answer = [];
			foreach ($posts as $nid => $item) {
				$counted = self::countedPost($item);
				if (isset($refreshed[$counted->getId()])) {
					try {
						$counted = self::countedPost($this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage((string)$nid)));
					} catch (StreamNotFoundException) {
						continue;
					}
				}
				$answer[] = ['id' => (string)$nid] + $counted->exportCounts();
			}

			$response = new DataResponse($answer, Http::STATUS_OK);
			if ($result['pending']) {
				$response->addHeader('X-Social-Filling', '1');
			}

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The ids a counts request names, as `ids[]=…` or comma-separated: valid
	 * numeric ids only, each once, at most `CountService::VISIBLE_BATCH`.
	 *
	 * @return list<string>
	 */
	private function countIds(mixed $raw): array {
		if (is_string($raw)) {
			$raw = explode(',', $raw);
		}
		if (!is_array($raw)) {
			return [];
		}

		$ids = [];
		foreach ($raw as $id) {
			if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id)
				|| \OCA\Social\Tools\Nid::compare((string)$id, '0') < 1) {
				continue;
			}
			$ids[\OCA\Social\Tools\Nid::normalize((string)$id)] = true;
			if (count($ids) >= CountService::VISIBLE_BATCH) {
				break;
			}
		}

		return array_map('strval', array_keys($ids));
	}

	/** A boost's counts are those of the post it boosts. */
	private static function countedPost(Stream $item): Stream {
		$object = $item->getType() === Announce::TYPE ? $item->getObject() : null;

		return $object instanceof Stream ? $object : $item;
	}

	/**
	 * One status as a client is served it, dressed as a timeline dresses it.
	 */
	private function served(Stream $item): Stream {
		$item->setExportFormat(ACore::FORMAT_LOCAL);
		$this->postDecorationService->decorate([$item], $this->viewer?->getId() ?? '');

		return $item;
	}

	/**
	 * The link preview of one status, on its own. A status with no link
	 * answers `{}`, as Mastodon does.
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
	public function statusContext(int|string $nid, bool $with_hidden = false): DataResponse {
		try {
			$this->initViewer(false);
			$threadSort = $this->viewer === null || $this->feedDisplayService === null
				? 'oldest'
				: $this->feedDisplayService->threadSort($this->viewer->getUserId());
			$context = $this->streamService->getContextByNid($nid, $with_hidden, $threadSort);

			$response = new DataResponse(
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
			// The rest of the conversation is being read in the background.
			if ($context['filling'] ?? false) {
				$response->addHeader('X-Social-Thread-Filling', '1');
			}
			// Replies hidden by the thread's author: left out unless `with_hidden`
			// asks, then marked `hidden_by_author`.
			if (($context['hidden'] ?? 0) > 0) {
				$response->addHeader('X-Social-Hidden-Replies', (string)$context['hidden']);
			}

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Deletes one of the viewer's own statuses and answers with it, as Mastodon
	 * does, for a client's "delete & redraft".
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
				// Same as an unknown id: whether somebody else's post exists is not told.
				throw new StreamNotFoundException('Stream not found');
			}

			// Exported before the delete; not dressed, as a redraft needs only text and media.
			$item->setExportFormat(ACore::FORMAT_LOCAL);
			$deleted = $item->exportAsLocal();
			// With its source, as Mastodon answers a delete.
			$deleted['text'] = $this->asSourceText($item->getContent());

			$this->streamService->deleteLocalItem($item, $item->getType());

			return new DataResponse($deleted, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The text of one of the viewer's own statuses, as written (Mastodon's
	 * StatusSource), which Tusky and Ivory need before offering edit. The stored
	 * HTML is turned back: `nl2br()` breaks become newlines and entities are
	 * decoded.
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
	 * Where one of the viewer's own posts got to: `/api/v1/statuses/{nid}/delivery`
	 * (this app's own route). Only for the author; anyone else gets the 404 of
	 * a missing post. Delivered requests are purged after
	 * `RequestQueueService::RETENTION_SECONDS`, which the reply states.
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
				['id' => (string)$item->getNid()] + $this->deliveryService->forObject($item->getId()) + ['bluesky' => $this->atprotoPublisher->statusOf($item)],
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
	 * Reacts to a status with an emoji. Answers the status, so a client redraws
	 * the card from one response.
	 *
	 * Declared before `statusAction()`, whose `POST /api/v1/statuses/{nid}/{act}`
	 * matches this path too. The emoji is a parameter rather than a path segment
	 * since it can be a long grapheme cluster.
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

			// Read back, so the client gets the count the next reader will be served.
			$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));

			return new DataResponse($this->served($item), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The emoji reactions on a status: each emoji, how many used it, and
	 * whether the caller is one of them. A status the caller may not read is a
	 * 404, as for `favourited_by`.
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
	 * One status, in the reader's language, as Mastodon's Translation entity.
	 *
	 * Declared before `statusAction()`, which would otherwise match this path.
	 * Uses this Nextcloud's translation provider; without one,
	 * `configuration.translation.enabled` says so and this answers 503, never
	 * the original text.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/translate')]
	public function statusTranslate(int|string $nid, string $lang = ''): DataResponse {
		try {
			$this->initViewer(true);
			$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));

			// The requested language, else the reader's Nextcloud language.
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
	 * Remembers where the reader got to in a video; private to the reader,
	 * never federated or counted.
	 *
	 * Declared ahead of `statusAction()`, whose `/api/v1/statuses/{nid}/{act}`
	 * matches this path too: routes are matched in declaration order, and a
	 * trait's methods come after the class's own.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// a player reports as it goes, so this is asked for often and is cheap
	#[AnonymousRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/watched')]
	public function statusWatched(int|string $nid, int $position = 0, int $duration = 0): DataResponse {
		try {
			$this->initViewer(true);
			$post = $this->streamService->getStreamByNid($nid);
			$stored = $this->watchService->remember($post, $this->viewer, $position, $duration);

			return new DataResponse(['stored' => $stored], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Counts a view of a video posted here once the reader has watched
	 * `VideoViewService::MIN_SECONDS`; at most one per viewer (account or
	 * session) per video and day. Answers whether the count moved.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonymousRateLimit(limit: 60, period: 3600)]
	#[UserRateLimit(limit: 600, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/view')]
	public function statusView(int|string $nid): DataResponse {
		try {
			$this->initViewer(false);
			$post = $this->streamService->getStreamByNid($nid);

			return new DataResponse(['counted' => $this->videoViewService->count($post, $this->viewer)], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Takes a video off the reader's own "continue watching" list. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonymousRateLimit(limit: 60, period: 3600)]
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
	 * @param string $act
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/{act}')]
	public function statusAction(int|string $nid, string $act): DataResponse {
		$act = $this->statusAct($act);
		try {
			$this->initViewer(true);
			$actor = $this->accountService->getActor($this->viewer->getPreferredUsername());
			$item = $this->actionService->action($actor, $nid, $act);

			if ($item === null) {
				$item = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
			}

			return new DataResponse($this->served($item), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The accounts that favourited a status, newest first (Mastodon's
	 * `favourited_by`). A status the caller may not read is a 404. Remote likes
	 * are included (`InteractionService`); `X-Social-Filling: 1` says they are
	 * still being read.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/favourited_by')]
	public function statusFavouritedBy(int|string $nid, int $limit = 40): DataResponse {
		return $this->reactedBy($nid, Like::TYPE, $limit);
	}

	/** The accounts that boosted a status, newest first. Mastodon's `reblogged_by`. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/reblogged_by')]
	public function statusRebloggedBy(int|string $nid, int $limit = 40): DataResponse {
		return $this->reactedBy($nid, Announce::TYPE, $limit);
	}

	/**
	 * Marks an answer as still being filled in from elsewhere; a client that
	 * knows the header asks again a little later.
	 */
	private function filling(DataResponse $response, bool $filling): DataResponse {
		if ($filling) {
			$response->addHeader('X-Social-Filling', '1');
		}

		return $response;
	}

	private function reactedBy(int|string $nid, string $type, int $limit): DataResponse {
		try {
			$this->initViewer(false);
			$limit = min(max($limit, 1), 80);
			$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
			$reacted = $this->interactions->reactedBy($post, $type, $limit);

			return $this->filling(new DataResponse($reacted['accounts'], Http::STATUS_OK), $reacted['filling']);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The posts that quote one status, newest first: Mastodon 4.5's
	 * `GET /api/v1/statuses/{id}/quotes`.
	 *
	 * Only quotes this server holds, read as the viewer. Quotes listed by the
	 * networks the post is on are fetched in the background
	 * (`X-Social-Filling: 1`).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/quotes')]
	public function statusQuotes(int|string $nid, int $limit = 20, int|string $max_id = 0): DataResponse {
		try {
			$this->initViewer(false);
			$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));

			$filling = $this->interactions->askForQuotes($post);
			$quotes = $this->quoteService->quotesOf($post, $limit, $max_id);
			foreach ($quotes as $quote) {
				$quote->setExportFormat(ACore::FORMAT_LOCAL);
			}

			return $this->filling(new DataResponse($quotes, Http::STATUS_OK), $filling);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Who may quote one of the caller's own posts: Mastodon 4.5's
	 * `PUT /api/v1/statuses/{id}/interaction_policy`. `quote_approval_policy` is
	 * `public`, `followers` or `nobody` and applies to future requests only;
	 * revoking a given permission is the route below.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonymousRateLimit(limit: 60, period: 3600)]
	#[UserRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/statuses/{nid}/interaction_policy')]
	public function statusInteractionPolicy(int|string $nid): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput((string)file_get_contents('php://input'));
			$status = new Status();
			$status->import($input);

			$actor = $this->accountService->getActorFromUserId($this->currentSession());
			// Each policy only when sent.
			if (array_key_exists('reply_policy', $input)) {
				$item = $this->replyRules->setRule($nid, $actor, $status->getReplyRule() !== '' ? $status->getReplyRule() : Stream::REPLY_RULE_EVERYONE);
			}
			if (!array_key_exists('reply_policy', $input) || array_key_exists('quote_approval_policy', $input)) {
				$item = $this->quoteService->setPolicy($nid, $actor, $status->getQuotePolicy());
				try {
					$this->atprotoPublisher->updateGates($item);
				} catch (Throwable $e) {
					$this->logger->warning('Quote policy not written to Bluesky', ['post' => $item->getId(), 'exception' => $e]);
				}
			}

			return new DataResponse($this->served($item), Http::STATUS_OK);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Takes one quote of one of the caller's own posts back: Mastodon 4.5's
	 * `POST /api/v1/statuses/{id}/quotes/{quoting}/revoke`. A remote quote gets
	 * a `Reject` of the accepted request (FEP-044f); a local one is withdrawn
	 * directly.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonymousRateLimit(limit: 60, period: 3600)]
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
	 * A reply to a post the writer cannot see because its author blocked them
	 * is refused with the reason, not posted as a new thread.
	 *
	 * @throws \OCA\Social\Exceptions\BlockedByException
	 */
	private function refuseHiddenByBlock(Person $author, int|string $nid): void {
		try {
			$parent = $this->streamService->getStreamByNid($nid, false);
		} catch (StreamNotFoundException) {
			return;
		}
		$this->blockedBy?->assertNotBlocked($author, [$parent->getAttributedTo()]);
	}

}
