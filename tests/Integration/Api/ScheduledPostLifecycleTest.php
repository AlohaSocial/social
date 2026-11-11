<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\ReviewController;
use OCA\Social\Controller\ScheduledStatusApiController;
use OCA\Social\Controller\StatusApiController;
use OCA\Social\Service\ScheduledStatusService;
use OCA\Social\Tests\Helper\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A scheduled post from `POST /api/v1/statuses` to its run of the cron, on a
 * frozen clock.
 *
 * The run a second before the post is due must leave it waiting, the run on
 * that second must publish it, and the run after must not publish it again —
 * the row is the only thing standing between a post and a second copy of it
 * on every follower's timeline.
 *
 * The clock that runs the cron stands in the past. A run publishes whatever is
 * due across the instance, so on one with posts of its own scheduled a clock
 * in the future would send those out early.
 */
class ScheduledPostLifecycleTest extends ApiTestCase {
	private const LEAD = 600;

	public function testAPostWaitsUntilItsSecondIsPublishedOnItAndOnlyOnce(): void {
		$clock = $this->freezeClock(new FrozenClock(time() - 30 * 86400));
		$text = 'scheduled probe ' . bin2hex(random_bytes(4));
		$due = $clock->getTime() + self::LEAD;

		$scheduled = $this->schedule('bob', $text, gmdate('Y-m-d\TH:i:s\Z', $due));
		$this->assertSame(gmdate('Y-m-d\TH:i:s', $due) . '.000Z', $scheduled->scheduled_at);

		$clock->set($due - 1);
		$this->runCron();
		$this->assertSame([$scheduled->id], $this->waiting('bob'), 'the run a second early');
		$this->assertNotContains($text, $this->postsOf('bob'));

		$clock->set($due);
		$this->runCron();
		$this->assertSame([], $this->waiting('bob'), 'the run on the second it is due');
		$this->assertSame(1, $this->copiesOf($text, $this->postsOf('bob')));

		$clock->advance(300);
		$this->runCron();
		$this->assertSame(1, $this->copiesOf($text, $this->postsOf('bob')), 'the next run');
	}

	/**
	 * `scheduled_at` as clients write it, at calendar edges: with and without
	 * an offset, on a leap day, at the end of a month and of a year, and in
	 * the local hour a daylight-saving change skips or repeats. Each names one
	 * instant, and that instant is when the post goes out.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function calendarEdges(): array {
		return [
			'midnight into a leap day' => ['2024-02-28T23:50:00Z', '2024-02-29T00:00:00Z', '2024-02-29T00:00:00'],
			'midnight out of a leap day' => ['2024-02-29T23:50:00Z', '2024-03-01T00:00:00.000Z', '2024-03-01T00:00:00'],
			'the last second of October' => ['2025-10-31T23:40:00Z', '2025-10-31T23:59:59Z', '2025-10-31T23:59:59'],
			'new year, written with an offset' => ['2025-12-31T22:50:00Z', '2026-01-01T00:00:00+01:00', '2025-12-31T23:00:00'],
			'the hour Europe skips' => ['2025-03-30T00:50:00Z', '2025-03-30T03:00:00+02:00', '2025-03-30T01:00:00'],
			'the hour Europe repeats, first time' => ['2025-10-26T00:20:00Z', '2025-10-26T02:30:00+02:00', '2025-10-26T00:30:00'],
			'the hour Europe repeats, second time' => ['2025-10-26T01:20:00Z', '2025-10-26T02:30:00+01:00', '2025-10-26T01:30:00'],
		];
	}

	#[DataProvider('calendarEdges')]
	public function testAPostScheduledAtACalendarEdgeGoesOutOnThatInstant(string $now, string $scheduledAt, string $utc): void {
		$clock = $this->freezeClock(FrozenClock::at($now));
		$due = FrozenClock::at($utc . 'Z')->getTime();
		$text = 'calendar probe ' . bin2hex(random_bytes(4));

		$scheduled = $this->schedule('bob', $text, $scheduledAt);
		$this->assertSame($utc . '.000Z', $scheduled->scheduled_at);

		$clock->set($due - 1);
		$this->runCron();
		$this->assertSame([$scheduled->id], $this->waiting('bob'));
		$clock->set($due);
		$this->runCron();
		$this->assertSame([], $this->waiting('bob'));
		$this->assertSame(1, $this->copiesOf($text, $this->postsOf('bob')));
	}

	/**
	 * Twenty-five a day, as Mastodon allows, counted over the day the posts are
	 * for: the twenty-sixth of the last day of February is refused and the
	 * first of the leap day after it is not.
	 */
	public function testTheDailyCapIsCountedOverTheDayThePostsAreFor(): void {
		$this->freezeClock(FrozenClock::at('2024-02-27T12:00:00Z'));
		for ($i = 0; $i < ScheduledStatusService::MAX_PENDING_PER_DAY; $i++) {
			$this->schedule('bob', 'cap probe ' . $i, sprintf('2024-02-28T23:%02d:00Z', $i));
		}

		$refused = $this->scheduleResponse('bob', 'one too many', '2024-02-28T23:59:59Z');
		$this->assertSame(422, $refused->status, json_encode($refused->body) ?: '');

		$nextDay = $this->scheduleResponse('bob', 'first of the leap day', '2024-02-29T00:00:00Z');
		$this->assertSame(200, $nextDay->status, json_encode($nextDay->body) ?: '');
	}

	/**
	 * Reviewed when it is scheduled: a post a rule holds is answered as held
	 * then, waits for a moderator with nothing scheduled, and the cron never
	 * publishes it on its own.
	 */
	public function testAPostARuleHoldsIsHeldWhenItIsScheduledAndTheCronPublishesNothing(): void {
		$clock = $this->freezeClock(new FrozenClock(time() - 30 * 86400));
		$marker = 'held probe ' . bin2hex(random_bytes(4));
		$links = implode(' ', array_map(static fn (int $n): string => 'https://spam.example/' . $n, range(1, 6)));
		$due = $clock->getTime() + self::LEAD;
		$waitingBefore = $this->waiting('bob');

		$response = $this->scheduleResponse('bob', $marker . ' ' . $links, gmdate('Y-m-d\TH:i:s\Z', $due));

		try {
			$this->assertSame(422, $response->status, json_encode($response->body) ?: '');
			$this->assertTrue($response->body->held_for_review);
			$this->assertSame('links', $response->body->reason);
			$this->assertSame($waitingBefore, $this->waiting('bob'), 'nothing is scheduled');

			$clock->set($due);
			$this->runCron();
			$this->assertSame([], array_filter($this->postsOf('bob'), static fn (string $post): bool => str_contains($post, $marker)));
		} finally {
			$id = (int)($response->body->held_post->id ?? 0);
			if ($id > 0) {
				$this->call(ReviewController::class, 'withdraw', ['id' => $id], [], 'bob', 'DELETE', webSession: true);
			}
		}
	}

	private function schedule(string $as, string $text, string $scheduledAt): \stdClass {
		$response = $this->scheduleResponse($as, $text, $scheduledAt);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertInstanceOf(\stdClass::class, $response->body);
		$this->assertObjectHasProperty('scheduled_at', $response->body, 'a ScheduledStatus, not a Status');

		return $response->body;
	}

	private function scheduleResponse(string $as, string $text, string $scheduledAt): ApiResponse {
		return $this->call(StatusApiController::class, 'statusNew', [], [
			'status' => $text, 'visibility' => 'public', 'scheduled_at' => $scheduledAt,
		], $as, 'POST');
	}

	private function runCron(): void {
		$this->clocked(ScheduledStatusService::class)->publishDue();
	}

	/** @return string[] the ids of the account's waiting posts */
	private function waiting(string $as): array {
		$response = $this->call(ScheduledStatusApiController::class, 'scheduledStatuses', [], [], $as);
		$this->assertSame(200, $response->status);

		return array_map(static fn (\stdClass $scheduled): string => (string)$scheduled->id, $response->body);
	}

	/** @return string[] the text of the account's posts, newest first */
	private function postsOf(string $as): array {
		$response = $this->call(
			AccountApiController::class, 'accountStatuses', ['account' => (string)$this->account($as)->getNid()], ['limit' => 40], $as
		);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		return array_map(static fn (\stdClass $status): string => strip_tags((string)$status->content), $response->body);
	}

	/** @param string[] $posts */
	private function copiesOf(string $text, array $posts): int {
		return count(array_filter($posts, static fn (string $post): bool => $post === $text));
	}
}
