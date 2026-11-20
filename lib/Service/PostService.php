<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\Postgates;
use OCA\Social\Atproto\Reader\Threadgates;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Events\PostEditedEvent;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Exceptions\FederationDeliveryException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\PostHeldException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Post;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class PostService {
	/** Mastodon's poll limits, which `InstanceService` advertises under `polls` */
	public const POLL_MAX_OPTIONS = 4;
	public const POLL_MAX_CHARACTERS_PER_OPTION = 100;

	/**
	 * How long a poll may run, in seconds, and the shortest it may run for:
	 * Mastodon's own bounds, advertised by `InstanceService` as
	 * `polls.min_expiration`/`max_expiration`, since clients offer exactly those.
	 */
	public const POLL_MIN_EXPIRATION = 300;
	public const POLL_MAX_EXPIRATION = 2629746;

	/** Mastodon's words for a reply whose parent is missing or not readable by its author. */
	public const REPLY_PARENT_NOT_FOUND = 'The post you are trying to reply to does not appear to exist.';

	/**
	 * The languages whose region subtag names a different written language,
	 * and which Mastodon therefore offers as post languages of their own.
	 * For every other language a Nextcloud locale's region describes the UI,
	 * not the text: `en_GB` is English.
	 */
	private const REGIONAL_LANGUAGES = ['pt', 'zh'];

	public function __construct(
		private StreamService $streamService,
		private AccountService $accountService,
		private ActivityService $activityService,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private ModerationService $moderationService,
		private StatusRevisionService $revisionService,
		private NotificationService $notificationService,
		private LinkifyService $linkifyService,
		private ChannelService $channelService,
		private ConfigService $configService,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private InterestService $interestService,
		private VideoDeliveryHold $videoDeliveryHold,
		private DocumentService $documentService,
		private StreamCardsRequest $streamCardsRequest,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * @param Post $post
	 * @param string $token
	 *
	 * @return ACore
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 * @throws UnauthorizedFediverseException
	 */
	public function createPost(Post $post, string &$token = ''): ?ACore {
		$this->moderationService->assertNotSuspended($post->getActor()->getId());
		$this->moderationService->assertNotMoved($post->getActor());
		$this->assertWithinLength($post->getContent(), $post->getSpoilerText());
		$this->fixRecipientAndHashtags($post);

		// before the recipients are assigned: the parent decides how wide a
		// reply may be, and a reply to a direct message is a direct message
		$parent = $this->replyParent($post);
		if ($parent !== null) {
			$post->setType(self::visibilityOfReply($post->getType(), $parent));
			// before anything is written: an author who blocked the replier,
			// a Bluesky threadgate, or a local post's reply rule
			$this->blockedBy()?->assertMayReply($post->getActor(), $parent);
			$refusal = $this->threadgates()?->refusal($post->getActor(), $parent) ?? '';
			if ($refusal === '') {
				$refusal = $this->replyRules()?->refusal($parent, $post->getActor()->getId()) ?? '';
			}
			if ($refusal !== '') {
				throw new InvalidActionException($refusal);
			}
		}

		// after every refusal, so nothing refused is put in front of a
		// moderator, and before anything is written
		$this->review($post, ($parent === null) ? '' : (string)$parent->getNid());

		$note = new Note();
		if ($post->getEvent() !== null) {
			$note->setSubType('Event');
			$note->setDetailArray(Details::EVENT, $post->getEvent());
		}
		if ($post->hasPoll()) {
			$poll = $post->getPoll();
			$options = self::pollOptions($poll['options'] ?? []);

			$note = new Question();
			$note->setPollData(
				$options,
				(bool)($poll['multiple'] ?? false),
				min(
					max((int)($poll['expires_in'] ?? 86400), self::POLL_MIN_EXPIRATION),
					self::POLL_MAX_EXPIRATION
				)
			);
		}
		$actor = $post->getActor();
		$this->streamService->assignItem($note, $actor, $post->getType());

		$note->setAttributedTo($actor->getId());
		// The warning is the object's `summary`, which is plain text wherever
		// it is read, so it is stored exactly as typed: entity-encoding would
		// federate `Bob&#039;s`, and `strip_tags()` would eat `I <3 cats`.
		// editPost() agrees.
		$note->setSpoilerText(ACore::withoutMarkup($post->getSpoilerText()));
		$note->setSensitive($post->isSensitive());
		$note->setAttachments($post->getMedias());
		$note->setVisibility($post->getType());
		$note->setLanguage($this->languageFor($post->getLanguage(), $actor));
		$note->setPlaceId($post->getPlaceId());

		$this->ensureChannelForVideo($post);
		$this->streamService->replyTo($note, $post->getReplyTo(), $parent);
		// who may quote this one, before it is stored: the column is written by
		// the same insert as everything else on the post
		$note->setQuotePolicy($post->getQuotePolicy());
		if ($post->getReplyRule() !== '') {
			$rules = $this->replyRules();
			$note->setReplyRule($rules?->sanitize($post->getActor()->getId(), $post->getReplyRule()) ?? $post->getReplyRule());
			$rules?->snapshotListMembers($note);
		}
		if ($post->getVideoMeta() !== []) {
			$note->setVideoMeta($post->getVideoMeta());
		}
		if ($post->getAtprotoRecordEmbed() !== null) {
			$atproto = $note->getDetails(Details::ATPROTO);
			$atproto[Details::ATPROTO_RECORD_EMBED] = $post->getAtprotoRecordEmbed();
			$note->setDetailArray(Details::ATPROTO, $atproto);
		}
		if ($post->getAtprotoSelfLabels() !== []) {
			$atproto = $note->getDetails(Details::ATPROTO);
			$atproto[Details::ATPROTO_SELF_LABELS] = $post->getAtprotoSelfLabels();
			$note->setDetailArray(Details::ATPROTO, $atproto);
		}
		$quotedAuthor = $this->applyQuote($note, $post->getQuotedId(), $post->getActor());
		$this->streamService->addRecipients($note, $post->getType(), $post->getTo());
		$this->streamService->addChannelFollowers($note);
		$this->streamService->addHashtags($note, $post->getHashtags());
		$this->streamService->addCustomEmojis(
			$note, $post->getContent(), $post->getSpoilerText()
		);
		//		$this->streamService->addAttachments($note, $post->getDocuments());

		// last, because the links are built from the `tag` array assembled
		// above: only a mention the tags vouch for is linked
		$note->setContent($this->linkifyService->toHtml($post->getContent(), $note->getTags()));

		// the stored source is what federates on Update, and for a poll the
		// only place the options and counts are kept. The language, tags and
		// quote are written to their own columns by StreamRequest from this
		// same object, so they cannot disagree.
		$this->snapshotSource($note);

		// a video still to be converted holds the delivery back, because servers
		// that refuse the original never ask again; 0 for every other post
		$holdUntil = $this->videoDeliveryHold->holdUntil($note);
		$token = $this->activityService->createActivity($actor, $note, $activity, $holdUntil);
		$linkCard = $post->getLinkCard();
		if ($linkCard !== null) {
			try {
				$this->streamCardsRequest->save((new StreamCard($note->getId(), $linkCard['url']))
					->setTitle($linkCard['title'])
					->setDescription($linkCard['description'])
					->setImage($linkCard['image'])
					->setProviderName($linkCard['provider']));
			} catch (Throwable $e) {
				$this->logger->warning('Post link card was not stored', [
					'post' => $note->getId(),
					'exception' => $e,
				]);
			}
		}
		if ($holdUntil > 0) {
			$this->videoDeliveryHold->convertSoon($note);
		}
		$this->learnFromReply($actor, $post->getReplyTo());
		// One counter, moved by one, and only for a post that names the public
		// collection, which is what `StreamRequest::countNotesFromActorId()`
		// recounts.
		if ($note->addressesPublic()) {
			$this->accountService->bumpActorCount($actor->getId(), 'count_posts', 1);
		}

		// after the post exists: the request names it as the instrument, and
		// the quoted author's server dereferences both before approving
		if ($quotedAuthor !== null) {
			$this->requestQuoteApproval($actor, $note, $quotedAuthor);
		}

		$this->logger->debug('Activity: ' . json_encode($activity));

		// dispatched after the post is stored and addressed, so a listener sees
		// what was published; never waits on delivery
		$this->eventDispatcher->dispatchTyped(new PostPublishedEvent($note));

		return $activity;
	}

	/**
	 * The options of a poll as a client sent them, blank ones left out, or
	 * the refusal Mastodon answers with a 422: fewer than two, more than
	 * `POLL_MAX_OPTIONS`, or one longer than `POLL_MAX_CHARACTERS_PER_OPTION`.
	 *
	 * @return list<string>
	 * @throws InvalidActionException
	 */
	public static function pollOptions(mixed $options): array {
		$options = array_values(array_filter(
			array_map('strval', is_array($options) ? $options : []),
			static fn (string $option): bool => trim($option) !== ''
		));
		if (count($options) < 2) {
			throw new InvalidActionException('a poll needs at least two options');
		}
		if (count($options) > self::POLL_MAX_OPTIONS) {
			throw new InvalidActionException('a poll may have at most ' . self::POLL_MAX_OPTIONS . ' options');
		}
		foreach ($options as $option) {
			if (mb_strlen($option) > self::POLL_MAX_CHARACTERS_PER_OPTION) {
				throw new InvalidActionException(
					'a poll option may not be longer than ' . self::POLL_MAX_CHARACTERS_PER_OPTION . ' characters'
				);
			}
		}

		return $options;
	}

	/**
	 * The visibility a reply is published with: what was asked for, unless
	 * the post it answers is a direct message; then it is one too, as on
	 * Mastodon, so an answer never widens the conversation.
	 */
	public static function visibilityOfReply(string $requested, Stream $parent): string {
		if ($parent->getVisibility() === Stream::TYPE_DIRECT) {
			return Stream::TYPE_DIRECT;
		}

		return $requested;
	}

	/**
	 * Puts a post past the review queue (`PostReviewService`), as
	 * `createPost()` does unless the post is `Post::REVIEW_EXEMPT`. A held
	 * post becomes a request for a moderator (`REVIEW_HOLD`) or is refused
	 * (`REVIEW_REFUSE`); either way nothing is published. Public so a
	 * scheduled post is reviewed when scheduled, while its author is there.
	 *
	 * @param string $inReplyToNid the nid of the post it answers, '' for none
	 * @param int $scheduledAt when a scheduled post is to go out, kept with
	 *                         the request so an approval schedules it
	 *
	 * @throws PostHeldException
	 * @throws InvalidActionException the author has too many posts waiting
	 */
	public function review(Post $post, string $inReplyToNid = '', int $scheduledAt = 0): void {
		if ($post->getReview() === Post::REVIEW_EXEMPT) {
			return;
		}
		$review = $this->postReview();
		if ($review === null) {
			return;
		}

		$author = $post->getAuthor();
		$reason = $review->assess(
			$author,
			$post->getContent(),
			$post->getType(),
			// asked of the attachments that were resolved, not of the ids a
			// client sent: an id that named nothing is not a video on this post
			PeerTubeService::soleVideo($post->getMedias()) !== null
		);
		if ($reason === '') {
			return;
		}
		if ($post->getReview() === Post::REVIEW_REFUSE) {
			throw new PostHeldException($reason);
		}

		$params = StatusAssemblyService::paramsOfPost($post, $inReplyToNid);
		if ($scheduledAt > 0) {
			$params['scheduled_at'] = $scheduledAt;
		}

		throw new PostHeldException($reason, $review->hold($author, $params, $reason));
	}

	/**
	 * Resolved when first needed: the queue publishes what it approves through
	 * this service. Null without a container, as in a unit test.
	 */
	private function postReview(): ?PostReviewService {
		$service = $this->container?->get(PostReviewService::class);

		return $service instanceof PostReviewService ? $service : null;
	}

	/**
	 * Resolved when first needed rather than injected: the Bluesky side needs
	 * services that need this one. Null without a container, as in a unit test.
	 */
	private function threadgates(): ?Threadgates {
		$service = $this->container?->get(Threadgates::class);

		return $service instanceof Threadgates ? $service : null;
	}

	private function blockedBy(): ?BlockedByService {
		$service = $this->container?->get(BlockedByService::class);

		return $service instanceof BlockedByService ? $service : null;
	}

	private function replyRules(): ?ReplyRuleService {
		$service = $this->container?->get(ReplyRuleService::class);

		return $service instanceof ReplyRuleService ? $service : null;
	}

	private function postgates(): ?Postgates {
		$service = $this->container?->get(Postgates::class);

		return $service instanceof Postgates ? $service : null;
	}

	/**
	 * The post being replied to, read as the reply's author: a post they may
	 * not read is not found, in the same words as one that is not here.
	 *
	 * @throws StreamNotFoundException
	 */
	private function replyParent(Post $post): ?Stream {
		if ($post->getReplyTo() === '') {
			return null;
		}

		$this->streamService->setViewer($post->getActor());
		try {
			return $this->streamService->getStreamById($post->getReplyTo(), true);
		} catch (StreamNotFoundException) {
			throw new StreamNotFoundException(self::REPLY_PARENT_NOT_FOUND);
		}
	}

	/** Apply the same permission checks as a new reply before changing a post. */
	private function editReplyTarget(Stream $stream, Person $actor, string $replyTo): void {
		if (!$stream instanceof Note || !$stream->isLocal()) {
			throw new InvalidActionException('Only a local post reply target can be changed');
		}
		$parent = $this->replyParent((new Post($actor))->setReplyTo($replyTo));
		if ($parent !== null) {
			if (($stream->isPublic() && !$parent->isPublic())
				|| self::visibilityOfReply($stream->getVisibility(), $parent) !== $stream->getVisibility()) {
				throw new InvalidActionException('Changing the reply target cannot widen the parent audience');
			}
			$this->blockedBy()?->assertMayReply($actor, $parent);
			$refusal = $this->threadgates()?->refusal($actor, $parent) ?? '';
			if ($refusal === '') {
				$refusal = $this->replyRules()?->refusal($parent, $actor->getId()) ?? '';
			}
			if ($refusal !== '') {
				throw new InvalidActionException($refusal);
			}
			// Never attach a post to itself or to one of its descendants. Bound
			// the walk, including malformed remote chains that already cycle.
			$seen = [$stream->getId() => true];
			$ancestor = $parent;
			for ($depth = 0; ; $depth++) {
				if ($depth >= 64 || isset($seen[$ancestor->getId()])) {
					throw new InvalidActionException('The reply would create a cyclic or overly deep thread');
				}
				$seen[$ancestor->getId()] = true;
				if ($ancestor->getInReplyTo() === '') {
					break;
				}
				$ancestor = $this->streamService->getStreamById($ancestor->getInReplyTo(), true);
			}
		}
		$stream->setInReplyTo('');
		$stream->setReplyState('');
		$stream->setConversation($parent === null ? '' : ($parent->getConversation() ?: $parent->getId()));
		$this->streamService->replyTo($stream, $replyTo, $parent);
	}

	/**
	 * @param ?string $language the language the client sent, null or empty to
	 *                          keep the post's; a post that never had one gets
	 *                          the poster's default
	 * @param list<array<string, mixed>> $mediaAttributes Mastodon's `media_attributes`:
	 *                                                    the new description and focal
	 *                                                    point of attachments the post carries
	 *
	 * @throws \Exception
	 */
	public function editPost(
		int|string $nid, Person $actor, string $content, ?string $spoilerText = null, ?bool $sensitive = null,
		?string $language = null, array $mediaAttributes = [], ?array $event = null, bool $eventProvided = false,
		?array $mediaIds = null, ?array $linkCard = null, bool $linkCardProvided = false,
		bool $atprotoEmbedProvided = false, string $quotedId = '', ?array $atprotoRecordEmbed = null,
		?array $atprotoSelfLabels = null, ?string $replyTo = null,
	): Stream {
		$this->moderationService->assertNotSuspended($actor->getId());
		$this->moderationService->assertNotMoved($actor);
		$stream = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));

		// somebody else's post is answered as one that is not there, as
		// Mastodon does: a 404, not a fault on this server
		if ($stream->getAttributedTo() !== $actor->getId()) {
			throw new StreamNotFoundException();
		}

		$this->assertWithinLength(
			$content, $spoilerText ?? $stream->getSpoilerText()
		);

		// the revision recorded below is the version being replaced, so it has
		// to be taken before any of the fields are overwritten
		$original = clone $stream;
		if ($replyTo !== null && $replyTo !== $stream->getInReplyTo()) {
			$this->editReplyTarget($stream, $actor, $replyTo);
		}
		$oldQuotedId = $stream->getQuote();
		$quotedAuthor = null;
		// the tags first: they are what the markup below may link, and an edit
		// is a fresh parse of the text
		$this->reapplyEntities($stream, $content);
		$stream->setContent($this->linkifyService->toHtml($content, $stream->getTags()));
		if ($spoilerText !== null) {
			$stream->setSpoilerText(ACore::withoutMarkup($spoilerText));
		}

		// an edit may add a shortcode, and the tag is what makes it render
		$this->streamService->addCustomEmojis($stream, $content, $stream->getSpoilerText());
		if ($sensitive !== null) {
			$stream->setSensitive($sensitive);
		}
		$stream->setLanguage($this->languageFor((string)$language, $actor, $stream->getLanguage()));
		if ($mediaIds !== null) {
			$stream->setAttachments($this->documentService->mediaAttachmentsForEdit(
				$mediaIds, $actor->getPreferredUsername(), $stream->getVisibility()
			));
		}
		if ($atprotoEmbedProvided && $oldQuotedId !== $quotedId) {
			$stream->setQuote('');
			$stream->setQuoteAuthorization('');
			$stream->setQuoteState('');
			if ($quotedId !== '') {
				$quotedAuthor = $this->applyQuote($stream, $quotedId, $actor);
			}
		}
		if ($atprotoEmbedProvided) {
			$details = $stream->getDetails(Details::ATPROTO);
			if ($atprotoRecordEmbed === null) {
				unset($details[Details::ATPROTO_RECORD_EMBED]);
			} else {
				$details[Details::ATPROTO_RECORD_EMBED] = $atprotoRecordEmbed;
			}
			$stream->setDetailArray(Details::ATPROTO, $details);
		}
		if ($atprotoSelfLabels !== null) {
			$details = $stream->getDetails(Details::ATPROTO);
			if ($atprotoSelfLabels === []) {
				unset($details[Details::ATPROTO_SELF_LABELS]);
			} else {
				$details[Details::ATPROTO_SELF_LABELS] = array_values(array_unique($atprotoSelfLabels));
			}
			$stream->setDetailArray(Details::ATPROTO, $details);
		} elseif ($spoilerText !== null) {
			// a client can add or remove the warning: keep the Bluesky
			// self-label in step and every other label the author supplied
			$details = $stream->getDetails(Details::ATPROTO);
			$labels = [];
			foreach (is_array($details[Details::ATPROTO_SELF_LABELS] ?? null) ? $details[Details::ATPROTO_SELF_LABELS] : [] as $label) {
				if ($label !== '!warn') {
					$labels[] = $label;
				}
			}
			if (trim($spoilerText) !== '') {
				array_unshift($labels, '!warn');
			}
			if ($labels === []) {
				unset($details[Details::ATPROTO_SELF_LABELS]);
			} else {
				$details[Details::ATPROTO_SELF_LABELS] = array_values(array_unique($labels));
			}
			$stream->setDetailArray(Details::ATPROTO, $details);
		}

		// a new list rather than changed objects: `$original` shares them, and
		// the revision recorded below keeps the old words
		if ($mediaAttributes !== []) {
			$stream->setAttachments($this->documentService->applyMediaAttributes(
				$stream->getAttachments(), $actor->getPreferredUsername(), $mediaAttributes
			));
		}
		if ($eventProvided) {
			$details = $stream->getDetailsAll();
			if ($event === null) {
				unset($details[Details::EVENT]);
				if ($stream->getSubType() === 'Event') {
					$stream->setSubType('');
				}
			} else {
				$details[Details::EVENT] = $event;
				$stream->setSubType('Event');
			}
			$stream->setDetailsAll($details);
		}

		// `published` stays the creation time: Mastodon reads an Update
		// without `updated` as a counter refresh and drops the content.
		// Strictly later than the prior edit so peers can order updates.
		$stream->setUpdated(self::nextEditTimestamp($original));
		$this->snapshotSource($stream);

		// an edit can name somebody new: their `social_stream_dest` row is
		// stored with the content in one transaction
		$this->streamService->updateStream($stream, true);
		if ($original->getInReplyTo() !== $stream->getInReplyTo()) {
			$this->streamService->recountReplies($original->getInReplyTo());
			$this->streamService->recountReplies($stream->getInReplyTo());
		}
		if ($atprotoEmbedProvided && $oldQuotedId !== $quotedId) {
			$this->streamService->recountQuotes($oldQuotedId);
			$this->streamService->recountQuotes($quotedId);
		}
		$this->revisionService->recordEdit($original, $stream);
		if ($linkCardProvided) {
			try {
				if ($linkCard === null) {
					$this->streamCardsRequest->deleteByStreamId($stream->getId());
				} else {
					$this->streamCardsRequest->save((new StreamCard($stream->getId(), $linkCard['url']))
						->setTitle($linkCard['title'])
						->setDescription($linkCard['description'])
						->setImage($linkCard['image'])
						->setProviderName($linkCard['provider']));
				}
			} catch (Throwable $e) {
				$this->logger->warning('Edited post link card was not stored', [
					'post' => $stream->getId(),
					'exception' => $e,
				]);
			}
		}

		$updated = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
		// the followers path expands to every instance with a follower, so it
		// is only added where the post reaches followers (not for `direct`)
		if ($this->streamService->reachesFollowers($updated)) {
			$updated->addInstancePath(
				new InstancePath(
					$actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
				)
			);
		}
		$this->streamService->addChannelFollowers($updated);
		$this->eventDispatcher->dispatchTyped(new PostEditedEvent($updated));

		// local subscribers learn about the edit even when the remote request
		// cannot be queued; the edit is saved, so the failure is a 503 that
		// need not be retried
		$this->notificationService->onStatusEdited($updated);

		try {
			// an edit to a post whose video is still being converted waits
			// with it, so it cannot overtake the Create or carry the old file
			$holdUntil = $this->videoDeliveryHold->holdUntil($updated);
			$this->activityService->updateActivity($actor, $updated, $holdUntil);
			if ($quotedAuthor !== null) {
				$this->requestQuoteApproval($actor, $updated, $quotedAuthor);
			}
			if ($holdUntil > 0) {
				$this->videoDeliveryHold->convertSoon($updated);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to federate post update', ['exception' => $e]);
			throw new FederationDeliveryException(
				'The post was saved locally, but its edit could not be sent to other instances.',
				0,
				$e
			);
		}
		return $updated;
	}

	/**
	 * ActivityPub update timestamps have second precision: advance past the
	 * previous edit so peers can order two edits in the same second.
	 */
	private static function nextEditTimestamp(Stream $previous): string {
		$timestamp = time();
		if ($previous->getUpdated() !== '') {
			$previousTimestamp = strtotime($previous->getUpdated());
			if ($previousTimestamp !== false) {
				$timestamp = max($timestamp, $previousTimestamp + 1);
			}
		}

		return gmdate('Y-m-d\\TH:i:s\\Z', $timestamp);
	}

	/**
	 * Refuses a post longer than the advertised
	 * `configuration.statuses.max_characters`, counted as Mastodon counts
	 * (LinkifyService::countableLength()), the spoiler included.
	 *
	 * @throws InvalidActionException the refusal the client API answers 422 with
	 */
	private function assertWithinLength(string $content, string $spoilerText): void {
		if (LinkifyService::countableLength($content) + mb_strlen($spoilerText) <= InstanceService::MAX_CHARACTERS) {
			return;
		}

		throw new InvalidActionException(
			'a post may not be longer than ' . InstanceService::MAX_CHARACTERS . ' characters'
		);
	}

	/**
	 * Gives an account a channel the first time it posts a video, since
	 * PeerTube refuses a `Video` whose `attributedTo` names no `Group`. Made
	 * when the post is written, not on each serialisation. A failure costs
	 * only the PeerTube shape; the `Note` still goes out.
	 */
	private function ensureChannelForVideo(Post $post): void {
		if (PeerTubeService::soleVideo($post->getMedias()) === null) {
			return;
		}

		try {
			if (!$this->configService->getAppValueBool(ConfigService::SOCIAL_PUBLISH_VIDEO)) {
				return;
			}

			$this->channelService->defaultFor($post->getActor());
		} catch (Throwable $e) {
			$this->logger->notice('could not give an account a channel for its video', [
				'actor' => $post->getActor()->getId(), 'exception' => $e,
			]);
		}
	}

	/**
	 * Puts the quoted post on the note and addresses its author. The quoted
	 * post is named by `quote_id` or an ActivityPub URI, must be held here and
	 * must be public or unlisted (as `PinService::pin()` and
	 * `BoostService::create()` require). The author is addressed because their
	 * server answers the QuoteRequest, unless it is this one.
	 *
	 * @return ?Person the quoted author, when there is a quote and they are known
	 * @throws InvalidActionException when the post may not be quoted, which
	 *                                the client API answers 422 with, as for
	 *                                PinService::pin()
	 */
	private function applyQuote(Stream $note, string $quotedId, Person $quoter): ?Person {
		if ($quotedId === '') {
			return null;
		}

		try {
			$quoted = ctype_digit($quotedId)
				? $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($quotedId))
				: $this->streamService->getStreamById($quotedId);
		} catch (\Exception $e) {
			throw new InvalidActionException('the post to quote is unknown here');
		}

		$this->blockedBy()?->assertNotBlocked($quoter, [$quoted->getAttributedTo()]);

		if (BlueskyIds::isPostId($quoted->getId())) {
			// on Bluesky the author's postgate decides and the AppView applies
			// it: a quote it shows detached is refused here
			$refusal = $quoted->getQuotePolicy() === Stream::QUOTE_POLICY_NOBODY
				? Postgates::REFUSAL
				: ($this->postgates()?->refusal($quoter, $quoted) ?? '');
			if ($refusal !== '') {
				throw new InvalidActionException($refusal);
			}
		}

		if (!$quoted->isQuotable()) {
			throw new InvalidActionException('you can only quote a public or unlisted post');
		}

		$note->setQuote($quoted->getId());

		if (BlueskyIds::isPostId($quoted->getId())) {
			$note->setQuoteState(Stream::QUOTE_ACCEPTED);

			return null;
		}

		if ($quoted->isLocal()) {
			// the quoted author is local, so the approval is ours to give:
			// stamp it now, before the source is snapshotted, so the first
			// delivery carries it
			$note->setQuoteAuthorization(
				$quoted->getId() . '/quote_authorizations/'
				. QuoteRequestInterface::stamp($note->getId())
			);
			$note->setQuoteState(Stream::QUOTE_ACCEPTED);

			return null;
		}

		try {
			$author = $this->streamService->getAuthorFromPostId($quoted->getId());
		} catch (\Exception $e) {
			// the post is here but its author is not cached: the quote still
			// stands, the author simply learns of it the way anyone else does
			$this->logger->notice('quoting a post whose author cannot be resolved', [
				'quoted' => $quoted->getId(),
				'exception' => $e,
			]);

			return null;
		}

		$note->addCc($author->getId());
		$inbox = ($author->getSharedInbox() !== '') ? $author->getSharedInbox() : $author->getInbox();
		if ($inbox !== '') {
			$note->addInstancePath(
				new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
			);
		}

		return $author;
	}

	/**
	 * FEP-044f: ask the quoted author's server for permission. Mastodon 4.5
	 * renders a quote inline only with a `quoteAuthorization`, granted in an
	 * `Accept{QuoteRequest}` that `QuoteRequestInterface` writes onto the
	 * post. A failure here does not fail the published post.
	 */
	private function requestQuoteApproval(Person $actor, Stream $note, Person $author): void {
		$request = new QuoteRequest();
		$quoteRequestKey = substr(hash('sha256', $note->getQuote() . "\0" . $note->getUpdated() . "\0" . $note->getPublished()), 0, 24);
		$request->setId($note->getId() . '#quote-request-' . $quoteRequestKey);
		$request->setActor($actor);
		$request->setObjectId($note->getQuote());
		$request->setInstrument($note->getId());
		$request->setToArray([$author->getId()]);

		$inbox = ($author->getSharedInbox() !== '') ? $author->getSharedInbox() : $author->getInbox();
		if ($inbox === '') {
			return;
		}

		$request->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
		);

		try {
			$this->activityService->request($request);
		} catch (\Exception $e) {
			$this->logger->warning('could not ask for approval of a quote', [
				'quote' => $note->getQuote(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * The stored wire object is what a reload reads the language and edit
	 * stamp from; a local edit rewrites it or the next re-export sends the
	 * old text.
	 */
	private function snapshotSource(Stream $stream): void {
		$stream->setSource($stream->wireSource());
	}

	/**
	 * The language a post is written in: what the client said, else what the
	 * post already had, else the poster's default.
	 */
	private function languageFor(string $requested, Person $actor, string $current = ''): string {
		$requested = Stream::normalizeLanguage($requested);
		if ($requested !== '') {
			return $requested;
		}
		if ($current !== '') {
			return $current;
		}

		return $this->defaultLanguageOf($actor);
	}

	/**
	 * The poster's Nextcloud language, the best guess for what they write in.
	 * The region is dropped unless it names a different written language, so
	 * Mastodon's per-language filters (`de`, not `de-DE`) match.
	 */
	private function defaultLanguageOf(Person $actor): string {
		$user = ($actor->getUserId() !== '') ? $this->userManager->get($actor->getUserId()) : null;
		$language = Stream::normalizeLanguage($this->l10nFactory->getUserLanguage($user));
		if ($language === '') {
			return 'en';
		}

		$primary = explode('-', $language, 2)[0];

		return in_array($primary, self::REGIONAL_LANGUAGES, true) ? $language : $primary;
	}

	/**
	 * The hashtags and mentions the edited text names, parsed afresh, so a
	 * hashtag added or removed in an edit is linked and indexed accordingly.
	 * Mentions are only added: the post was already delivered to everyone it
	 * named, so a removed mention keeps its tag but leaves `mentions`.
	 */
	private function reapplyEntities(Stream $stream, string $content): void {
		$linkedBefore = $this->linkifyService->mentionedHrefs(
			FileCommentsService::plainText($stream->getContent()), $stream->getTags()
		);
		$hashtags = [];
		$mentions = [];
		foreach ($this->linkifyService->entitiesIn($content) as $entity) {
			match ($entity['type']) {
				LinkifyService::TYPE_MENTION => $mentions[] = $entity['name'],
				LinkifyService::TYPE_HASHTAG => $hashtags[] = $entity['name'],
				default => null,
			};
		}

		if ($stream instanceof Note) {
			// rebuilt rather than appended to, as StreamService rebuilds the
			// Emoji tags
			$stream->setTags(array_values(array_filter(
				$stream->getTags(),
				static fn (array $tag): bool => ($tag['type'] ?? '') !== 'Hashtag'
			)));
			$this->streamService->addHashtags($stream, array_values(array_unique($hashtags)));
		}

		$named = [];
		foreach ($stream->getTags('Mention') as $tag) {
			$named[strtolower(ltrim((string)($tag['name'] ?? ''), '@'))] = true;
		}

		$this->streamService->addRecipients(
			$stream,
			$stream->getVisibility(),
			array_values(array_filter(
				array_unique($mentions),
				static fn (string $mention): bool => !isset($named[strtolower($mention)])
			))
		);

		$gone = array_diff($linkedBefore, $this->linkifyService->mentionedHrefs($content, $stream->getTags()));
		if ($stream instanceof Note && $gone !== []) {
			$stream->setDetailArray(Details::MENTIONS, array_values(array_filter(
				$stream->getDetails(Details::MENTIONS),
				static fn (mixed $mention): bool => !is_array($mention) || !in_array($mention['url'] ?? '', $gone, true)
			)));
		}
	}

	/**
	 * The accounts and hashtags written into the text itself, from the same
	 * `LinkifyService` parse that builds the published links, so addressing,
	 * `tag` and markup always agree.
	 */
	public function fixRecipientAndHashtags(Post $post) {
		foreach ($this->linkifyService->entitiesIn($post->getContent()) as $entity) {
			match ($entity['type']) {
				LinkifyService::TYPE_MENTION => $post->addTo($entity['name']),
				LinkifyService::TYPE_HASHTAG => $post->addHashtag($entity['name']),
				default => null,
			};
		}
	}

	/**
	 * A reply's hashtags count for the replier's For you. Read as the viewer,
	 * and never allowed to fail the post.
	 */
	private function learnFromReply(Person $actor, string $replyTo): void {
		if ($replyTo === '') {
			return;
		}

		try {
			$parent = $this->streamService->getStreamById($replyTo, true);
			$this->interestService->recordAction(
				$actor, $parent, InterestService::ACTION_REPLY
			);
			$signals = $this->container?->get(RecommendationSignalService::class);
			if ($signals instanceof RecommendationSignalService) {
				$signals->recordAction($actor, $parent, 'reply');
			}
		} catch (\Throwable $e) {
			$this->logger->debug('[PostService] the post replied to is not one to learn from', ['exception' => $e]);
		}
	}
}
