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
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\ScheduledStatus;
use OCA\Social\Model\Client\Status;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts a client asked to have published later, and the publishing of them.
 *
 * `POST /api/v1/statuses` with a `scheduled_at` is not a post: Mastodon answers
 * it with a ScheduledStatus entity, publishes nothing, and hands the request
 * back to a worker when the time comes. This app used to parse the field and
 * post immediately, so a client that scheduled something for next Tuesday
 * showed its user a success and had already published it.
 *
 * The whole request is kept, not a rendered post, because publishing has to
 * take the same path an immediate post takes — `PostService::createPost()`,
 * over a `Post` built exactly as `StatusApiController::statusNew()` builds one. A
 * rendered note stored now would be built by one code path and federated by
 * another, and the two would drift: the quote approval, the language fallback
 * and the source snapshot all happen inside `createPost()`.
 */
class ScheduledStatusService {
	/**
	 * How far ahead a post has to be to count as scheduled at all.
	 *
	 * Mastodon's own bound, and clients are built to it: Tusky greys out its
	 * "schedule" button until the picked time is five minutes out, and Elk
	 * shows the 422 this refusal produces. The value is also what makes the
	 * cron interval workable — a post may be published up to one cron period
	 * late, so accepting a post due in thirty seconds would mean promising a
	 * precision this app cannot keep.
	 */
	public const MIN_LEAD_TIME = 300;

	/**
	 * How many posts one account may have waiting, in total and on any one
	 * day. Mastodon's numbers.
	 *
	 * A cap is needed because a scheduled post is the only thing in this app a
	 * client can store, unpublished and unbounded, without anybody seeing it:
	 * without one, a single authorised client could fill an instance's database
	 * with text that never becomes a post and that no moderator can find,
	 * because none of it is on any timeline. The daily cap is the one that
	 * matters for the cron — 300 posts all due in the same minute is one run
	 * that cannot finish, while 25 per account per day is a load the job can
	 * drain.
	 */
	public const MAX_PENDING = 300;
	public const MAX_PENDING_PER_DAY = 25;

	/**
	 * How many due posts one cron run publishes.
	 *
	 * Each one is a `createPost()`, which signs and queues a delivery per
	 * recipient instance, so this is the slowest thing the job does. Bounded so
	 * that an instance whose cron has been dead for a week publishes what it
	 * can and comes back for the rest.
	 */
	public const PUBLISH_PER_RUN = 50;

	public function __construct(
		private ScheduledStatusesRequest $scheduledRequest,
		private AccountService $accountService,
		private DocumentService $documentService,
		private PostService $postService,
		private StatusAssemblyService $statusAssemblyService,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The instant a client asked a post to be published at, or 0 when it asked
	 * for none — which is the whole of the difference between a scheduled post
	 * and an immediate one, and so has to be answerable before anything is
	 * created.
	 *
	 * @param array<string, mixed> $params the decoded `POST /api/v1/statuses` body
	 *
	 * @throws InvalidActionException the field is there but is not a time; a
	 *                                client that meant to schedule and was
	 *                                misunderstood must not be told its post
	 *                                went out now
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
	 * client gets in place of a Status.
	 *
	 * Everything `createPost()` would refuse is refused here instead, while
	 * there is still a client on the other end to tell: the row is claimed and
	 * deleted before the post is attempted, so a refusal discovered by the cron
	 * is a post the author never learns was lost.
	 *
	 * @param array<string, mixed> $request the decoded request body
	 *
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

		$this->scheduledRequest->save($scheduled);
		$this->hydrateMedia($scheduled, $actor);

		return $scheduled;
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
	 * Moves a waiting post to another time.
	 *
	 * The row is read before it is written even though the update is itself
	 * scoped to the owner: an update that matches nothing is indistinguishable
	 * from one that changed a row, and a client that asked to move somebody
	 * else's post — or one that no longer exists — has to hear 404 rather than
	 * a success carrying a time nothing was set to.
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
	 * Publishes everything that is due, and says how many went out.
	 *
	 * @return int the posts published, which is what the job logs
	 */
	public function publishDue(int $limit = self::PUBLISH_PER_RUN): int {
		$published = 0;
		foreach ($this->scheduledRequest->getDue($this->time->getTime(), $limit) as $scheduled) {
			if (!$this->scheduledRequest->claim($scheduled->getId())) {
				// another worker got there first; it is publishing this one
				continue;
			}

			try {
				$this->publish($scheduled);
				$published++;
			} catch (Throwable $e) {
				// The row is already gone, so this post is not published and
				// will not be retried. That is the deliberate side of the
				// trade: the alternative — publish, then delete — federates the
				// post twice whenever a worker dies in between, and a duplicate
				// post cannot be taken back, while a failed one is at least
				// here. At warning, because Nextcloud's default loglevel is 2
				// and an author has no other way to find out.
				$this->logger->warning(
					'[ScheduledStatusService] could not publish scheduled status '
					. $scheduled->getId() . ' of ' . $scheduled->getActorId()
					. ': ' . $e->getMessage(),
					['exception' => $e, 'scheduled' => $scheduled->getId()]
				);
			}
		}

		return $published;
	}

	/**
	 * Publishes one waiting post, down the path an immediate post takes.
	 *
	 * @throws \Exception whatever `createPost()` refuses it for
	 */
	private function publish(ScheduledStatus $scheduled): ?ACore {
		$actor = $this->accountService->getFromId($scheduled->getActorId());

		return $this->postService->createPost(
			$this->statusAssemblyService->fromParams($actor, $scheduled)
		);
	}

	/**
	 * The visibility the post will be published with, resolved now rather than
	 * when it goes out.
	 *
	 * `StatusApiController::visibilityOf()` decides the same thing for an immediate
	 * post, and this has to agree with it. Resolving an absent visibility at
	 * publishing time instead would read the account's default as it is then:
	 * an author who schedules a public post and later switches their default to
	 * followers-only would find the waiting post had quietly changed audience —
	 * in the direction they did not choose either way.
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
	 * Refusals `PostService::createPost()` would raise, raised here instead.
	 *
	 * The poll check is the one that cannot wait: a poll with fewer than two
	 * usable options comes out of `createPost()` as an `InvalidArgumentException`,
	 * which the API maps to a 500 rather than a 422 — and from the cron it is
	 * not answered at all, it is a post silently dropped weeks after the author
	 * was told it was accepted.
	 *
	 * @param ?array $poll ['options' => string[], …] as the client sent it
	 *
	 * @throws InvalidActionException
	 */
	private function assertPublishable(string $content, string $spoilerText, ?array $poll): void {
		if (mb_strlen($content) + mb_strlen($spoilerText) > InstanceService::MAX_CHARACTERS) {
			throw new InvalidActionException(
				'a post may not be longer than ' . InstanceService::MAX_CHARACTERS . ' characters'
			);
		}

		if ($poll === null || ($poll['options'] ?? []) === []) {
			return;
		}

		$options = array_filter(
			array_map('strval', $poll['options']),
			static fn (string $option): bool => trim($option) !== ''
		);
		if (count($options) < 2) {
			throw new InvalidActionException('a poll needs at least two options');
		}
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
	 * over.
	 *
	 * The server's own day, not UTC's: `ScheduledStatusesRequest` writes these
	 * columns in the server's timezone, so a window built in UTC would select
	 * the wrong rows on every instance that is not on it — and "that day" means
	 * the author's day, not Greenwich's.
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
