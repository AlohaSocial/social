<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MemoriesService;
use OCA\Social\Service\ProfileHighlightsService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The reader's own posts from this day in previous years.
 *
 * What matters: each year back is looked up as *that calendar day* rather than
 * as "about a year ago", the reader's own timezone decides where a day starts,
 * and the 29th of February is not quietly turned into the 1st of March.
 */
#[AllowMockObjectsWithoutExpectations]
class MemoriesServiceTest extends TestCase {
	private StreamRequest&MockObject $streamRequest;
	private ConfigService&MockObject $configService;
	private ProfileHighlightsService&MockObject $profileHighlightsService;
	private MemoriesService $service;

	protected function setUp(): void {
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->profileHighlightsService = $this->createMock(ProfileHighlightsService::class);
		$this->service = new MemoriesService(
			$this->streamRequest, $this->configService, $this->profileHighlightsService
		);
	}

	private function actor(): Person {
		$actor = new Person();
		$actor->setId('https://cloud.example/users/alice');

		return $actor;
	}

	private function post(string $id, int $published): Stream {
		$post = new Note();
		$post->setId($id);
		$post->setPublishedTime($published);

		return $post;
	}

	/**
	 * The windows the service asked for, as `Y-m-d H:i:s` pairs in the given
	 * timezone, so a test can say which day was looked up rather than which
	 * timestamp.
	 *
	 * @return list<array{string, string}>
	 */
	private function windowsAskedFor(DateTimeZone $timezone, array &$calls): array {
		return array_map(
			static fn (array $call): array => [
				(new DateTimeImmutable('@' . $call[0]))->setTimezone($timezone)->format('Y-m-d H:i:s'),
				(new DateTimeImmutable('@' . $call[1]))->setTimezone($timezone)->format('Y-m-d H:i:s'),
			],
			$calls
		);
	}

	public function testEachYearBackIsLookedUpAsTheSameCalendarDay(): void {
		$timezone = new DateTimeZone('UTC');
		$calls = [];
		$this->streamRequest->method('getByAuthorBetween')
			->willReturnCallback(function (string $id, int $from, int $until) use (&$calls): array {
				$calls[] = [$from, $until];

				return [];
			});

		$this->service->onThisDay(
			$this->actor(), $timezone, new DateTimeImmutable('2026-03-04 15:30:00', $timezone)
		);

		$this->assertSame([
			['2025-03-04 00:00:00', '2025-03-05 00:00:00'],
			['2024-03-04 00:00:00', '2024-03-05 00:00:00'],
			['2023-03-04 00:00:00', '2023-03-05 00:00:00'],
			['2022-03-04 00:00:00', '2022-03-05 00:00:00'],
			['2021-03-04 00:00:00', '2021-03-05 00:00:00'],
		], $this->windowsAskedFor($timezone, $calls));
	}

	/**
	 * A day belongs to the reader, not to the server: a post written at 23:50
	 * in Berlin must not count as the next day's.
	 */
	public function testTheDayIsTheReadersOwn(): void {
		$timezone = new DateTimeZone('Europe/Berlin');
		$calls = [];
		$this->streamRequest->method('getByAuthorBetween')
			->willReturnCallback(function (string $id, int $from, int $until) use (&$calls): array {
				$calls[] = [$from, $until];

				return [];
			});

		$this->service->onThisDay(
			$this->actor(), $timezone, new DateTimeImmutable('2026-03-04 23:50:00', $timezone)
		);

		$windows = $this->windowsAskedFor($timezone, $calls);
		$this->assertSame('2025-03-04 00:00:00', $windows[0][0]);
		$this->assertSame('2025-03-05 00:00:00', $windows[0][1]);
	}

	/**
	 * `modify('-1 years')` on the 29th of February lands on the 1st of March
	 * in a year that has no 29th — a different day's posts entirely. A leap
	 * day is remembered on leap years only.
	 */
	public function testALeapDayIsOnlyLookedUpInYearsThatHaveOne(): void {
		$timezone = new DateTimeZone('UTC');
		$calls = [];
		$this->streamRequest->method('getByAuthorBetween')
			->willReturnCallback(function (string $id, int $from, int $until) use (&$calls): array {
				$calls[] = [$from, $until];

				return [];
			});

		$this->service->onThisDay(
			$this->actor(), $timezone, new DateTimeImmutable('2028-02-29 12:00:00', $timezone)
		);

		$starts = array_column($this->windowsAskedFor($timezone, $calls), 0);
		$this->assertSame(['2024-02-29 00:00:00'], $starts);
	}

	public function testMemoriesComeBackNewestFirstAcrossTheYears(): void {
		$this->streamRequest->method('getByAuthorBetween')
			->willReturnCallback(fn (string $id, int $from): array => match (true) {
				$from > 1735689600 => [$this->post('newer', 1741000000)],
				default => [$this->post('older', 1615000000)],
			});

		$memories = $this->service->onThisDay(
			$this->actor(), new DateTimeZone('UTC'), new DateTimeImmutable('2026-03-04 12:00:00')
		);

		$this->assertSame('newer', $memories[0]->getId());
		$this->assertSame('older', $memories[1]->getId());
	}

	public function testThereIsACeilingHoweverManyYearsHadSomething(): void {
		$this->streamRequest->method('getByAuthorBetween')
			->willReturnCallback(fn (string $id, int $from): array => [
				$this->post('a' . $from, $from),
				$this->post('b' . $from, $from + 1),
				$this->post('c' . $from, $from + 2),
			]);

		$memories = $this->service->onThisDay(
			$this->actor(), new DateTimeZone('UTC'), new DateTimeImmutable('2026-03-04 12:00:00')
		);

		$this->assertCount(6, $memories);
	}

	public function testAnActorWithNoIdIsNotLookedUp(): void {
		$this->streamRequest->expects($this->never())->method('getByAuthorBetween');

		$this->assertSame([], $this->service->onThisDay(new Person(), new DateTimeZone('UTC')));
	}

	public function testTheRecapIsOffUntilItIsAskedFor(): void {
		$this->configService->method('getValueForUser')->willReturn('');
		$this->profileHighlightsService->expects($this->never())->method('forActor');

		$recap = $this->service->recap($this->actor(), 'alice');

		$this->assertFalse($recap['enabled']);
		$this->assertSame(0, $recap['this_week']);
	}

	/**
	 * Read off the same twelve-week chart the profile draws, so the number
	 * here cannot disagree with the number on the reader's own profile: the
	 * last bucket is the week in progress and the one before it is last week.
	 */
	public function testTheRecapReadsTheLastTwoWeeksOfTheChart(): void {
		$this->configService->method('getValueForUser')->willReturn('1');
		$this->profileHighlightsService->method('forActor')->willReturn([
			'available' => true,
			'since' => 0,
			'weeks' => [9, 9, 9, 9, 9, 9, 9, 9, 9, 9, 5, 3],
			'week_starts' => 0,
			'hashtags' => [],
		]);

		$recap = $this->service->recap($this->actor(), 'alice');

		$this->assertTrue($recap['enabled']);
		$this->assertSame(3, $recap['this_week']);
		$this->assertSame(5, $recap['last_week']);
	}

	public function testARecapWithNoChartBehindItIsZeroesRatherThanAnError(): void {
		$this->configService->method('getValueForUser')->willReturn('1');
		$this->profileHighlightsService->method('forActor')->willReturn([
			'available' => false,
			'since' => 0,
			'weeks' => [],
			'week_starts' => 0,
			'hashtags' => [],
		]);

		$recap = $this->service->recap($this->actor(), 'alice');

		$this->assertSame(0, $recap['this_week']);
		$this->assertSame(0, $recap['last_week']);
	}

	public function testTheSwitchIsStoredAsAFlag(): void {
		$this->configService->expects($this->once())
			->method('setValueForUser')
			->with('alice', MemoriesService::RECAP_KEY, '1');

		$this->service->setRecapEnabled('alice', true);
	}

	public function testTurningItOffStoresAFlagRatherThanRemovingIt(): void {
		$this->configService->expects($this->once())
			->method('setValueForUser')
			->with('alice', MemoriesService::RECAP_KEY, '0');

		$this->service->setRecapEnabled('alice', false);
	}
}
