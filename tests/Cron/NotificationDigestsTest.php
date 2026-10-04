<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Social\Cron\NotificationDigests;
use OCA\Social\Model\NotificationDelivery;
use OCA\Social\Service\NotificationDeliveryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * When the digest job raises a digest, and with which cut.
 *
 * The cut is the point: a job that runs four minutes late must report the
 * window the user asked for, up to 18:00, not up to 18:04 — otherwise the
 * next window starts at 18:04 and whatever arrived in between is in both
 * digests or in neither.
 */
#[AllowMockObjectsWithoutExpectations]
class NotificationDigestsTest extends TestCase {
	private const ALICE = 'alice';

	private NotificationDeliveryService|Stub $deliveryService;
	private int $now;
	/** @var array<string, NotificationDelivery> */
	private array $settings = [];
	/** @var array<string, int> */
	private array $lastDigestAt = [];
	/** @var array<int, array{string, string}> [user, cut as Berlin local time] of every digest built */
	private array $digests = [];
	private bool $digestsAreEmpty = false;

	protected function setUp(): void {
		$this->now = $this->berlin('2026-03-10 18:04')->getTimestamp();

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->deliveryService = $this->createStub(NotificationDeliveryService::class);
		$this->deliveryService->method('scheduledUserIds')
			->willReturnCallback(fn (): array => array_keys($this->settings));
		$this->deliveryService->method('of')
			->willReturnCallback(fn (string $userId): NotificationDelivery
				=> $this->settings[$userId] ?? new NotificationDelivery());
		$this->deliveryService->method('lastDigestAt')
			->willReturnCallback(fn (string $userId): int => $this->lastDigestAt[$userId] ?? 0);
		$this->deliveryService->method('setLastDigestAt')
			->willReturnCallback(function (string $userId, int $at): void {
				$this->lastDigestAt[$userId] = $at;
			});
		$this->deliveryService->method('timezoneOf')->willReturn(new DateTimeZone('Europe/Berlin'));
		$this->deliveryService->method('digestFor')
			->willReturnCallback(function (string $userId, DateTimeImmutable $until): ?array {
				$this->digests[] = [$userId, $until->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i')];
				$this->lastDigestAt[$userId] = $until->getTimestamp();

				return $this->digestsAreEmpty ? null : ['counts' => ['favourite' => 1], 'total' => 1, 'link' => ''];
			});

		$this->job = new NotificationDigests($time, $this->deliveryService, new NullLogger());
	}

	private NotificationDigests $job;

	private function berlin(string $local): DateTimeImmutable {
		return new DateTimeImmutable($local, new DateTimeZone('Europe/Berlin'));
	}

	private function scheduled(string $userId, array $setting, string $lastDigestAt): void {
		$this->settings[$userId] = (new NotificationDelivery())->apply($setting);
		$this->lastDigestAt[$userId] = $this->berlin($lastDigestAt)->getTimestamp();
	}

	public function testItIsATimedJobOnAFiveMinuteInterval(): void {
		$this->assertInstanceOf(TimedJob::class, $this->job);
		$this->assertSame(300, (new \ReflectionProperty(TimedJob::class, 'interval'))->getValue($this->job));
	}

	public function testADigestWhoseTimeHasComeIsRaisedWithThatTimeAsItsCut(): void {
		$this->scheduled(self::ALICE, ['mode' => 'digest', 'times' => ['08:00', '18:00']], '2026-03-10 08:00');

		$this->assertSame(1, $this->job->runDue());
		// 18:00, not 18:04
		$this->assertSame([[self::ALICE, '2026-03-10 18:00']], $this->digests);
	}

	public function testNothingIsRaisedBeforeTheTime(): void {
		$this->scheduled(self::ALICE, ['mode' => 'digest', 'times' => ['08:00', '18:00']], '2026-03-10 08:00');
		$this->now = $this->berlin('2026-03-10 17:59')->getTimestamp();

		$this->assertSame(0, $this->job->runDue());
		$this->assertSame([], $this->digests);
	}

	public function testADigestAlreadyRaisedIsNotRaisedAgain(): void {
		$this->scheduled(self::ALICE, ['mode' => 'digest', 'times' => ['08:00', '18:00']], '2026-03-10 18:00');

		$this->assertSame(0, $this->job->runDue());
	}

	public function testAJobThatMissedSeveralTimesCatchesUpOnePerTimeUpToTheCap(): void {
		$this->scheduled(self::ALICE, ['mode' => 'digest', 'times' => ['08:00', '18:00']], '2026-03-07 08:00');

		$this->job->runDue();

		$this->assertSame([
			[self::ALICE, '2026-03-07 18:00'],
			[self::ALICE, '2026-03-08 08:00'],
			[self::ALICE, '2026-03-08 18:00'],
			[self::ALICE, '2026-03-09 08:00'],
		], $this->digests);
		$this->assertCount(NotificationDigests::MAX_PER_USER, $this->digests);
	}

	public function testTheEndOfQuietHoursIsADigestInInstantMode(): void {
		$this->scheduled(self::ALICE, ['quiet' => ['from' => '22:00', 'to' => '07:00']], '2026-03-09 23:00');
		$this->now = $this->berlin('2026-03-10 07:02')->getTimestamp();

		$this->job->runDue();

		$this->assertSame([[self::ALICE, '2026-03-10 07:00']], $this->digests);
	}

	public function testAnEmptyDigestIsNotCountedAsRaisedButStillMovesTheClock(): void {
		$this->scheduled(self::ALICE, ['mode' => 'digest', 'times' => ['18:00']], '2026-03-10 08:00');
		$this->digestsAreEmpty = true;

		$this->assertSame(0, $this->job->runDue());
		$this->assertSame($this->berlin('2026-03-10 18:00')->getTimestamp(), $this->lastDigestAt[self::ALICE]);
	}

	public function testAUserWithoutAClockGetsOneStartedRatherThanADigestSince1970(): void {
		$this->settings[self::ALICE] = (new NotificationDelivery())->apply(['mode' => 'digest']);

		$this->assertSame(0, $this->job->runDue());
		$this->assertSame([], $this->digests);
		$this->assertSame($this->now, $this->lastDigestAt[self::ALICE]);
	}

	public function testAFlagThatOutlivedTheSettingRaisesNothing(): void {
		$this->settings[self::ALICE] = new NotificationDelivery();
		$this->lastDigestAt[self::ALICE] = $this->berlin('2026-03-10 08:00')->getTimestamp();

		$this->assertSame(0, $this->job->runDue());
	}

	public function testOneUsersFailureDoesNotStopTheOthers(): void {
		$this->scheduled('zed', ['mode' => 'digest', 'times' => ['18:00']], '2026-03-10 08:00');
		$this->scheduled(self::ALICE, ['mode' => 'digest', 'times' => ['18:00']], '2026-03-10 08:00');
		$this->deliveryService = $this->createStub(NotificationDeliveryService::class);
		$this->deliveryService->method('scheduledUserIds')->willReturn(['zed', self::ALICE]);
		$this->deliveryService->method('of')->willReturnCallback(function (string $userId): NotificationDelivery {
			if ($userId === 'zed') {
				throw new \RuntimeException('the database went away');
			}

			return $this->settings[$userId];
		});
		$this->deliveryService->method('lastDigestAt')
			->willReturnCallback(fn (string $userId): int => $this->lastDigestAt[$userId]);
		$this->deliveryService->method('timezoneOf')->willReturn(new DateTimeZone('Europe/Berlin'));
		$this->deliveryService->method('digestFor')->willReturnCallback(
			function (string $userId, DateTimeImmutable $until): array {
				$this->digests[] = [$userId, $until->format('H:i')]; // in the reader's zone, as nextDigestAfter() answers

				return ['counts' => [], 'total' => 1, 'link' => ''];
			}
		);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$job = new NotificationDigests($time, $this->deliveryService, new NullLogger());

		$this->assertSame(1, $job->runDue());
		$this->assertSame([[self::ALICE, '18:00']], $this->digests);
	}
}
