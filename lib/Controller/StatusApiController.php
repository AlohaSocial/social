<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\DeliveryService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PeerTubeService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\QuoteService;
use OCA\Social\Service\ReactionService;
use OCA\Social\Service\ReactionSummaryService;
use OCA\Social\Service\ScheduledStatusService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\TeamService;
use OCA\Social\Service\TranslationService;
use OCA\Social\Service\ViewCountService;
use OCA\Social\Service\WatchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
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
 * `statusAction()`'s `/api/v1/statuses/{nid}/{act}` also matches the paths of
 * the routes declared before it; it has to stay the last of them, because the
 * routes of one controller are matched in declaration order.
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
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private FilterService $filterService,
		private ScheduledStatusService $scheduledStatusService,
		private PostReviewService $postReviewService,
		private ModerationService $moderationService,
		private ViewCountService $viewCountService,
		private TeamService $teamService,
		private PlaceService $placeService,
		private DeliveryService $deliveryService,
		private ReactionService $reactionService,
		private ReactionSummaryService $reactionSummaryService,
		private TranslationService $translationService,
		private QuoteService $quoteService,
		private WatchService $watchService,
		private IFactory $l10nFactory,
		private DurableCache $durableCache,
		private ?\OCA\Social\Db\AtprotoRequest $atprotoRequest = null,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
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
			$post->setPublicationTarget($status->getPublicationTarget());
			if ($post->getPublicationTarget() === 'atproto') {
				if ($post->getType() !== Stream::TYPE_PUBLIC) {
					throw new InvalidActionException('AT Protocol posts must use public visibility');
				}
				if ($this->atprotoRequest !== null) {
					$linked = $this->atprotoRequest->getAccount($this->currentSession());
					if ($linked === null || $linked->getState() !== \OCA\Social\Model\Atproto\AtprotoAccount::STATE_LINKED) {
						throw new InvalidActionException('Connect your Bluesky account in settings to publish there');
					}
				}
			}
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

			// before the media is scoped: a reply to a direct message is a
			// direct message whatever `visibility` says, and its attachments
			// must not be made world-readable on the strength of the request
			$replyReference = $status->getInReplyToReference();
			if ($replyReference !== '') {
				try {
					$replyTo = ctype_digit($replyReference)
						? $this->streamService->getStreamByNid((int)$replyReference)
						: $this->streamService->getStreamById($replyReference, true);
					$post->setReplyTo($replyTo->getId());
					$post->setType(PostService::visibilityOfReply($post->getType(), $replyTo));
				} catch (StreamNotFoundException $e) {
					$this->logger->debug('reply to post not found');
				}
			}

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

			$post->setQuotedId($status->getQuotedId());
			$post->setQuotePolicy($status->getQuotePolicy());
			$post->setVideoMeta($status->getVideoMeta());

			// Before anything is written: a post a rule holds is stored as a
			// request and never reaches `social_stream`, so there is no row for
			// a timeline to find. The 422 is what a client can be told with the
			// vocabulary Mastodon's API has — `held_for_review` beside it is
			// what this app's own composer reads to say something better than
			// "failed".
			// a moved account may not post at all, and is told so before the
			// review queue gets to hold something a moderator would then be
			// shown from an account that has left
			$this->moderationService->assertNotMoved($author);
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
			$fields = $this->convertInput($input);
			$status->import($fields);

			$actor = $this->accountService->getActorFromUserId($this->currentSession());

			$item = $this->postService->editPost(
				$nid,
				$actor,
				$status->getStatus(),
				$status->getSpoilerText() !== '' ? $status->getSpoilerText() : null,
				$status->isSensitive(),
				$status->getLanguage() !== '' ? $status->getLanguage() : null,
				$this->mediaAttributes($fields['media_attributes'] ?? [])
			);
			$item->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($item, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's `media_attributes`, one entry per attachment.
	 *
	 * A JSON body sends a list of objects. A form body sends
	 * `media_attributes[][id]=1&media_attributes[][description]=…`, which Rails
	 * groups per attachment and PHP does not: every `[]` opens an entry of its
	 * own, so an id and its description arrive as two. An entry naming an id
	 * starts an attachment, and the entries after it fill that one in.
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
	 * @param string $act
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
}
