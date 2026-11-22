<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use Exception;
use OCA\Social\Activity\Publisher as ActivityPublisher;
use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\Client\StoryInteraction;
use OCA\Social\Model\Details;
use OCA\Social\Model\NotificationDelivery;
use OCA\Social\Reference\PostReferenceProvider;
use OCP\BackgroundJob\IJobList;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Raises the Nextcloud notification (bell, mobile app, mail digest) for each
 * stored in-app notification, and dismisses in-app notifications.
 *
 * Hooked to `SocialAppNotificationInterface::save()`, the one path that writes
 * a notification row, so the bell and the in-app list cannot disagree.
 * Nothing is raised for a notification the reader's policy holds for review
 * or about a muted thread, though the row is still stored; what is raised may
 * be held by `NotificationDelivery` for a digest or quiet hours.
 */
class NotificationService {
	/**
	 * Mastodon's notification type for each sub-type that gets a Nextcloud
	 * notification, which is also the subject `Notifier` renders it from.
	 * `Stream::NOTIFICATION_TYPES` answers a different question (what a client
	 * may filter on); NotificationServiceTest checks the two against each other.
	 */
	public const SUBJECTS = [
		Mention::TYPE => 'mention',
		Like::TYPE => 'favourite',
		Announce::TYPE => 'reblog',
		Follow::TYPE => 'follow',
		Follow::TYPE_REQUEST => 'follow_request',
		Update::TYPE => 'update',
		// Events this instance raises itself: a poll the reader voted in closing,
		// and a post from an account whose bell they rang.
		Stream::SUBTYPE_POLL => 'poll',
		Stream::SUBTYPE_STATUS => 'status',
	];

	/** The `object_type` every notification of this app is filed under. */
	public const OBJECT = 'notification';

	/** How many rows one pass of clear() removes before asking for the next. */
	private const CLEAR_PAGE = ProbeOptions::MAX_LIMIT;

	/**
	 * The most notifications one clear() call will dismiss, each costing two
	 * queries, so a large backlog cannot time out the request; a client calls
	 * again for the rest.
	 */
	private const CLEAR_MAX = 5000;

	public function __construct(
		private StreamRequest $streamRequest,
		private StreamService $streamService,
		private ActorsRequest $actorsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private ActorRelationRequest $actorRelationRequest,
		private ActionsRequest $actionsRequest,
		private AccountRelationService $accountRelationService,
		private FollowsRequest $followsRequest,
		private NotificationDeliveryService $deliveryService,
		private INotificationManager $notificationManager,
		private ActivityPublisher $activityPublisher,
		private LoggerInterface $logger,
		private IURLGenerator $urlGenerator,
		private NotificationPolicyService $notificationPolicyService,
		private ConversationsRequest $conversationsRequest,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * Tells Nextcloud about a notification row that was just stored. Never
	 * throws: it runs from the inbox, which must still accept the activity.
	 */
	public function onNotification(SocialAppNotification $notification, string $actorId = ''): void {
		$pushEligible = false;
		try {
			$pushEligible = $this->emit($notification, $actorId);
		} catch (Exception $e) {
			$this->logger->warning('could not raise a notification', ['exception' => $e]);
		}
		if ($pushEligible) {
			$this->queueMastodonPush($notification);
		}
		// the open pages' badges are read again, whatever the bell does
		$this->pushService()?->onNotification($notification->getTo());
	}

	/**
	 * Resolved lazily because of a service cycle.
	 */
	private function pushService(): ?PushService {
		try {
			$push = $this->container?->get(PushService::class);
		} catch (\Throwable) {
			return null;
		}

		return $push instanceof PushService ? $push : null;
	}

	/**
	 * Queues the Web Push of a notification, only for an account with a push
	 * subscription.
	 */
	private function queueMastodonPush(SocialAppNotification $notification): void {
		try {
			$userId = $this->actorsRequest->getFromId($notification->getTo())->getUserId();
			if ($userId !== '' && $notification->getNid() !== '' && $notification->getNid() !== '0') {
				$subscriptions = $this->container?->get(PushSubscriptionService::class);
				if ($subscriptions instanceof PushSubscriptionService && !$subscriptions->hasAny($userId)) {
					return;
				}
				$jobList = $this->container?->get(IJobList::class);
				if ($jobList instanceof IJobList) {
					$jobList->add(\OCA\Social\Cron\MastodonPushNotification::class, [
						'user' => $userId,
						'nid' => (string)$notification->getNid(),
					]);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('could not queue a Mastodon Web Push notification', ['exception' => $e]);
		}
	}

	/**
	 * Mastodon's `update`: the local people who boosted a post are told when its
	 * author edits it. Remote boosters are notified by their own server.
	 */
	public function onStatusEdited(Stream $post): void {
		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);
		} catch (Exception $e) {
			return;
		}

		$author = $this->accountOf($post->getAttributedTo());

		// Part of the id, so each edit is its own notification.
		$edit = ($post->getUpdated() !== '') ? $post->getUpdated() : (string)time();

		foreach ($this->actionsRequest->getByObjectId($post->getId()) as $action) {
			if ($action->getType() !== Announce::TYPE
				|| $action->getActorId() === $post->getAttributedTo()
				|| !$this->isLocal($action->getActorId())) {
				continue;
			}

			try {
				/** @var SocialAppNotification $item */
				$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
				$item->setDetailItem(Details::POST, $post);
				$item->addDetail(Details::ACCOUNT, $author);
				$item->setAttributedTo($post->getAttributedTo())
					->setSubType(Update::TYPE)
					->setId(
						$post->getId() . '/notification+update/'
						. substr(sha1($action->getActorId() . '/' . $edit), 0, 16)
					)
					->setSummary('{account} edited a post you boosted')
					->setObjectId($post->getId())
					->setTo($action->getActorId())
					->setLocal(true);

				$interface->save($item);
			} catch (Exception $e) {
				$this->logger->warning('could not store an edit notification', ['exception' => $e]);
			}
		}
	}

	/**
	 * Tells the local subscribers of an account's bell (`POST /accounts/{id}/follow`
	 * with `notify`) that it has posted. Replies do not count, as in Mastodon;
	 * remote subscribers are told by their own server.
	 */
	public function onNewStatus(Stream $post): void {
		if ($post->getInReplyTo() !== '') {
			return;
		}

		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);
			$stored = $this->streamRequest->getStreamById($post->getId(), false, ACore::FORMAT_LOCAL);
		} catch (Exception $e) {
			return;
		}

		$author = $this->accountOf($post->getAttributedTo());

		foreach ($this->accountRelationService->subscribersOf($post->getAttributedTo()) as $subscriber) {
			if (!$this->isLocal($subscriber)) {
				continue;
			}

			try {
				$interface->save($this->statusNotification($stored, $author, $subscriber));
			} catch (Exception $e) {
				$this->logger->warning('could not store a status notification', ['exception' => $e]);
			}
		}
	}

	/**
	 * The bell's notification of a post, one per post and subscriber whichever
	 * way it came.
	 */
	private function statusNotification(Stream $stored, string $author, string $subscriber): SocialAppNotification {
		/** @var SocialAppNotification $item */
		$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
		$item->setDetailItem(Details::POST, $stored);
		$item->addDetail(Details::ACCOUNT, $author);
		$item->setAttributedTo($stored->getAttributedTo())
			->setSubType(Stream::SUBTYPE_STATUS)
			->setId($stored->getId() . '/notification+status/' . md5($subscriber))
			->setSummary('{account} posted')
			->setObjectId($stored->getId())
			->setTo($subscriber)
			->setLocal(true);

		return $item;
	}

	/**
	 * Tells the people who voted in a poll that it has closed, and its author,
	 * as Mastodon does.
	 *
	 * @param string[] $voters actor ids
	 */
	public function onPollClosed(Stream $poll, array $voters): void {
		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);
			$stored = $this->streamRequest->getStreamById($poll->getId(), false, ACore::FORMAT_LOCAL);
		} catch (Exception $e) {
			return;
		}

		$author = $this->accountOf($poll->getAttributedTo());

		foreach (array_unique(array_merge($voters, [$poll->getAttributedTo()])) as $reader) {
			if (!$this->isLocal($reader)) {
				continue;
			}

			try {
				/** @var SocialAppNotification $item */
				$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
				$item->setDetailItem(Details::POST, $stored);
				$item->addDetail(Details::ACCOUNT, $author);
				$item->setAttributedTo($poll->getAttributedTo())
					->setSubType(Stream::SUBTYPE_POLL)
					->setId($poll->getId() . '/notification+poll/' . md5($reader))
					->setSummary('A poll you voted in has ended')
					->setObjectId($poll->getId())
					->setTo($reader)
					->setLocal(true);

				$interface->save($item);
			} catch (Exception $e) {
				$this->logger->warning('could not store a poll notification', ['exception' => $e]);
			}
		}
	}

	/**
	 * Tells a local account what a moderator decided about it, so a Mastodon
	 * client can show it too.
	 */
	public function onModerationWarning(string $actorId, string $action, string $text): void {
		if (!$this->isLocal($actorId)) {
			// No activity tells a remote instance its user was warned.
			return;
		}

		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);

			/** @var SocialAppNotification $item */
			$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
			$item->addDetail(Details::ACTION, $action);
			$item->addDetail(Details::TEXT, $text);
			$item->setAttributedTo($actorId)
				->setSubType(Stream::SUBTYPE_WARNING)
				->setId($actorId . '/notification+warning/' . md5($action . '/' . $text . '/' . time()))
				->setSummary('A moderator of this server has acted on your account')
				->setTo($actorId)
				->setLocal(true);

			$interface->save($item);
		} catch (Exception $e) {
			$this->logger->warning('could not store a moderation notification', ['exception' => $e]);
		}
	}

	/**
	 * Tells the poster of a short that somebody answered it, and nobody else.
	 * The notification carries the text, since the short is gone in a day.
	 */
	public function onStoryInteraction(Story $story, StoryInteraction $interaction): void {
		if (!$this->isLocal($story->getOwnerId()) || $story->getOwnerId() === $interaction->getActorId()) {
			return;
		}

		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);

			/** @var SocialAppNotification $item */
			$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
			$reply = ($interaction->getType() === StoryInteraction::TYPE_REPLY);

			$item->addDetail(Details::STORY_ID, (string)$story->getId());
			$item->addDetail(Details::CONTENT, $interaction->getContent());
			$item->addDetail(Details::ACCOUNT, $this->accountOf($interaction->getActorId()));
			// attributedTo is who answered, which the client draws as the account.
			$item->setAttributedTo($interaction->getActorId())
				->setSubType($reply ? Stream::SUBTYPE_STORY_REPLY : Stream::SUBTYPE_STORY_REACT)
				->setId($story->getOwnerId() . '/notification+story/' . md5($interaction->getSourceId()))
				->setSummary($reply ? '{account} replied to your story' : '{account} reacted to your story')
				->setTo($story->getOwnerId())
				->setLocal(true);

			$interface->save($item);
		} catch (Exception $e) {
			$this->logger->warning('could not store a story notification', ['exception' => $e]);
		}
	}

	/**
	 * Tells a local account it has been named in a photo; a remote one learns
	 * it from the post's `Mention`.
	 */
	public function onPhotoTag(Stream $post, Person $author, Person $tagged): void {
		if (!$this->isLocal($tagged->getId()) || $tagged->getId() === $author->getId()) {
			return;
		}

		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);

			/** @var SocialAppNotification $item */
			$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
			$item->addDetail(Details::ACCOUNT, $this->accountOf($author->getId()));
			$item->setAttributedTo($author->getId())
				->setSubType(Stream::SUBTYPE_PHOTO_TAG)
				->setId($post->getId() . '/notification+tagged/' . md5($tagged->getId()))
				->setSummary('{account} named you in a photo')
				->setObjectId($post->getId())
				->setTo($tagged->getId())
				->setLocal(true);

			$interface->save($item);
		} catch (Exception $e) {
			$this->logger->warning('could not store a photo-tag notification', ['exception' => $e]);
		}
	}

	/**
	 * Tells a local account what only Bluesky tells (`Stream::SUBTYPE_BLUESKY_*`):
	 * `$actorId` did it, to `$objectId` where it was done to a post — the post
	 * the account reposted, for a like or repost of the repost. One of each
	 * per `$what`, so Bluesky saying it twice is told once.
	 */
	public function onBlueskyEvent(string $subType, string $recipientId, string $actorId, string $objectId, string $summary, string $what): void {
		if (!$this->isLocal($recipientId)) {
			return;
		}

		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);

			/** @var SocialAppNotification $item */
			$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
			if ($objectId !== '') {
				$item->setDetailItem(Details::POST, $this->streamRequest->getStreamById($objectId, false, ACore::FORMAT_LOCAL));
				$item->setObjectId($objectId);
			}
			$item->addDetail(Details::ACCOUNT, $this->accountOf($actorId));
			$item->setAttributedTo($actorId)
				->setSubType($subType)
				->setId($actorId . '/notification+' . $subType . '/' . md5($recipientId . '|' . $what))
				->setSummary($summary)
				->setTo($recipientId)
				->setLocal(true);

			$interface->save($item);
		} catch (Exception $e) {
			$this->logger->warning('could not store a Bluesky notification', ['subtype' => $subType, 'exception' => $e]);
		}
	}

	/**
	 * Tells one local account that an account it rang the bell of on Bluesky
	 * has posted: the bell's own notification, under the same id, so a bell
	 * rung here as well tells once.
	 */
	public function onSubscribedPost(string $postId, string $subscriberId): void {
		if (!$this->isLocal($subscriberId)) {
			return;
		}

		try {
			$stored = $this->streamRequest->getStreamById($postId, false, ACore::FORMAT_LOCAL);
			if ($stored->getInReplyTo() !== '') {
				return;
			}
			AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE)
				->save($this->statusNotification($stored, $this->accountOf($stored->getAttributedTo()), $subscriberId));
		} catch (Exception $e) {
			$this->logger->warning('could not store a status notification', ['exception' => $e]);
		}
	}

	/**
	 * Tells a local account that a domain block deleted its follows with that
	 * domain.
	 */
	public function onRelationshipsSevered(string $actorId, string $domain, int $lost): void {
		if ($lost < 1 || !$this->isLocal($actorId)) {
			return;
		}

		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);

			/** @var SocialAppNotification $item */
			$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
			$item->addDetail(Details::TARGET_NAME, $domain);
			$item->addDetail(Details::RELATIONSHIPS_COUNT, (string)$lost);
			$item->setAttributedTo($actorId)
				->setSubType(Stream::SUBTYPE_SEVERED)
				->setId($actorId . '/notification+severed/' . md5($domain))
				->setSummary('Your follows of {target_name} were removed when it was blocked')
				->setTo($actorId)
				->setLocal(true);

			$interface->save($item);
		} catch (Exception $e) {
			$this->logger->warning('could not store a severed-relationships notification', ['exception' => $e]);
		}
	}

	/**
	 * One notification of the viewer's, as `/api/v1/notifications` would have
	 * served it.
	 *
	 * @throws ItemNotFoundException the id names no notification of the
	 *                               viewer's, including somebody else's
	 */
	public function get(Person $viewer, int|string $id): Stream {
		return $this->find($viewer, $id);
	}

	/**
	 * Dismisses one notification. The row is deleted, as Mastodon's dismiss is
	 * final; the post and the Like or Announce behind it are untouched.
	 *
	 * @throws ItemNotFoundException
	 */
	public function dismiss(Person $viewer, int|string $id): void {
		$notification = $this->find($viewer, $id);

		$this->streamRequest->deleteById($notification->getId(), SocialAppNotification::TYPE);
		$this->forget($viewer, $notification->getId());
	}

	/**
	 * Dismisses every notification the viewer has, up to CLEAR_MAX, and answers
	 * how many that was. The cursor only moves down, so a delete that matched
	 * nothing cannot loop forever.
	 */
	public function clear(Person $viewer): int {
		$cleared = 0;
		$maxId = 0;

		while ($cleared < self::CLEAR_MAX) {
			$page = $this->page($viewer, min(self::CLEAR_PAGE, self::CLEAR_MAX - $cleared), $maxId);
			if ($page === []) {
				return $cleared;
			}

			foreach ($page as $notification) {
				$this->streamRequest->deleteById($notification->getId(), SocialAppNotification::TYPE);
				$this->forget($viewer, $notification->getId());
				$maxId = $notification->getNid();
				$cleared++;
			}
		}

		$this->logger->info(
			'stopped clearing notifications at the per-call ceiling of ' . self::CLEAR_MAX
			. '; the account has more left to dismiss',
			['actor' => $viewer->getId()]
		);

		return $cleared;
	}

	/**
	 * @throws Exception
	 */
	private function emit(SocialAppNotification $notification, string $actorId): bool {
		$subject = self::SUBJECTS[$notification->getSubType()] ?? '';
		if ($subject === '') {
			// No client name and no wording for it.
			return false;
		}

		$recipientId = $notification->getTo();
		$actorId = ($actorId !== '') ? $actorId : $this->actorOf($notification);
		if ($recipientId === '' || $actorId === '') {
			return false;
		}
		if ($recipientId === $actorId && $subject !== 'poll') {
			// Not news to the actor themselves, except their own poll closing, as in Mastodon.
			return false;
		}

		try {
			$recipient = $this->actorsRequest->getFromId($recipientId);
		} catch (Exception $e) {
			return false; // not an account of this server: there is no user to tell
		}

		if ($recipient->getUserId() === '' || $this->isSuppressed($recipientId, $actorId)) {
			return false;
		}

		$actor = $this->cachedActor($actorId);
		if ($this->notificationPolicyService->isHeldFor(
			$recipient, $actorId, $actor?->getCreation() ?? 0,
			$subject === 'mention' && $this->isDirectMessage($notification)
		) || $this->isInMutedThread($notification, $recipientId)) {
			return false;
		}

		$local = $this->localActor($actorId);
		$parameters = [
			'account' => ($actor === null) ? $actorId : $this->labelOf($actor),
			// Into this app, not to the remote object.
			'link' => in_array($subject, ['follow', 'follow_request'], true)
				? $this->profileLink($actor, $actorId)
				: $this->postLink($notification),
			'avatar' => $this->avatarOf($local, $actor),
		];
		if ($subject === 'follow_request' && $actor !== null && $actor->getNid() > 0) {
			// what Notifier builds the Accept and Decline actions from
			$parameters['nid'] = $actor->getNid();
		}

		if (!$this->isHeld($notification, $subject, $recipient->getUserId(), $recipientId, $actorId)) {
			$raised = $this->notificationManager->createNotification();
			$raised->setApp('social')
				->setDateTime(new DateTime('now'))
				->setUser($recipient->getUserId())
				->setObject(self::OBJECT, $this->objectId($notification->getId()))
				->setSubject($subject, $parameters);

			$this->notificationManager->notify($raised);
		}

		// The same news in the Activity app, with a local actor as their Nextcloud user.
		$activityActor = $actor;
		if ($local !== null) {
			$activityActor ??= $local;
			$activityActor->setUserId($local->getUserId());
		}
		$this->activityPublisher->publish(
			$recipient->getUserId(), $subject, $activityActor, $actorId, $parameters['link'],
			$this->excerptOf($notification), $notification->getNid()
		);

		return true;
	}

	/**
	 * Whether the bell stays quiet about this one because the reader chose a
	 * digest or is in quiet hours; the stored row is untouched. The follow lookup
	 * is made only for a mention, the one subject it matters for.
	 */
	private function isHeld(
		SocialAppNotification $notification,
		string $subject,
		string $userId,
		string $recipientId,
		string $actorId,
	): bool {
		$kind = ($subject === 'mention' && $this->isDirectMessage($notification))
			? NotificationDelivery::SUBJECT_DIRECT : $subject;
		$followed = ($kind === NotificationDelivery::SUBJECT_MENTION) && $this->follows($recipientId, $actorId);

		return $this->deliveryService->holds($userId, $kind, $followed);
	}

	/**
	 * Whether the post the notification is about belongs to a thread the
	 * recipient muted. The walk up to the thread's root is made only for a
	 * recipient who has muted something.
	 */
	private function isInMutedThread(SocialAppNotification $notification, string $recipientId): bool {
		$post = $this->postOf($notification);
		if ($post === null) {
			return false;
		}

		$muted = $this->conversationsRequest->getMutedRoots($recipientId);
		if ($muted === []) {
			return false;
		}

		return in_array($this->conversationsRequest->rootOf($post->getId()), $muted, true);
	}

	/**
	 * A mention in a post addressed to its readers alone; a direct message
	 * reaches the bell as the mention it carries.
	 */
	private function isDirectMessage(SocialAppNotification $notification): bool {
		return $this->postOf($notification)?->getVisibility() === Stream::TYPE_DIRECT;
	}

	/** Whether the reader follows the actor, as the follows table has it. */
	private function follows(string $recipientId, string $actorId): bool {
		try {
			return $this->followsRequest->getByPersons($recipientId, $actorId)->isAccepted();
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * The post an entry is about: the copy the caller passed along where
	 * there is one, else the stored one, else nothing.
	 */
	private function postOf(SocialAppNotification $notification): ?Stream {
		$post = $notification->getDetailItem(Details::POST);
		if ($post instanceof Stream) {
			return $post;
		}
		if ($notification->getObjectId() === '') {
			return null;
		}

		try {
			return $this->streamRequest->getStreamById($notification->getObjectId());
		} catch (Exception $e) {
			return null;
		}
	}

	/** The post an entry is about, as plain text; nothing when there is no post. */
	private function excerptOf(SocialAppNotification $notification): string {
		$post = $this->postOf($notification);

		return ($post === null) ? '' : PostReferenceProvider::excerpt($post->getContent(), 200);
	}

	/**
	 * A follow request was answered: its bell entry is removed with the row
	 * behind it. Never throws.
	 */
	public function onFollowRequestAnswered(Person $viewer, string $followerId): void {
		try {
			foreach ($this->page($viewer, self::CLEAR_PAGE) as $row) {
				if ($row->getSubType() === Follow::TYPE_REQUEST
					&& $row->getAttributedTo() === $followerId) {
					$this->dismiss($viewer, $row->getNid());
				}
			}
		} catch (Exception $e) {
			$this->logger->debug('could not take a follow request off the bell', ['exception' => $e]);
		}
	}

	/** This app's page for an account, or the actor's own address when it has no handle here. */
	private function profileLink(?Person $actor, string $actorId): string {
		$acct = $this->acctOf($actor);

		return ($acct === '') ? $actorId : $this->appLink('@' . $acct);
	}

	/**
	 * This app's page for the post a notification is about, or the post's
	 * own address when it is not one this instance holds.
	 */
	private function postLink(SocialAppNotification $notification): string {
		$post = $this->postOf($notification);
		if ($post === null) {
			return $notification->getObjectId();
		}

		$acct = $this->acctOf($this->cachedActor($post->getAttributedTo()));
		if ($acct === '') {
			try {
				$acct = $this->actorsRequest->getFromId($post->getAttributedTo())->getPreferredUsername();
			} catch (Exception $e) {
				$acct = '';
			}
		}
		if ($acct === '' || $post->getNid() < 1) {
			return $notification->getObjectId();
		}

		return $this->appLink('@' . $acct . '/' . $post->getNid());
	}

	private function acctOf(?Person $actor): string {
		if ($actor === null) {
			return '';
		}

		return ($actor->getAccount() !== '') ? $actor->getAccount() : $actor->getPreferredUsername();
	}

	private function appLink(string $path): string {
		return rtrim($this->urlGenerator->linkToRouteAbsolute('social.Navigation.navigate'), '/') . '/' . $path;
	}

	/**
	 * Who acted: `attributedTo`, except for a mention, whose row is attributed to
	 * the account told, so the author is read off the post.
	 */
	private function actorOf(SocialAppNotification $notification): string {
		if ($notification->getSubType() !== Mention::TYPE) {
			return ($notification->getAttributedTo() !== '')
				? $notification->getAttributedTo() : $notification->getActorId();
		}

		$post = $notification->getDetailItem(Details::POST);
		if ($post instanceof Stream) {
			return $post->getAttributedTo();
		}

		try {
			return $this->streamRequest->getStreamById($notification->getObjectId())->getAttributedTo();
		} catch (Exception $e) {
			return '';
		}
	}

	/**
	 * Nothing is raised for an actor the recipient has blocked, who has blocked
	 * the recipient, or whom the recipient muted with notifications hidden; an
	 * expired mute does not count.
	 */
	private function isSuppressed(string $recipientId, string $actorId): bool {
		foreach ($this->actorRelationRequest->getBetween($recipientId, $actorId) as $relation) {
			if ($relation->getType() === ActorRelation::TYPE_BLOCK
				|| $relation->getType() === ActorRelation::TYPE_BLOCKED_BY) {
				return true;
			}

			if ($relation->getType() === ActorRelation::TYPE_MUTE
				&& $relation->isNotifications()
				&& !$this->accountRelationService->isMuteExpired($recipientId, $actorId)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The notification's `object_id`, capped at 64 characters by the
	 * notifications table. Derived, so dismissing the in-app row can clear the
	 * same Nextcloud notification.
	 */
	private function objectId(string $id): string {
		return sha1($id);
	}

	/** Takes the bell entry down with the notification it announced. */
	private function forget(Person $viewer, string $id): void {
		$userId = $viewer->getUserId();
		if ($userId === '') {
			try {
				$userId = $this->actorsRequest->getFromId($viewer->getId())->getUserId();
			} catch (Exception $e) {
				return;
			}
		}

		try {
			$raised = $this->notificationManager->createNotification();
			$raised->setApp('social')
				->setUser($userId)
				->setObject(self::OBJECT, $this->objectId($id));

			$this->notificationManager->markProcessed($raised);
		} catch (Exception $e) {
			$this->logger->warning('could not withdraw a notification', ['exception' => $e]);
		}
	}

	/**
	 * The viewer's notification with this id, read through the timeline's own
	 * query, so somebody else's or one with no Mastodon type is not found.
	 *
	 * @throws ItemNotFoundException
	 */
	private function find(Person $viewer, int|string $id): Stream {
		if (\OCA\Social\Tools\Nid::compare($id, '0') > 0) {
			foreach ($this->page(
				$viewer, 1, \OCA\Social\Tools\Nid::increment($id), \OCA\Social\Tools\Nid::decrement($id)
			) as $notification) {
				if (\OCA\Social\Tools\Nid::compare($notification->getNid(), $id) === 0
					&& Stream::notificationTypeOfSubType($notification->getSubType()) !== '') {
					return $notification;
				}
			}
		}

		throw new ItemNotFoundException('Record not found');
	}

	/**
	 * A page of the viewer's notifications, newest first.
	 *
	 * @return Stream[]
	 */
	private function page(Person $viewer, int $limit, int|string $maxId = '0', int|string $minId = 0): array {
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_LOCAL);
		$options->setProbe(ProbeOptions::NOTIFICATIONS)
			->setLimit($limit)
			->setMaxId($maxId)
			->setMinId($minId);

		$this->streamService->setViewer($viewer);

		return $this->streamService->getTimeline($options);
	}

	private function cachedActor(string $actorId): ?Person {
		try {
			// Local cache only: an inbox request must not fetch from another server.
			return $this->cacheActorsRequest->getFromId($actorId);
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * The acting account's picture as this server serves it, or `''`: the
	 * Nextcloud avatar for a local account, else the cached icon. Never the
	 * remote address, which the CSP blocks and which would leak the reader.
	 */
	private function avatarOf(?Person $local, ?Person $cached): string {
		$userId = ($local === null) ? '' : $local->getUserId();
		if ($userId !== '' && !str_starts_with($userId, 'team/') && !str_starts_with($userId, 'channel/')) {
			return $this->urlGenerator->linkToRouteAbsolute(
				'core.avatar.getAvatar', ['userId' => $userId, 'size' => 64]
			);
		}

		if ($cached === null || !$cached->hasIcon()) {
			return '';
		}

		$icon = $cached->getIcon();
		if ($icon->getLocalCopy() === '') {
			return '';
		}

		return ($icon->getResizedCopy() === '')
			? $icon->getMediaUrl($this->urlGenerator)
			: $icon->getResizedMediaUrl($this->urlGenerator);
	}

	/** What to call an account in a sentence. */
	private function labelOf(Person $actor): string {
		foreach ([$actor->getName(), $actor->getAccount(), $actor->getPreferredUsername()] as $label) {
			if ($label !== '') {
				return $label;
			}
		}

		return $actor->getId();
	}

	private function accountOf(string $actorId): string {
		$actor = $this->cachedActor($actorId);

		return ($actor === null) ? $actorId : $this->labelOf($actor);
	}

	/**
	 * The actor as this server's account, null for anyone else. The cached
	 * copy of an actor does not carry the Nextcloud user; the actors table does.
	 */
	private function localActor(string $actorId): ?Person {
		try {
			return $this->actorsRequest->getFromId($actorId);
		} catch (Exception $e) {
			return null;
		}
	}

	private function isLocal(string $actorId): bool {
		try {
			$this->actorsRequest->getFromId($actorId);

			return true;
		} catch (Exception $e) {
			return false;
		}
	}
}
