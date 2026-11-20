<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ScheduledStatusesRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\PostHeldException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\ScheduledStatus;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts a client asked to have published later, and the publishing of them:
 * `POST /api/v1/statuses` with a `scheduled_at` answers a ScheduledStatus
 * entity, as in Mastodon. The whole request is kept rather than a rendered
 * post, so publishing goes through `PostService::createPost()` with a `Post`
 * built as `StatusApiController::statusNew()` builds one.
 */
class ScheduledStatusService {
	/**
	 * How far ahead a post has to be to count as scheduled: Mastodon's bound,
	 * which clients are built to, and longer than a cron period, as a post may
	 * be published up to one period late.
	 */
	public const MIN_LEAD_TIME = 300;

	/**
	 * How many posts one account may have waiting, in total and on any one
	 * day; Mastodon's numbers. Waiting posts are invisible to moderators, and
	 * the daily cap keeps one cron run able to drain what is due.
	 */
	public const MAX_PENDING = 300;
	public const MAX_PENDING_PER_DAY = 25;

	/**
	 * How many due posts one cron run publishes, as each `createPost()` signs
	 * and queues deliveries; a backlog is published over several runs.
	 */
	public const PUBLISH_PER_RUN = 50;

	/** How many times one run reads again after finding accounts to hold. */
	private const HELD_ROUNDS = 5;

	public function __construct(
		private ScheduledStatusesRequest $scheduledRequest,
		private AccountService $accountService,
		private DocumentService $documentService,
		private PostService $postService,
		private StatusAssemblyService $statusAssemblyService,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private IUserManager $userManager,
	) {
	}

	/**
	 * The instant a client asked a post to be published at, or 0 when it
	 * asked for none.
	 *
	 * @param array<string, mixed> $params the decoded `POST /api/v1/statuses` body
	 *
	 * @throws InvalidActionException the field is there but is not a time; a
	 *                                post meant to be scheduled must not go out
	 *                                now
	 */
	public function requestedTime(array $params): int {
		$raw = $params['scheduled_at'] ?? null;
		if ($raw === null || (is_string($raw) && trim($raw) === '')) {
			return 0;
		}

		if (!is_scalar($raw)) {
			throw new InvalidActionException('scheduled_at is not a date');
		}

		$when = strtotime((string)$raw);
		if ($when === false || $when <= 0) {
			throw new InvalidActionException('scheduled_at is not a date: ' . (string)$raw);
		}

		return $when;
	}

	/**
	 * Stores a post to be published later, and answers with the entity the
	 * client gets in place of a Status. What `createPost()` would refuse is
	 * refused here, while a client can still be told: the cron deletes the row
	 * before posting, so a later refusal is lost.
	 *
	 * @param array<string, mixed> $request the decoded request body
	 *
	 * @throws PostHeldException a review rule holds it; nothing is scheduled
	 * @throws InvalidActionException
	 */
	public function schedule(Person $actor, Status $status, array $request): ScheduledStatus {
		$when = $this->requestedTime($request);
		if ($when === 0) {
			throw new InvalidActionException('scheduled_at is missing');
		}

		$this->assertFarEnoughAhead($when);
		$this->assertWithinCaps($actor->getId(), $when);
		$this->assertPublishable($status->getStatus(), $status->getSpoilerText(), $status->getPoll());

		$scheduled = new ScheduledStatus();
		$scheduled->setActorId($actor->getId())
			->setScheduledAt($when)
			->setParams($this->statusAssemblyService->paramsOf($status, $this->visibilityOf($status, $actor)));

		// reviewed now, while its author is there to be told: a post a rule
		// holds waits for a moderator instead, with its time kept, and the
		// cron publishes the others exempt
		$this->postService->review(
			$this->statusAssemblyService->fromParams($actor, $scheduled),
			$scheduled->paramString('in_reply_to_id'),
			$when
		);

		$this->scheduledRequest->save($scheduled);
		$this->hydrateMedia($scheduled, $actor);

		return $scheduled;
	}

	/**
	 * Schedules a post a moderator approved after it was held when it was
	 * scheduled, for the time it was scheduled for. Nothing is scheduled,
	 * and the caller publishes it now, when that time is closer than
	 * `MIN_LEAD_TIME` or has passed while it waited.
	 *
	 * @param array<string, mixed> $params the held request
	 * @param int $when when it was scheduled for; 0 for a post that was not
	 *
	 * @return bool whether it was scheduled
	 */
	public function scheduleApproved(Person $actor, array $params, int $when): bool {
		if ($when - $this->time->getTime() < self::MIN_LEAD_TIME) {
			return false;
		}

		unset($params['scheduled_at']);
		$scheduled = new ScheduledStatus();
		$scheduled->setActorId($actor->getId())
			->setScheduledAt($when)
			->setParams($params);
		$this->scheduledRequest->save($scheduled);

		return true;
	}

	/**
	 * A page of the account's waiting posts.
	 *
	 * @return ScheduledStatus[]
	 */
	public function getAll(
		Person $actor,
		int $limit = 20,
		int|string $maxId = '0',
		int|string $minId = 0,
		int|string $sinceId = '0',
	): array {
		$scheduled = $this->scheduledRequest->getByActor($actor->getId(), $limit, \OCA\Social\Tools\Nid::fromStorage($maxId), \OCA\Social\Tools\Nid::fromStorage($minId), \OCA\Social\Tools\Nid::fromStorage($sinceId));
		foreach ($scheduled as $one) {
			$this->hydrateMedia($one, $actor);
		}

		return $scheduled;
	}

	/**
	 * @throws ItemNotFoundException no such waiting post of this account
	 */
	public function getOne(Person $actor, int $id): ScheduledStatus {
		$scheduled = $this->scheduledRequest->getById($id, $actor->getId());
		$this->hydrateMedia($scheduled, $actor);

		return $scheduled;
	}

	/**
	 * Moves a waiting post to another time. The row is read first, as an
	 * update matching nothing looks like success, and another account's post
	 * or a missing one must answer 404.
	 *
	 * @param array<string, mixed> $request the decoded request body
	 *
	 * @throws InvalidActionException
	 * @throws ItemNotFoundException
	 */
	public function reschedule(Person $actor, int $id, array $request): ScheduledStatus {
		$when = $this->requestedTime($request);
		if ($when === 0) {
			throw new InvalidActionException('scheduled_at is missing');
		}

		$this->assertFarEnoughAhead($when);

		$scheduled = $this->scheduledRequest->getById($id, $actor->getId());

		// Moving a post inside the day it is already in changes no daily count,
		// and checking it there would count the post against itself: an account
		// at the cap could never move any of that day's posts by an hour.
		if (!$this->sameDay($scheduled->getScheduledAt(), $when)) {
			$this->assertWithinDailyCap($actor->getId(), $when);
		}

		$this->scheduledRequest->reschedule($id, $actor->getId(), $when);
		$scheduled->setScheduledAt($when);
		$this->hydrateMedia($scheduled, $actor);

		return $scheduled;
	}

	/**
	 * @throws ItemNotFoundException no such waiting post of this account, which
	 *                               is also the answer for one that is not
	 *                               theirs
	 */
	public function delete(Person $actor, int $id): void {
		if (!$this->scheduledRequest->delete($id, $actor->getId())) {
			throw new ItemNotFoundException('no scheduled status ' . $id);
		}
	}

	/**
	 * Publishes everything that is due, and says how many went out. Posts of a
	 * disabled Nextcloud user wait until the user is enabled again, and their
	 * account is left out of the next read so it does not crowd out others.
	 *
	 * @return int the posts published, which is what the job logs
	 */
	public function publishDue(int $limit = self::PUBLISH_PER_RUN): int {
		$published = 0;
		$held = [];
		for ($round = 0; $round < self::HELD_ROUNDS; $round++) {
			$holding = count($held);
			foreach ($this->scheduledRequest->getDue($this->time->getTime(), $limit, array_keys($held)) as $scheduled) {
				if (isset($held[$scheduled->getActorId()])) {
					continue;
				}
				if ($this->publishOne($scheduled, $held)) {
					$published++;
				}
			}
			if (count($held) === $holding) {
				break;
			}
		}

		return $published;
	}

	/**
	 * Publishes one due post unless its account is held.
	 *
	 * @param array<string, true> $held the actor ids found held so far; one
	 *                                  found now is added
	 */
	private function publishOne(ScheduledStatus $scheduled, array &$held): bool {
		try {
			$actor = $this->accountService->getFromId($scheduled->getActorId());
		} catch (Throwable $e) {
			$actor = null;
		}
		if ($actor !== null && $this->isDisabled($actor)) {
			$held[$scheduled->getActorId()] = true;

			return false;
		}

		if (!$this->scheduledRequest->claim($scheduled->getId())) {
			// another worker got there first; it is publishing this one
			return false;
		}

		try {
			$this->publish($scheduled, $actor);

			return true;
		} catch (Throwable $e) {
			// The row is already gone, so this post is not retried: publishing
			// first and deleting after would federate it twice whenever a worker
			// dies in between. Logged at warning, as Nextcloud's default loglevel
			// is 2 and the author has no other way to find out.
			$this->logger->warning(
				'[ScheduledStatusService] could not publish scheduled status '
				. $scheduled->getId() . ' of ' . $scheduled->getActorId()
				. ': ' . $e->getMessage(),
				['exception' => $e, 'scheduled' => $scheduled->getId()]
			);
		}

		return false;
	}

	/** Whether the account belongs to a Nextcloud user that is disabled. */
	private function isDisabled(Person $actor): bool {
		$userId = $actor->getUserId();
		if ($userId === '' || str_starts_with($userId, 'team/') || str_starts_with($userId, 'channel/')) {
			return false;
		}

		$user = $this->userManager->get($userId);

		return $user !== null && !$user->isEnabled();
	}

	/**
	 * Publishes one waiting post, down the path an immediate post takes.
	 *
	 * @throws \Exception whatever `createPost()` refuses it for
	 */
	private function publish(ScheduledStatus $scheduled, ?Person $actor = null): void {
		$actor ??= $this->accountService->getFromId($scheduled->getActorId());

		// reviewed when it was scheduled (`schedule()`)
		$this->postService->createPost(
			$this->statusAssemblyService->fromParams($actor, $scheduled)->setReview(Post::REVIEW_EXEMPT)
		);
	}

	/**
	 * The visibility the post will be published with, resolved now as
	 * `StatusApiController::visibilityOf()` resolves it for an immediate post,
	 * so a later change of the account's default does not change the audience.
	 *
	 * @throws InvalidActionException the visibility is not one this app posts
	 *                                with; refused rather than silently turned
	 *                                into a direct message nobody receives
	 */
	private function visibilityOf(Status $status, Person $actor): string {
		$visibility = trim($status->getVisibility());
		if ($visibility === '') {
			return $this->accountService->getDefaultPrivacy($actor->getUserId());
		}

		if (!Stream::isKnownClientVisibility($visibility)) {
			throw new InvalidActionException('unknown visibility: ' . $visibility);
		}

		return $visibility;
	}

	/**
	 * @throws InvalidActionException
	 */
	private function assertFarEnoughAhead(int $when): void {
		if ($when - $this->time->getTime() >= self::MIN_LEAD_TIME) {
			return;
		}

		throw new InvalidActionException(
			'scheduled_at must be at least ' . (self::MIN_LEAD_TIME / 60)
			. ' minutes in the future'
		);
	}

	/**
	 * @throws InvalidActionException
	 */
	private function assertWithinCaps(string $actorId, int $when): void {
		if ($this->scheduledRequest->countByActor($actorId) >= self::MAX_PENDING) {
			throw new InvalidActionException(
				'this account already has ' . self::MAX_PENDING . ' scheduled statuses'
			);
		}

		$this->assertWithinDailyCap($actorId, $when);
	}

	/**
	 * @throws InvalidActionException
	 */
	private function assertWithinDailyCap(string $actorId, int $when): void {
		[$from, $until] = $this->dayOf($when);
		if ($this->scheduledRequest->countByActorBetween($actorId, $from, $until) < self::MAX_PENDING_PER_DAY) {
			return;
		}

		throw new InvalidActionException(
			'this account already has ' . self::MAX_PENDING_PER_DAY
			. ' statuses scheduled for that day'
		);
	}

	/**
	 * Refusals `PostService::createPost()` would raise, raised here instead,
	 * the poll check above all: from the cron a refused poll is a post
	 * silently dropped.
	 *
	 * @param ?array $poll ['options' => string[], …] as the client sent it
	 *
	 * @throws InvalidActionException
	 */
	private function assertPublishable(string $content, string $spoilerText, ?array $poll): void {
		if (LinkifyService::countableLength($content) + mb_strlen($spoilerText) > InstanceService::MAX_CHARACTERS) {
			throw new InvalidActionException(
				'a post may not be longer than ' . InstanceService::MAX_CHARACTERS . ' characters'
			);
		}

		if ($poll === null || ($poll['options'] ?? []) === []) {
			return;
		}

		PostService::pollOptions($poll['options']);
	}

	/**
	 * Puts the attachments on the entity, as the same `MediaAttachment` a
	 * published status carries: a client draws the thumbnails of a waiting post
	 * with the code it already has, instead of fetching each id.
	 */
	private function hydrateMedia(ScheduledStatus $scheduled, Person $actor): void {
		$ids = $scheduled->paramMediaIds();
		if ($ids === []) {
			return;
		}

		$documents = $this->documentService->getMediaFromArray($ids, $actor->getPreferredUsername());
		$scheduled->setMediaAttachments(
			array_map(function (Document $document): MediaAttachment {
				return $document->convertToMediaAttachment($this->urlGenerator);
			}, $documents)
		);
	}

	/**
	 * The day an instant falls in, as the half-open window the count is taken
	 * over, in the server's timezone, in which `ScheduledStatusesRequest`
	 * writes the columns.
	 *
	 * @return int[] [from, until)
	 */
	private function dayOf(int $when): array {
		return [(int)strtotime('today', $when), (int)strtotime('tomorrow', $when)];
	}

	private function sameDay(int $a, int $b): bool {
		return $this->dayOf($a) === $this->dayOf($b);
	}
}
