<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\SetupNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class SetupNotificationServiceTest extends TestCase {
	/** @var array<string, string> */
	private array $values = [];
	/** @var string[] */
	private array $notified = [];

	private function service(INotificationManager $manager): SetupNotificationService {
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->values[$key] ?? '');
		$config->method('setAppValue')->willReturnCallback(function (string $key, string $value): void {
			$this->values[$key] = $value;
		});

		$users = [];
		foreach (['root', 'ops'] as $uid) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}
		$admins = $this->createStub(IGroup::class);
		$admins->method('getUsers')->willReturn($users);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($admins);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_762_560_000);

		return new SetupNotificationService($groups, $manager, $config, $time, new NullLogger());
	}

	private function manager(): INotificationManager {
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			foreach (['setApp', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
				$notification->method($setter)->willReturnSelf();
			}
			$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification): INotification {
				$this->notified[] = $uid;

				return $notification;
			});

			return $notification;
		});

		return $manager;
	}

	public function testEveryAdministratorIsToldOnce(): void {
		$manager = $this->manager();
		$manager->expects($this->exactly(2))->method('notify');
		$service = $this->service($manager);

		$service->notifyAdminsOnce();
		$service->notifyAdminsOnce();

		$this->assertSame(['root', 'ops'], $this->notified);
		$this->assertSame('1762560000', $this->values[ConfigService::SETUP_ADMINS_NOTIFIED]);
	}

	public function testTheNotificationsAreTakenDownAfterTheSetup(): void {
		$manager = $this->manager();
		$manager->expects($this->once())->method('markProcessed');

		$this->service($manager)->withdraw();
	}
}
