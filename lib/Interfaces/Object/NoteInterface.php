<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use OCA\Social\AP;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FileCommentsService;
use OCA\Social\Service\ForwardService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PushService;
use OCA\Social\Service\ReplyRuleService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StatusRevisionService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tools\ActivityPubEvent;
use OCA\Social\Tools\Traits\TArrayTools;

class NoteInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	/** An edit may overtake the post it updates: keep it across that gap for one delivery window. */
	public const EARLY_UPDATE_NAMESPACE = 'social.early_update';
	public const EARLY_UPDATE_TTL = 21600;
	private const EARLY_UPDATE_KEY_PREFIX = 'update:';

	use TArrayTools;

	public function __construct(
		private StreamRequest $streamRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private PollService $pollService,
		private PushService $pushService,
		private StreamQueueService $streamQueueService,
		private LinkPreviewService $linkPreviewService,
		private ForwardService $forwardService,
		private NotificationService $notificationService,
		private StatusRevisionService $revisionService,
		private FileCommentsService $fileCommentsService,
		private ?ReplyRuleService $replyRules = null,
		private ?DurableCache $durableCache = null,
	) {
	}

	/**
	 * @throws ItemNotFoundException
	 */
	#[\Override]
	public function getItemById(string $id): ACore {
		try {
			return $this->streamRequest->getStreamById($id);
		} catch (StreamNotFoundException $e) {
			throw new ItemNotFoundException();
		}
	}

	/**
	 * @throws InvalidOriginException|ItemAlreadyExistsException
	 */
	#[\Override]
	public function activity(Acore $activity, ACore $item): void {
		/** @var Note $item */
		if ($activity->getType() === Create::TYPE) {
			$activity->checkOrigin($item->getId());
			// Mastodon attributes an incoming Create to the actor performing it,
			// whatever the object's `attributedTo` says; so does this, as the origin
			// check alone is host-wide. A Create without an actor fails the origin
			// check below.
			$item->setAttributedTo($activity->getActorId());
			$activity->checkOrigin($item->getAttributedTo());
			if (DeleteInterface::deletedBeforeArrival($this->durableCache, $item->getId(), $activity->getActorId())) {
				$this->forgetEarlyUpdate($item->getId());

				return;
			}
			$item->setActivityId($activity->getId());

			// asked before the save, because afterwards every delivery of this
			// activity looks alike and a re-delivery would be forwarded again
			$known = $this->isKnown($item->getId());
			$this->save($item);

			if (!$known) {
				$this->forwardService->forwardReply($activity, $item);
			}
			if ($this->isKnown($item->getId())) {
				$this->applyEarlyUpdate($item->getId(), $activity->getActorId(), $item->getAttributedTo());
			}
		}

		if ($activity->getType() === Delete::TYPE) {
			$activity->checkOrigin($item->getId());
			try {
				$this->getStoredForAuthor($activity, $item->getId());
			} catch (StreamNotFoundException $e) {
				return; // never received: nothing here to remove
			}
			$this->delete($item);
		}

		if ($activity->getType() === Update::TYPE) {
			$activity->checkOrigin($item->getId());
			$activity->checkOrigin($item->getAttributedTo());
			try {
				$stored = $this->getStoredForAuthor($activity, $item->getId());
			} catch (StreamNotFoundException $e) {
				$this->rememberEarlyUpdate($activity, $item);

				return;
			}
			if (self::isOlderEdit($item, $stored)) {
				return;
			}
			$item->setActivityId($activity->getId());
			$edited = self::isEdit($stored, $item);
			if (!$edited) {
				// not an edit: the post keeps the edit time it had, if any
				$item->setUpdated($stored->getUpdated());
			}
			$this->streamRequest->update($item);
			$this->queueMedia($item);
			if ($stored->getSubType() === 'Event' || $item->getSubType() === 'Event') {
				// Event facts live in a dedicated key in `details`, which the general
				// stream update leaves alone; merge just this key so a Gancio date or
				// venue change shows without losing counters, quote state or other
				// local metadata.
				$this->streamRequest->updateDetails($item, Details::EVENT);
			}
			if (in_array($stored->getSubType(), Stream::ARTICLE_SUBTYPES, true)
				|| in_array($item->getSubType(), Stream::ARTICLE_SUBTYPES, true)) {
				$this->streamRequest->updateDetails($item, Details::ARTICLE_TITLE);
			}
			if (!$edited) {
				return;
			}
			// the version being replaced, recorded as a local edit records one, for
			// `GET /statuses/{nid}/history` and the "edited" dialog
			$this->revisionService->recordEdit($stored, $item);
			$this->notificationService->onStatusEdited($item);
			$this->fileCommentsService->onReplyUpdated($item);
		}
	}

	/**
	 * Remember the latest authenticated edit until its Create arrives.
	 * Updates are already origin-checked above and must name the actor (or a
	 * channel they run) whose object they edit before they can be replayed.
	 */
	private function rememberEarlyUpdate(ACore $activity, ACore $item): void {
		if (!$item instanceof Stream || $this->durableCache === null || $item->getId() === '' || $activity->getActorId() === '') {
			return;
		}
		$author = $item->getAttributedTo();
		if ($author === '' || ($activity->getActorId() !== $author && !$this->ownsChannel($activity->getActorId(), $author))) {
			throw new InvalidOriginException('NoteInterface::rememberEarlyUpdate - actor does not own the post');
		}

		$key = self::EARLY_UPDATE_KEY_PREFIX . hash('sha256', $item->getId());
		$existing = $this->durableCache->getBuffer(self::EARLY_UPDATE_NAMESPACE, $key);
		$existingUpdated = is_array($existing) ? strtotime((string)($existing['updated'] ?? '')) : false;
		$incomingUpdated = $item->getUpdated() !== '' ? strtotime($item->getUpdated()) : false;
		if ($existingUpdated !== false && ($incomingUpdated === false || $incomingUpdated <= $existingUpdated)) {
			return;
		}

		$source = json_decode($activity->getSource(), true);
		$replay = is_array($source) && ($source['type'] ?? '') === Update::TYPE
			? $source : $activity->jsonSerialize();
		$this->durableCache->setBuffer(self::EARLY_UPDATE_NAMESPACE, $key, [
			'actor' => $activity->getActorId(),
			'author' => $author,
			'object' => $item->getId(),
			'origin' => $activity->getRoot()->getOriginAuthority(),
			'updated' => $item->getUpdated(),
			'received' => $activity->getRoot()->getOriginCreationTime(),
			'activity' => $replay,
		], self::EARLY_UPDATE_TTL);
	}

	/** Apply a remembered edit once its matching post has been durably created. */
	private function applyEarlyUpdate(string $objectId, string $actorId, string $authorId): void {
		if ($this->durableCache === null) {
			return;
		}
		$key = self::EARLY_UPDATE_KEY_PREFIX . hash('sha256', $objectId);
		$pending = $this->durableCache->getBuffer(self::EARLY_UPDATE_NAMESPACE, $key);
		if (!is_array($pending)) {
			return;
		}

		if (($pending['actor'] ?? '') !== $actorId
			|| ($pending['author'] ?? '') !== $authorId
			|| ($pending['object'] ?? '') !== $objectId
			|| !is_array($pending['activity'] ?? null)
			|| !is_string($pending['origin'] ?? null)
			|| $pending['origin'] === '') {
			$this->durableCache->removeBuffer(self::EARLY_UPDATE_NAMESPACE, $key);

			return;
		}

		$update = AP::instance()->getItemFromData($pending['activity'], deferAttachmentDownloads: true);
		if (!$update instanceof Update || !$update->hasObject() || $update->getActorId() !== $actorId) {
			$this->durableCache->removeBuffer(self::EARLY_UPDATE_NAMESPACE, $key);

			return;
		}
		$update->setOrigin($pending['origin'], SignatureService::ORIGIN_REQUEST, (int)($pending['received'] ?? 0));
		$this->activity($update, $update->getObject());
		$this->durableCache->removeBuffer(self::EARLY_UPDATE_NAMESPACE, $key);
	}

	private function forgetEarlyUpdate(string $objectId): void {
		if ($this->durableCache !== null && $objectId !== '') {
			$this->durableCache->removeBuffer(
				self::EARLY_UPDATE_NAMESPACE,
				self::EARLY_UPDATE_KEY_PREFIX . hash('sha256', $objectId)
			);
		}
	}

	/**
	 * Whether an incoming Update changes what the post says, as against only
	 * its poll's tallies, which Mastodon sends as `Update{Question}` without
	 * `updated`. An edit is what Mastodon counts as one: a change to the
	 * text, content warning, sensitivity, attachments or poll options.
	 */
	private static function isEdit(Stream $stored, Stream $incoming): bool {
		return $stored->getContent() !== $incoming->getContent()
			|| ($stored->getDetailsAll()[Details::ARTICLE_TITLE] ?? '') !== ($incoming->getDetailsAll()[Details::ARTICLE_TITLE] ?? '')
			|| $stored->getSummary() !== $incoming->getSummary()
			|| $stored->isSensitive() !== $incoming->isSensitive()
			|| self::attachmentsOf($stored) !== self::attachmentsOf($incoming)
			|| self::pollOf($stored) !== self::pollOf($incoming)
			|| ActivityPubEvent::fromStream($stored) !== ActivityPubEvent::fromStream($incoming);
	}

	/** @return list<array{?string, string}> each attachment's origin and description */
	private static function attachmentsOf(Stream $stream): array {
		$attachments = [];
		foreach ($stream->getAttachments() as $attachment) {
			if ($attachment instanceof MediaAttachment) {
				$attachments[] = [$attachment->getRemoteUrl(), $attachment->getDescription()];
			}
		}

		return $attachments;
	}

	/** @return array{}|array{bool, list<string>} whether the poll takes several answers, and its options */
	private static function pollOf(Stream $stream): array {
		if (!($stream instanceof Question)) {
			return [];
		}

		return [$stream->isMultiple(), array_column($stream->getOptions(), 'title')];
	}

	/**
	 * The stored copy of the note an Update or Delete refers to, provided the
	 * activity comes from the note's author: the origin check compares only
	 * hosts. Mastodon looks the status up by uri and account; here a mismatch
	 * is refused out loud, as a foreign origin is.
	 *
	 * @throws StreamNotFoundException nothing is stored under that id
	 * @throws InvalidOriginException the actor is not the stored author
	 */
	private function getStoredForAuthor(ACore $activity, string $id): Stream {
		$stored = $this->streamRequest->getStreamById($id);
		if ($stored->getAttributedTo() !== $activity->getActorId()
			&& !$this->ownsChannel($activity->getActorId(), $stored->getAttributedTo())) {
			throw new InvalidOriginException(
				'NoteInterface::getStoredForAuthor - actor: ' . $activity->getActorId()
				. ' - attributedTo: ' . $stored->getAttributedTo()
			);
		}

		return $stored;
	}

	/**
	 * Whether an incoming edit is older, by its `updated`, than the stored
	 * version: edits can arrive out of order, and Mastodon also drops an older
	 * one. Either side without a date is applied.
	 */
	private static function isOlderEdit(Stream $incoming, Stream $stored): bool {
		$incomingTime = ($incoming->getUpdated() === '') ? false : strtotime($incoming->getUpdated());
		$storedTime = ($stored->getUpdated() === '') ? false : strtotime($stored->getUpdated());

		return $incomingTime !== false && $storedTime !== false && $incomingTime < $storedTime;
	}

	/**
	 * Whether `$actorId` is the account behind the channel `$authorId`.
	 *
	 * PeerTube files a video under its channel, a `Group`, and sends the
	 * video's `Update` and `Delete` as the account that owns the channel,
	 * which the channel's own document names; see `Person::isRunBy()`.
	 */
	private function ownsChannel(string $actorId, string $authorId): bool {
		try {
			return $this->cacheActorsRequest->getFromId($authorId)->isRunBy($actorId);
		} catch (CacheActorDoesNotExistException $e) {
			return false;
		}
	}

	private function isKnown(string $id): bool {
		try {
			$this->streamRequest->getStreamById($id);

			return true;
		} catch (StreamNotFoundException $e) {
			return false;
		}
	}

	#[\Override]
	public function save(ACore $item): void {
		/** @var Note $note */
		$note = $item;
		$this->checkAuthorship($note);

		// a bare note replying to one of our polls with an option as its name
		// is a vote: counted, never stored as a timeline item
		if ($this->pollService->handleIncomingVote($note)) {
			return;
		}

		try {
			$this->streamRequest->getStreamById($note->getId());
		} catch (StreamNotFoundException $e) {
			if ($this->refusedReply($note)) {
				return;
			}
			if ($note->getVisibility() === '') {
				$note->setVisibility($this->estimateVisibility($note));
			}
			// marked before the save, so the row carries what the queue has to fetch.
			// Both, never one or the other: a quote of a reply brings two unknown
			// posts, and they travel in the one cache the queue entry works through
			$fetchParent = $this->markUnknownParent($note);
			$fetchQuote = $this->markUnknownQuote($note);
			// nothing below is for a post that was not written now: a
			// redelivery overlapping the first copy passes the lookup above
			// and is refused by the database instead
			if (!$this->streamRequest->save($note)) {
				return;
			}
			$this->updateDetails($note);
			$this->generateNotification($note);
			// the bell on a profile: whoever asked to be told when this account
			// posts, as against whoever it mentioned
			$this->notificationService->onNewStatus($note);
			$this->pushService->onNewStream($note->getId());
			$this->queueMedia($note);
			$this->queueLinkPreview($note);
			$this->fileCommentsService->onReply($note);
			if ($fetchParent || $fetchQuote) {
				$this->streamQueueService->generateStreamQueue(
					$note->getRequestToken(), StreamQueue::TYPE_CACHE, $note->getId()
				);
			}
		}
	}

	/**
	 * Whether the note is a reply the author of one of this instance's own
	 * posts does not let through (`ReplyRuleService`): not kept, from
	 * another server or from Bluesky alike, as a reply from here is refused.
	 */
	private function refusedReply(Note $note): bool {
		if ($this->replyRules === null || $note->getInReplyTo() === '') {
			return false;
		}
		try {
			$parent = $this->streamRequest->getStreamById($note->getInReplyTo());
		} catch (StreamNotFoundException) {
			return false;
		}

		return $this->replyRules->refusal($parent, $note->getAttributedTo()) !== '';
	}

	/**
	 * Has a reply's missing parent fetched: into the note's cache and the
	 * note into the stream queue, as an Announce's object is, so the fetch
	 * happens after the inbox request and is retried if the parent's server
	 * is down. Each fetched parent queues its own in turn;
	 * `StreamQueueService` stamps each ancestor with its depth and the climb
	 * stops at the cap.
	 *
	 * @return bool whether a queue entry is needed once the note is saved
	 */
	private function markUnknownParent(Note $note): bool {
		$parent = $note->getInReplyTo();
		if ($parent === '' || $this->isKnown($parent)) {
			return false;
		}

		if ($note->getDetailInt(Details::ANCESTOR_DEPTH)
			>= StreamQueueService::MAX_ANCESTOR_DEPTH) {
			return false;
		}

		$note->addCacheItem($parent);

		return true;
	}

	/**
	 * Has the post an incoming quote names fetched the way a reply's parent
	 * is, through the stream queue; until it is here the client is told the
	 * quote is `pending`. The depth counter is shared with the reply climb,
	 * so quote chains are bounded too.
	 *
	 * @return bool whether a queue entry is needed once the note is saved
	 */
	private function markUnknownQuote(Note $note): bool {
		$quoted = $note->getQuote();
		if ($quoted === '' || $this->isKnown($quoted)) {
			return false;
		}

		if ($note->getDetailInt(Details::ANCESTOR_DEPTH)
			>= StreamQueueService::MAX_ANCESTOR_DEPTH) {
			return false;
		}

		$note->addCacheItem($quoted);

		return true;
	}

	/**
	 * A post stored with attachments whose files are not here yet -- the
	 * inbox could not finish them, or the path that brought the post defers
	 * its downloads -- has them fetched by the queue rather than by the first
	 * reader's request. Such an attachment is one with no link, no recorded
	 * refusal, and an origin (`Document::convertToMediaAttachment()`).
	 */
	private function queueMedia(Stream $note): void {
		foreach ($note->getAttachments() as $attachment) {
			if ($attachment instanceof MediaAttachment
				&& $attachment->getUrl() === ''
				&& $attachment->getCacheError() === 0
				&& ($attachment->getRemoteUrl() ?? '') !== '') {
				$this->streamQueueService->queueMedia($note->getRequestToken(), $note->getId());

				return;
			}
		}
	}

	/**
	 * A public or unlisted post that links somewhere gets its preview read by
	 * a background job, so a slow web server cannot hold up the inbox. A
	 * followers-only or direct post never does (`LinkPreviewService::generate()`).
	 */
	private function queueLinkPreview(Note $note): void {
		if (!$note->isOpenToAnyone()
			|| $this->linkPreviewService->extractUrl($note->getContent()) === '') {
			return;
		}

		$this->streamQueueService->generateStreamQueue(
			$note->getRequestToken(), StreamQueue::TYPE_LINK_PREVIEW, $note->getId()
		);
	}

	/**
	 * A note lives on its author's instance, so `attributedTo` must share the
	 * host of the note's own id: the fetch-and-store paths (a cached announced
	 * object, a synced outbox) have no request origin to compare against.
	 *
	 * @throws InvalidOriginException
	 */
	private function checkAuthorship(Note $note): void {
		$noteHost = parse_url($note->getId(), PHP_URL_HOST);
		$authorHost = parse_url($note->getAttributedTo(), PHP_URL_HOST);

		if (!is_string($noteHost) || $noteHost === ''
			|| !is_string($authorHost)
			|| strtolower($noteHost) !== strtolower($authorHost)) {
			throw new InvalidOriginException(
				'NoteInterface::checkAuthorship - id: ' . $note->getId()
				. ' - attributedTo: ' . $note->getAttributedTo()
			);
		}
	}

	#[\Override]
	public function delete(ACore $item): void {
		/** @var Note $item */
		// guarded by type so that a note's id never takes a row of another kind
		// with it; a poll is stored as the `Question` it is
		$type = ($item->getType() === Question::TYPE) ? Question::TYPE : Note::TYPE;
		$this->streamRequest->deleteById($item->getId(), $type);
		$this->linkPreviewService->deleteCard($item->getId());
		$this->fileCommentsService->onDeleted($item);
		// the post it answered counts one reply fewer now; `updateDetails()`
		// recounts, so it runs after the row has gone
		$this->updateDetails($item);
	}

	public function updateDetails(Note $stream): void {
		// the recount lives on the request because the other thing that changes
		// a reply count — a local post being deleted, which never reaches this
		// interface — has to do exactly the same arithmetic
		$this->streamRequest->recountReplies($stream->getInReplyTo());
		$this->streamRequest->recountQuotes($stream->getQuote());
	}

	/**
	 * A remote note carries no Mastodon-style visibility field; estimate it
	 * from its addressing the way Mastodon serialises it: as:Public in `to`
	 * is public, as:Public in `cc` is unlisted, the author's followers
	 * collection makes it followers-only, anything else is a direct message.
	 */
	private function estimateVisibility(Note $note): string {
		if (in_array(ACore::CONTEXT_PUBLIC, $note->getToAll(), true)) {
			return Stream::TYPE_PUBLIC;
		}
		if (in_array(ACore::CONTEXT_PUBLIC, $note->getCcArray(), true)) {
			return Stream::TYPE_UNLISTED;
		}

		try {
			$author = $this->cacheActorsRequest->getFromId($note->getAttributedTo());
			if ($author->getFollowers() !== ''
				&& in_array($author->getFollowers(), array_merge($note->getToAll(), $note->getCcArray()), true)) {
				return Stream::TYPE_FOLLOWERS;
			}
		} catch (CacheActorDoesNotExistException $e) {
		}

		return Stream::TYPE_DIRECT;
	}

	private function generateNotification(Note $note): void {
		$mentions = $note->getTags('Mention');
		if (empty($mentions)) {
			return;
		}

		/** @var SocialAppNotificationInterface $notificationInterface */
		$notificationInterface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);
		$post = $this->streamRequest->getStreamById($note->getId(), false, ACore::FORMAT_LOCAL);

		foreach ($mentions as $mention) {
			try {
				$recipient = $this->cacheActorsRequest->getFromId($this->get('href', $mention));
				if (!$recipient->isLocal()) { // only interested on local
					throw new CacheActorDoesNotExistException();
				}
			} catch (CacheActorDoesNotExistException $e) {
				continue;
			}

			/** @var SocialAppNotification $notification */
			$notification = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
			$notification->setDetailItem(Details::POST, $post);
			$notification->addDetail(Details::ACCOUNT, $post->getActor()->getAccount());
			// the author, not the reader: the notification timeline finds this row
			// by its recipient rows, so `attributedTo` says who did the thing, as on
			// every other notification, and the block and mute filter compares it
			$notification->setAttributedTo($post->getActor()->getId())
				->setSubType(Mention::TYPE)
				// one row per recipient: a post mentioning three people wrote
				// one id three times, and only the first of them survived
				->setId($post->getId() . '/notification+mention/' . md5($recipient->getId()))
				->setSummary('{account} mentioned you in a post')
				->setObjectId($post->getId())
				->setTo($recipient->getId())
				->setLocal(true);

			$notificationInterface->save($notification);
		}
	}
}
