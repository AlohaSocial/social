<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTime;
use DateTimeImmutable;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\NotificationRequest;
use OCA\Social\Model\NotificationDelivery;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MarkerService;
use OCA\Social\Service\NotificationDeliveryService;
use OCA\Social\Service\NotificationInboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
	private const ALICE_ID = 'https://cloud.example/users/alice';

	private ConfigService|Stub $configService;
	private IUserConfig|Stub $userConfig;
	private StreamRequest|Stub $streamRequest;
	private MarkerService|Stub $markerService;
	private INotificationManager|Stub $notificationManager;
	private NotificationDeliveryService $service;

	/** @var array<string, array<string, string>> user => key => value, this app's user values */
	private array $stored = [];
	/** @var array<string, string> user => the zone Nextcloud's own settings hold */
	private array $zones = [];
	/** @var array<string, int> what the database answers, by sub-type */
	private array $unread = [];
	/** @var array<int, array{string, int, int}> [marker, since, until] of every count asked for */
	private array $counted = [];
	/** @var string[] users with no account */
	private array $accountless = [];
	private string $marker = '0';
	/** @var array<int, array> every notification raised */
	private array $raised = [];
	/** @var array<int, array> every notification taken down */
	private array $withdrawn = [];
	/** @var array<string, array> what each notification double was told */
	private array $fields = [];
	/** @var Stream[] what the notification policy holds past the marker */
	private array $held = [];
	/** @var array<int, array{string}> the markers the held notifications were asked past */
	private array $heldAsked = [];
	/** how many senders wait in the requests inbox */
	private int $waiting = 0;

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

		$actorsRequest = $this->createStub(ActorsRequest::class);
		$actorsRequest->method('getFromUserId')->willReturnCallback(function (string $userId): Person {
			if (in_array($userId, $this->accountless, true)) {
				throw new ActorDoesNotExistException('Actor not found');
			}
			$actor = new Person();
			$actor->setId('https://cloud.example/users/' . $userId);
			$actor->setUserId($userId);

			return $actor;
		});

		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->streamRequest->method('countNotificationsBySubType')->willReturnCallback(
			function (Person $actor, int|string $afterNid, DateTime $since, DateTime $until): array {
				$this->counted[] = [(string)$afterNid, $since->getTimestamp(), $until->getTimestamp()];

				return $this->unread;
			}
		);

		$this->markerService = $this->createStub(MarkerService::class);
		$this->markerService->method('lastReadId')->willReturnCallback(fn (): string => $this->marker);

		$this->notificationManager = $this->createStub(INotificationManager::class);
		$this->notificationManager->method('createNotification')
			->willReturnCallback(fn (): INotification => $this->notification());
		$this->notificationManager->method('notify')->willReturnCallback(function (INotification $n): void {
			$this->raised[] = $this->fields[spl_object_hash($n)];
		});
		$this->notificationManager->method('markProcessed')->willReturnCallback(function (INotification $n): void {
			$this->withdrawn[] = $this->fields[spl_object_hash($n)];
		});

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/social/');

		$this->service = new NotificationDeliveryService(
			$this->configService,
			$this->userConfig,
			$time,
			$actorsRequest,
			$this->streamRequest,
			$this->markerService,
			$this->notificationManager,
			$urlGenerator,
			new NullLogger(),
			$this->inbox()
		);
	}

	private function inbox(): NotificationInboxService {
		$inbox = $this->createStub(NotificationInboxService::class);
		$inbox->method('held')->willReturnCallback(function (Person $viewer, int|string $sinceId): array {
			$this->heldAsked[] = [(string)$sinceId];

			return $this->held;
		});
		$inbox->method('requests')->willReturnCallback(fn (): array => array_map(
			static fn (int $i): NotificationRequest => new NotificationRequest(new Person(), 1, 0, 0),
			($this->waiting > 0) ? range(1, $this->waiting) : []
		));

		return $inbox;
	}

	/** A notification the policy holds, received at a moment. */
	private function heldAt(string $subType, int $at): Stream {
		$row = new Stream();
		$row->setSubType($subType);
		$row->setPublishedTime($at);

		return $row;
	}

	private function notification(): INotification {
		$notification = $this->createStub(INotification::class);
		$key = spl_object_hash($notification);
		$this->fields[$key] = [];

		foreach (['setApp' => 'app', 'setUser' => 'user'] as $method => $field) {
			$notification->method($method)->willReturnCallback(
				function (string $value) use ($notification, $key, $field): INotification {
					$this->fields[$key][$field] = $value;

					return $notification;
				}
			);
		}
		$notification->method('setDateTime')->willReturnCallback(
			function (\DateTime $at) use ($notification, $key): INotification {
				$this->fields[$key]['at'] = $at->getTimestamp();

				return $notification;
			}
		);
		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $id) use ($notification, $key): INotification {
				$this->fields[$key]['object'] = [$type, $id];

				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters = []) use ($notification, $key): INotification {
				$this->fields[$key]['subject'] = $subject;
				$this->fields[$key]['parameters'] = $parameters;

				return $notification;
			}
		);

		return $notification;
	}

	private function until(int $timestamp): DateTimeImmutable {
		return new DateTimeImmutable('@' . $timestamp);
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
		// the final digest covered up to now, and the quiet hours carry on from there
		$this->assertSame(self::NOW, $this->service->lastDigestAt(self::ALICE));
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

	// -- the digest -------------------------------------------------------------

	public function testADigestCountsTheUnreadRowsPerSubjectInTheSubjectsOrder(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->unread = [Follow::TYPE => 1, Like::TYPE => 6, Mention::TYPE => 2, Announce::TYPE => 4];

		$digest = $this->service->digestFor(self::ALICE, $this->until(self::NOW + 3600));

		$this->assertSame(['mention' => 2, 'favourite' => 6, 'reblog' => 4, 'follow' => 1], $digest['counts']);
		$this->assertSame(13, $digest['total']);
		$this->assertSame('https://cloud.example/apps/social/timeline/notifications', $digest['link']);

		$this->assertCount(1, $this->raised);
		$this->assertSame('social', $this->raised[0]['app']);
		$this->assertSame('alice', $this->raised[0]['user']);
		$this->assertSame('digest', $this->raised[0]['subject']);
		$this->assertSame(['notification', 'digest-' . (self::NOW + 3600)], $this->raised[0]['object']);
		$this->assertSame(self::NOW + 3600, $this->raised[0]['at']);
		$this->assertSame($digest, $this->raised[0]['parameters']);
	}

	/** What the policy held was never raised, so the digest does not count it. */
	public function testADigestLeavesOutWhatThePolicyHeldInsideTheWindow(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->service->setLastDigestAt(self::ALICE, self::NOW - 7200);
		$this->marker = '42';
		$this->unread = [Like::TYPE => 3, Mention::TYPE => 1];
		$this->held = [
			$this->heldAt(Like::TYPE, self::NOW - 60),
			$this->heldAt(Mention::TYPE, self::NOW - 60),
			// before the window: the database did not count it either
			$this->heldAt(Like::TYPE, self::NOW - 9000),
		];

		$digest = $this->service->digestFor(self::ALICE, $this->until(self::NOW));

		$this->assertSame(['favourite' => 2], $digest['counts']);
		$this->assertSame(2, $digest['total']);
		$this->assertSame([['42']], $this->heldAsked, 'held past the same marker the count is past');
	}

	public function testADigestOfNothingButHeldNotificationsIsNotRaised(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->service->setLastDigestAt(self::ALICE, self::NOW - 7200);
		$this->unread = [Like::TYPE => 1];
		$this->held = [$this->heldAt(Like::TYPE, self::NOW - 60)];
		$this->waiting = 1;

		$this->assertNull($this->service->digestFor(self::ALICE, $this->until(self::NOW)));
		$this->assertSame([], $this->raised);
	}

	public function testADigestSaysHowManyPeopleAreWaiting(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->unread = [Like::TYPE => 1];
		$this->waiting = 3;

		$digest = $this->service->digestFor(self::ALICE, $this->until(self::NOW));

		$this->assertSame(3, $digest['waiting']);
		$this->assertSame(1, $digest['total'], 'the waiting are not notifications');
		$this->assertSame($digest, $this->raised[0]['parameters']);
	}

	public function testADigestWithNobodyWaitingSaysNothingAboutIt(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->unread = [Like::TYPE => 1];

		$this->assertArrayNotHasKey('waiting', $this->service->digestFor(self::ALICE, $this->until(self::NOW)));
	}

	/** Requests alone are no reason to ring: they never were a bell notification. */
	public function testRequestsAloneRaiseNoDigest(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->waiting = 2;

		$this->assertNull($this->service->digestFor(self::ALICE, $this->until(self::NOW)));
		$this->assertSame([], $this->raised);
	}

	public function testADigestAsksForTheRowsPastTheMarkerAndInsideTheWindow(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->service->setLastDigestAt(self::ALICE, self::NOW - 7200);
		$this->marker = '123456789012345678';

		$this->service->digestFor(self::ALICE, $this->until(self::NOW));

		$this->assertSame([['123456789012345678', self::NOW - 7200, self::NOW]], $this->counted);
	}

	public function testADigestMovesTheClockToItsCut(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->unread = [Like::TYPE => 1];

		$this->service->digestFor(self::ALICE, $this->until(self::NOW + 600));

		$this->assertSame(self::NOW + 600, $this->service->lastDigestAt(self::ALICE));
	}

	public function testNothingUnreadMeansNoDigestButTheClockStillMoves(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);

		$this->assertNull($this->service->digestFor(self::ALICE, $this->until(self::NOW + 600)));
		$this->assertSame([], $this->raised);
		$this->assertSame([], $this->withdrawn);
		$this->assertSame(self::NOW + 600, $this->service->lastDigestAt(self::ALICE));
	}

	public function testASubTypeWithoutASubjectIsNotCounted(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->unread = [Stream::SUBTYPE_WARNING => 3];

		$this->assertNull($this->service->digestFor(self::ALICE, $this->until(self::NOW)));
	}

	public function testOnlyOneDigestSitsOnTheBell(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->unread = [Like::TYPE => 1];

		$this->service->digestFor(self::ALICE, $this->until(self::NOW));

		$this->assertCount(1, $this->withdrawn);
		$this->assertSame('social', $this->withdrawn[0]['app']);
		$this->assertSame('alice', $this->withdrawn[0]['user']);
		$this->assertSame('digest', $this->withdrawn[0]['subject']);
		// the template names no object: it is every digest of this user's
		$this->assertArrayNotHasKey('object', $this->withdrawn[0]);
	}

	public function testAUserWithoutAnAccountGetsNoDigest(): void {
		$this->accountless[] = self::ALICE;
		$this->service->save(self::ALICE, ['mode' => 'digest']);

		$this->assertNull($this->service->digestFor(self::ALICE, $this->until(self::NOW)));
		$this->assertSame([], $this->counted);
	}

	public function testSwitchingAwayFromADigestRaisesWhatItWasHolding(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->service->setLastDigestAt(self::ALICE, self::NOW - 7200);
		$this->unread = [Mention::TYPE => 2];

		$this->service->save(self::ALICE, ['mode' => 'instant']);

		$this->assertCount(1, $this->raised);
		$this->assertSame(['mention' => 2], $this->raised[0]['parameters']['counts']);
		$this->assertSame([['0', self::NOW - 7200, self::NOW]], $this->counted);
		// and then forgets the clock, since nothing is scheduled any more
		$this->assertSame(0, $this->service->lastDigestAt(self::ALICE));
	}

	public function testSwitchingAwayFromADigestWithNothingUnreadRaisesNothing(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);

		$this->service->save(self::ALICE, ['mode' => 'instant']);

		$this->assertSame([], $this->raised);
	}

	public function testChangingTheTimesOfADigestRaisesNoDigest(): void {
		$this->service->save(self::ALICE, ['mode' => 'digest']);
		$this->unread = [Like::TYPE => 5];

		$this->service->save(self::ALICE, ['times' => ['06:00']]);

		$this->assertSame([], $this->raised);
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
