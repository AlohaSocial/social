<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\NotificationDelivery;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\NotificationDeliveryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The delivery setting's storage and the bookkeeping around a mode switch.
 *
 * What matters most is the switch into a digest: the clock has to start at
 * that moment, or the first digest reports everything the account was ever
 * told, which is the one thing somebody turning the bell down did not ask for.
 */
#[AllowMockObjectsWithoutExpectations]
class NotificationDeliveryServiceTest extends TestCase {
	private const NOW = 1773230400; // 2026-03-11 12:00:00 UTC
	private const ALICE = 'alice';

	private ConfigService|Stub $configService;
	private IUserConfig|Stub $userConfig;
	private NotificationDeliveryService $service;

	/** @var array<string, array<string, string>> user => key => value, this app's user values */
	private array $stored = [];
	/** @var array<string, string> user => the zone Nextcloud's own settings hold */
	private array $zones = [];

	protected function setUp(): void {
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getUserValue')
			->willReturnCallback(fn (string $key, string $userId = '', string $app = ''): string
				=> $this->stored[$userId][$key] ?? '');
		$this->configService->method('setValueForUser')
			->willReturnCallback(function (string $userId, string $key, string $value): void {
				$this->stored[$userId][$key] = $value;
			});

		$this->userConfig = $this->createStub(IUserConfig::class);
		$this->userConfig->method('getValueString')
			->willReturnCallback(fn (string $userId, string $app, string $key, string $default = ''): string
				=> ($app === 'core' && $key === 'timezone') ? ($this->zones[$userId] ?? $default) : $default);
		$this->userConfig->method('deleteUserConfig')
			->willReturnCallback(function (string $userId, string $app, string $key): void {
				unset($this->stored[$userId][$key]);
			});
		$this->userConfig->method('searchUsersByValueString')
			->willReturnCallback(function (string $app, string $key, string $value): \Generator {
				foreach ($this->stored as $userId => $values) {
					if (($values[$key] ?? null) === $value) {
						yield $userId;
					}
				}
			});

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->service = new NotificationDeliveryService($this->configService, $this->userConfig, $time);
	}

	private function value(string $key): ?string {
		return $this->stored[self::ALICE][$key] ?? null;
	}

	public function testAnAccountThatNeverTouchedTheSettingGetsTheDefaults(): void {
		$this->assertSame((new NotificationDelivery())->toArray(), $this->service->of(self::ALICE)->toArray());
	}

	public function testSaveWritesOneJsonValueAndReadsItBack(): void {
		$saved = $this->service->save(self::ALICE, ['mode' => 'digest', 'times' => ['20:00', '09:00']]);

		$this->assertSame(['09:00', '20:00'], $saved->getTimes());
		$this->assertSame(
			$saved->toArray(),
			json_decode((string)$this->value(NotificationDeliveryService::CONFIG_KEY), true)
		);
		$this->assertSame($saved->toArray(), $this->service->of(self::ALICE)->toArray());
	}

	public function testSaveLeavesWhatWasNotNamed(): void {
		$this->service->save(self::ALICE, ['times' => ['07:00']]);
		$this->service->save(self::ALICE, ['passthrough' => ['direct' => false]]);

		$delivery = $this->service->of(self::ALICE);
		$this->assertSame(['07:00'], $delivery->getTimes());
		$this->assertFalse($delivery->toArray()['passthrough']['direct']);
		$this->assertSame('instant', $delivery->getMode());
	}

	public function testAnInvalidChangeIsRefusedAndNothingIsWritten(): void {
		try {
			$this->service->save(self::ALICE, ['mode' => 'hourly']);
			$this->fail('the change should have been refused');
		} catch (InvalidResourceException) {
		}

		$this->assertNull($this->value(NotificationDeliveryService::CONFIG_KEY));
	}

	public function testAStoredValueThatIsNotJsonReadsAsTheDefaults(): void {
		$this->stored[self::ALICE][NotificationDeliveryService::CONFIG_KEY] = 'not json';

		$this->assertSame((new NotificationDelivery())->toArray(), $this->service->of(self::ALICE)->toArray());
	}

	// -- the schedule flag and the clock ------------------------------------

	public function testSwitchingToADigestStartsTheClockNowAndFlagsTheUser(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);

		$this->assertSame(self::NOW, $this->service->lastDigestAt(self::ALICE));
		$this->assertSame('1', $this->value(NotificationDeliveryService::SCHEDULED_KEY));
		$this->assertSame([self::ALICE], $this->service->scheduledUserIds());
	}

	public function testChangingTheTimesOfADigestDoesNotMoveTheClock(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->service->setLastDigestAt(self::ALICE, self::NOW - 3600);

		$this->service->save(self::ALICE, ['times' => ['06:00']]);

		$this->assertSame(self::NOW - 3600, $this->service->lastDigestAt(self::ALICE));
	}

	public function testQuietHoursAloneScheduleTheUserToo(): void {
		$this->service->save(self::ALICE, ['quiet' => ['from' => '22:00', 'to' => '07:00']]);

		$this->assertSame('1', $this->value(NotificationDeliveryService::SCHEDULED_KEY));
		$this->assertSame(self::NOW, $this->service->lastDigestAt(self::ALICE));
	}

	public function testTurningADigestOnOverQuietHoursRestartsTheClock(): void {
		$this->service->save(self::ALICE, ['quiet' => ['from' => '22:00', 'to' => '07:00']]);
		$this->service->setLastDigestAt(self::ALICE, self::NOW - 7200);

		$this->service->save(self::ALICE, ['mode' => 'digest']);

		$this->assertSame(self::NOW, $this->service->lastDigestAt(self::ALICE));
	}

	public function testSwitchingEverythingOffForgetsTheClockAndTheFlag(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest', 'quiet' => ['from' => '22:00', 'to' => '07:00']]);

		$this->service->save(self::ALICE, ['mode' => 'instant', 'quiet' => ['from' => '', 'to' => '']]);

		$this->assertNull($this->value(NotificationDeliveryService::SCHEDULED_KEY));
		$this->assertSame(0, $this->service->lastDigestAt(self::ALICE));
		$this->assertSame([], $this->service->scheduledUserIds());
	}

	public function testSwitchingToInstantWithQuietHoursKeptStaysScheduled(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest', 'quiet' => ['from' => '22:00', 'to' => '07:00']]);
		$this->service->setLastDigestAt(self::ALICE, self::NOW - 7200);

		$this->service->save(self::ALICE, ['mode' => 'instant']);

		$this->assertSame('1', $this->value(NotificationDeliveryService::SCHEDULED_KEY));
		$this->assertSame(self::NOW - 7200, $this->service->lastDigestAt(self::ALICE));
	}

	// -- holds ----------------------------------------------------------------

	public function testNothingIsHeldForAnAccountOnInstant(): void {
		$this->assertFalse($this->service->holds(self::ALICE, 'favourite', false));
	}

	public function testADigestHoldsALikeAndPassesADirectMessage(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);

		$this->assertTrue($this->service->holds(self::ALICE, 'favourite', true));
		$this->assertFalse($this->service->holds(self::ALICE, NotificationDelivery::SUBJECT_DIRECT, false));
	}

	public function testQuietHoursAreReadInTheUsersOwnZone(): void {
		// 12:00 UTC is 13:00 in Berlin and 07:00 in New York
		$this->service->save(self::ALICE, ['quiet' => ['from' => '12:30', 'to' => '14:00']]);

		$this->zones[self::ALICE] = 'Europe/Berlin';
		$this->assertTrue($this->service->holds(self::ALICE, 'favourite', false));

		$this->zones[self::ALICE] = 'America/New_York';
		$this->assertFalse($this->service->holds(self::ALICE, 'favourite', false));
	}

	// -- the zone -------------------------------------------------------------

	public function testTheZoneIsNextcloudsSettingForTheUser(): void {
		$this->zones[self::ALICE] = 'Asia/Tokyo';

		$this->assertSame('Asia/Tokyo', $this->service->timezoneOf(self::ALICE)->getName());
	}

	public function testWithoutASettingTheZoneIsTheServers(): void {
		$this->assertSame(date_default_timezone_get(), $this->service->timezoneOf(self::ALICE)->getName());
	}

	public function testAZoneNobodyHasHeardOfIsUtc(): void {
		$this->zones[self::ALICE] = 'Mars/Olympus_Mons';

		$this->assertSame('UTC', $this->service->timezoneOf(self::ALICE)->getName());
	}
}
