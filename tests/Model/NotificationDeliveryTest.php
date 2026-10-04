<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\NotificationDelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The delivery setting: what it accepts, what it refuses, and when the next
 * digest is due.
 *
 * The clock cases are the ones worth pinning: a digest time earlier in the
 * day than "now" belongs to tomorrow, a quiet window may cross midnight, and
 * the night Berlin loses an hour must not lose or double a digest.
 */
class NotificationDeliveryTest extends TestCase {
	private DateTimeZone $berlin;

	protected function setUp(): void {
		$this->berlin = new DateTimeZone('Europe/Berlin');
	}

	private function at(string $local, ?DateTimeZone $zone = null): DateTimeImmutable {
		return new DateTimeImmutable($local, $zone ?? $this->berlin);
	}

	public function testTheDefaultsAreInstantWithTwoTimesAndNoQuietHours(): void {
		$this->assertSame([
			'mode' => 'instant',
			'times' => ['08:00', '18:00'],
			'passthrough' => ['direct' => true, 'mentions_from_followed' => true],
			'quiet' => ['from' => '', 'to' => ''],
		], (new NotificationDelivery())->toArray());
		$this->assertFalse((new NotificationDelivery())->isScheduled());
	}

	public function testApplyChangesOnlyWhatWasNamed(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest']);

		$this->assertTrue($delivery->isDigest());
		$this->assertSame(['08:00', '18:00'], $delivery->getTimes());
		$this->assertSame(['direct' => true, 'mentions_from_followed' => true], $delivery->toArray()['passthrough']);
	}

	public function testTimesAreSortedOnSave(): void {
		$delivery = (new NotificationDelivery())->apply(['times' => ['18:00', '07:30', '12:00']]);

		$this->assertSame(['07:30', '12:00', '18:00'], $delivery->getTimes());
	}

	public function testPassthroughAcceptsFormBooleans(): void {
		$delivery = (new NotificationDelivery())->apply([
			'passthrough' => ['direct' => '0', 'mentions_from_followed' => 'true'],
		]);

		$this->assertSame(['direct' => false, 'mentions_from_followed' => true], $delivery->toArray()['passthrough']);
	}

	public function testQuietHoursAreStored(): void {
		$delivery = (new NotificationDelivery())->apply(['quiet' => ['from' => '22:00', 'to' => '07:00']]);

		$this->assertTrue($delivery->hasQuietHours());
		$this->assertTrue($delivery->isScheduled());
		$this->assertFalse($delivery->isDigest());
	}

	public function testClearingQuietHoursTakesBothEnds(): void {
		$delivery = (new NotificationDelivery())
			->apply(['quiet' => ['from' => '22:00', 'to' => '07:00']])
			->apply(['quiet' => ['from' => '', 'to' => '']]);

		$this->assertFalse($delivery->hasQuietHours());
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function invalidChanges(): iterable {
		yield 'unknown mode' => [['mode' => 'weekly']];
		yield 'mode not a string' => [['mode' => 1]];
		yield 'no times' => [['times' => []]];
		yield 'five times' => [['times' => ['01:00', '02:00', '03:00', '04:00', '05:00']]];
		yield 'times not a list' => [['times' => '08:00']];
		yield 'a time without padding' => [['times' => ['8:00']]];
		yield 'a time past midnight' => [['times' => ['24:00']]];
		yield 'a 12-hour time' => [['times' => ['8:00 pm']]];
		yield 'a repeated time' => [['times' => ['08:00', '08:00']]];
		yield 'one end of quiet hours' => [['quiet' => ['from' => '22:00', 'to' => '']]];
		yield 'quiet hours of no length' => [['quiet' => ['from' => '22:00', 'to' => '22:00']]];
		yield 'quiet not an object' => [['quiet' => '22:00-07:00']];
		yield 'a passthrough that is not a boolean' => [['passthrough' => ['direct' => 'maybe']]];
		yield 'passthrough not an object' => [['passthrough' => true]];
	}

	#[DataProvider('invalidChanges')]
	public function testInvalidChangesAreRefused(array $changes): void {
		$this->expectException(InvalidResourceException::class);

		(new NotificationDelivery())->apply($changes);
	}

	public function testARefusedChangeLeavesEverythingAsItWas(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest']);

		try {
			$delivery->apply(['mode' => 'instant', 'times' => ['25:00']]);
			$this->fail('the change should have been refused');
		} catch (InvalidResourceException) {
		}

		$this->assertTrue($delivery->isDigest());
	}

	public function testFromArrayResetsAKeyThatNoLongerValidatesAndKeepsTheRest(): void {
		$delivery = NotificationDelivery::fromArray([
			'mode' => 'digest',
			'times' => ['nonsense'],
			'quiet' => ['from' => '23:00', 'to' => '06:00'],
		]);

		$this->assertTrue($delivery->isDigest());
		$this->assertSame(['08:00', '18:00'], $delivery->getTimes());
		$this->assertSame(['from' => '23:00', 'to' => '06:00'], $delivery->toArray()['quiet']);
	}

	public function testFromArrayRoundTrips(): void {
		$stored = (new NotificationDelivery())->apply([
			'mode' => 'digest',
			'times' => ['09:15'],
			'passthrough' => ['direct' => false],
			'quiet' => ['from' => '22:00', 'to' => '07:00'],
		])->toArray();

		$this->assertSame($stored, NotificationDelivery::fromArray($stored)->toArray());
	}

	// -- pass-through --------------------------------------------------------

	public function testADirectMessagePassesWhenItsSwitchIsOn(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest']);

		$this->assertTrue($delivery->passesThrough(NotificationDelivery::SUBJECT_DIRECT, false));
		$this->assertFalse(
			$delivery->apply(['passthrough' => ['direct' => false]])
				->passesThrough(NotificationDelivery::SUBJECT_DIRECT, false)
		);
	}

	public function testAMentionPassesOnlyFromAFollowedAccount(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest']);

		$this->assertTrue($delivery->passesThrough('mention', true));
		$this->assertFalse($delivery->passesThrough('mention', false));
		$this->assertFalse(
			$delivery->apply(['passthrough' => ['mentions_from_followed' => false]])
				->passesThrough('mention', true)
		);
	}

	public function testNothingElsePassesEvenFromAFollowedAccount(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest']);

		foreach (['favourite', 'reblog', 'follow', 'follow_request', 'poll', 'status', 'update'] as $subject) {
			$this->assertFalse($delivery->passesThrough($subject, true), $subject);
		}
	}

	// -- holds ----------------------------------------------------------------

	public function testInstantModeOutsideQuietHoursHoldsNothing(): void {
		$delivery = new NotificationDelivery();

		$this->assertFalse($delivery->holds('favourite', false, $this->at('2026-03-10 12:00'), $this->berlin));
	}

	public function testDigestModeHoldsWhatDoesNotPassThrough(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest']);
		$noon = $this->at('2026-03-10 12:00');

		$this->assertTrue($delivery->holds('favourite', true, $noon, $this->berlin));
		$this->assertTrue($delivery->holds('mention', false, $noon, $this->berlin));
		$this->assertFalse($delivery->holds('mention', true, $noon, $this->berlin));
		$this->assertFalse($delivery->holds(NotificationDelivery::SUBJECT_DIRECT, false, $noon, $this->berlin));
	}

	public function testQuietHoursHoldInInstantModeAndEndAtTheWindowsEnd(): void {
		$delivery = (new NotificationDelivery())->apply(['quiet' => ['from' => '22:00', 'to' => '07:00']]);

		$this->assertTrue($delivery->holds('favourite', false, $this->at('2026-03-10 23:30'), $this->berlin));
		$this->assertTrue($delivery->holds('favourite', false, $this->at('2026-03-11 06:59'), $this->berlin));
		$this->assertFalse($delivery->holds('favourite', false, $this->at('2026-03-11 07:00'), $this->berlin));
		$this->assertFalse($delivery->holds('favourite', false, $this->at('2026-03-10 21:59'), $this->berlin));
		// the pass-throughs still pass
		$this->assertFalse($delivery->holds(NotificationDelivery::SUBJECT_DIRECT, false, $this->at('2026-03-10 23:30'), $this->berlin));
	}

	public function testQuietHoursAreReadInTheReadersZone(): void {
		$delivery = (new NotificationDelivery())->apply(['quiet' => ['from' => '22:00', 'to' => '07:00']]);
		// 23:30 in Berlin is 22:30 UTC, 17:30 in New York
		$moment = $this->at('2026-03-10 23:30');

		$this->assertTrue($delivery->isQuietAt($moment, $this->berlin));
		$this->assertFalse($delivery->isQuietAt($moment, new DateTimeZone('America/New_York')));
	}

	public function testAQuietWindowInsideOneDayDoesNotWrap(): void {
		$delivery = (new NotificationDelivery())->apply(['quiet' => ['from' => '13:00', 'to' => '14:00']]);

		$this->assertTrue($delivery->isQuietAt($this->at('2026-03-10 13:30'), $this->berlin));
		$this->assertFalse($delivery->isQuietAt($this->at('2026-03-10 14:00'), $this->berlin));
		$this->assertFalse($delivery->isQuietAt($this->at('2026-03-10 02:00'), $this->berlin));
	}

	// -- next digest ------------------------------------------------------------

	public function testNothingIsDueWhenNothingIsScheduled(): void {
		$this->assertNull((new NotificationDelivery())->nextDigestAfter($this->at('2026-03-10 12:00'), $this->berlin));
	}

	public function testTheNextDigestIsTheEarliestTimeAfterTheMoment(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest', 'times' => ['08:00', '18:00']]);

		$next = $delivery->nextDigestAfter($this->at('2026-03-10 12:00'), $this->berlin);

		$this->assertSame('2026-03-10 18:00 Europe/Berlin', $next->format('Y-m-d H:i e'));
	}

	public function testATimeAlreadyPassedTodayIsDueTomorrow(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest', 'times' => ['08:00', '18:00']]);

		$next = $delivery->nextDigestAfter($this->at('2026-03-10 19:00'), $this->berlin);

		$this->assertSame('2026-03-11 08:00', $next->format('Y-m-d H:i'));
	}

	public function testExactlyTheDigestTimeIsNotAfterIt(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest', 'times' => ['08:00']]);

		$next = $delivery->nextDigestAfter($this->at('2026-03-10 08:00'), $this->berlin);

		$this->assertSame('2026-03-11 08:00', $next->format('Y-m-d H:i'));
	}

	public function testTheMomentIsReadInTheReadersZoneNotTheServers(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest', 'times' => ['08:00']]);
		// 23:30 UTC on the 10th is already 00:30 on the 11th in Berlin
		$next = $delivery->nextDigestAfter($this->at('2026-03-10 23:30', new DateTimeZone('UTC')), $this->berlin);

		$this->assertSame('2026-03-11 08:00 +01:00', $next->format('Y-m-d H:i P'));
	}

	public function testTheSpringClockChangeKeepsTheDigestAtItsLocalTime(): void {
		// Berlin moves from CET to CEST at 02:00 on 2026-03-29
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest', 'times' => ['08:00']]);

		$next = $delivery->nextDigestAfter($this->at('2026-03-28 20:00'), $this->berlin);

		$this->assertSame('2026-03-29 08:00 +02:00', $next->format('Y-m-d H:i P'));
		// eleven hours of wall clock, ten of real time
		$this->assertSame(11 * 3600, $next->getTimestamp() - $this->at('2026-03-28 20:00')->getTimestamp());
	}

	public function testTheAutumnClockChangeKeepsTheDigestAtItsLocalTime(): void {
		// Berlin moves from CEST back to CET at 03:00 on 2026-10-25
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest', 'times' => ['08:00']]);

		$next = $delivery->nextDigestAfter($this->at('2026-10-24 20:00'), $this->berlin);

		$this->assertSame('2026-10-25 08:00 +01:00', $next->format('Y-m-d H:i P'));
		$this->assertSame(13 * 3600, $next->getTimestamp() - $this->at('2026-10-24 20:00')->getTimestamp());
	}

	public function testADigestTimeInTheClockChangeGapStillHappensOnce(): void {
		$delivery = (new NotificationDelivery())->apply(['mode' => 'digest', 'times' => ['02:30']]);

		$next = $delivery->nextDigestAfter($this->at('2026-03-29 00:00'), $this->berlin);

		$this->assertNotNull($next);
		$this->assertSame('2026-03-29', $next->format('Y-m-d'));
		$this->assertGreaterThan($this->at('2026-03-29 00:00'), $next);
	}

	public function testTheEndOfQuietHoursIsADigestEvenInInstantMode(): void {
		$delivery = (new NotificationDelivery())->apply(['quiet' => ['from' => '22:00', 'to' => '07:00']]);

		$next = $delivery->nextDigestAfter($this->at('2026-03-10 23:00'), $this->berlin);

		$this->assertSame('2026-03-11 07:00', $next->format('Y-m-d H:i'));
		// and the digest times play no part while the mode is instant
		$this->assertSame(
			'2026-03-11 07:00',
			$delivery->nextDigestAfter($this->at('2026-03-10 09:00'), $this->berlin)->format('Y-m-d H:i')
		);
	}

	public function testADigestTimeInsideTheQuietWindowIsSkippedForTheWindowsEnd(): void {
		$delivery = (new NotificationDelivery())->apply([
			'mode' => 'digest',
			'times' => ['06:00', '18:00'],
			'quiet' => ['from' => '22:00', 'to' => '07:00'],
		]);

		$next = $delivery->nextDigestAfter($this->at('2026-03-10 23:00'), $this->berlin);

		$this->assertSame('2026-03-11 07:00', $next->format('Y-m-d H:i'));
	}

	public function testDigestTimesAndTheQuietEndAreWeighedTogether(): void {
		$delivery = (new NotificationDelivery())->apply([
			'mode' => 'digest',
			'times' => ['12:00'],
			'quiet' => ['from' => '22:00', 'to' => '07:00'],
		]);

		$this->assertSame(
			'2026-03-10 12:00',
			$delivery->nextDigestAfter($this->at('2026-03-10 08:00'), $this->berlin)->format('Y-m-d H:i')
		);
		$this->assertSame(
			'2026-03-11 07:00',
			$delivery->nextDigestAfter($this->at('2026-03-10 13:00'), $this->berlin)->format('Y-m-d H:i')
		);
	}
}
