<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ModeratorService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Who counts as a moderator.
 *
 * An administrator, or whoever the administrator delegated the Social settings
 * section to — which is what the moderation routes already accept. Everything
 * that asked the question for itself asked a narrower one: the dashboard
 * widgets offered themselves to the `admin` group, and a new report walked
 * every Nextcloud account to find the same group the hard way.
 */
#[AllowMockObjectsWithoutExpectations]
class ModeratorServiceTest extends TestCase {
	private IGroupManager|MockObject $groupManager;
	private IUserManager|MockObject $userManager;
	private ISettingsManager|MockObject $settingsManager;
	private ContainerInterface|Stub $container;

	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->settingsManager = $this->createMock(ISettingsManager::class);
		$this->container = $this->createStub(ContainerInterface::class);
	}

	private function service(): ModeratorService {
		return new ModeratorService(
			$this->groupManager,
			$this->userManager,
			$this->settingsManager,
			$this->container,
			new NullLogger(),
		);
	}

	/** @param string[] $uids */
	private function group(string $groupId, array $uids): IGroup|MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($groupId);
		$group->method('getUsers')->willReturn(array_map(
			function (string $uid): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);

				return $user;
			},
			$uids
		));

		return $group;
	}

	/** The settings app answering that the section is delegated to these groups. */
	private function delegatedTo(string ...$groupIds): void {
		$authorized = [];
		foreach ($groupIds as $groupId) {
			$authorized[] = new class($groupId) {
				public function __construct(
					private string $groupId,
				) {
				}

				public function getGroupId(): string {
					return $this->groupId;
				}
			};
		}

		$this->container->method('get')->willReturn(new class($authorized) {
			/** @param object[] $authorized */
			public function __construct(
				private array $authorized,
			) {
			}

			/** @return object[] */
			public function findExistingGroupsForClass(string $class): array {
				return $this->authorized;
			}
		});
	}

	public function testAnAdministratorMayModerate(): void {
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(true);

		$this->assertTrue($this->service()->isModerator('alice'));
	}

	public function testWhoeverTheSettingsSectionIsDelegatedToMayModerate(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$bob = $this->createStub(IUser::class);
		$this->userManager->method('get')->with('bob')->willReturn($bob);
		$this->settingsManager->method('getAllowedAdminSettings')
			->with('social', $bob)
			->willReturn([1 => ['a setting']]);

		$this->assertTrue($this->service()->isModerator('bob'));
	}

	public function testAnOrdinaryUserMayNot(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->userManager->method('get')->willReturn($this->createStub(IUser::class));
		$this->settingsManager->method('getAllowedAdminSettings')->willReturn([]);

		$this->assertFalse($this->service()->isModerator('carol'));
	}

	public function testNobodyIsNotAModerator(): void {
		$this->groupManager->expects($this->never())->method('isAdmin');

		$this->assertFalse($this->service()->isModerator(''));
	}

	public function testAUserThatNoLongerExistsIsNotAModerator(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->userManager->method('get')->willReturn(null);

		$this->assertFalse($this->service()->isModerator('deleted'));
	}

	/**
	 * The set that has to be told about a report: the administrators and the
	 * groups the section is delegated to, read from the groups rather than by
	 * asking every account on the instance.
	 */
	public function testTheModeratorsAreTheAdminGroupAndTheDelegatedGroups(): void {
		$this->delegatedTo('moderators');
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $groupId): ?IGroup => match ($groupId) {
				'admin' => $this->group('admin', ['admin', 'alice']),
				'moderators' => $this->group('moderators', ['alice', 'bob']),
				default => null,
			}
		);
		$this->userManager->expects($this->never())->method('search');

		$this->assertSame(['admin', 'alice', 'bob'], $this->service()->moderators());
	}

	public function testAGroupThatIsNotThereIsNotAFailure(): void {
		$this->delegatedTo('gone');
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $groupId): ?IGroup => $groupId === 'admin'
				? $this->group('admin', ['admin'])
				: null
		);

		$this->assertSame(['admin'], $this->service()->moderators());
	}

	/** An installation whose settings app cannot answer tells the administrators. */
	public function testTheAdministratorsAreToldWhenTheDelegationCannotBeRead(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('no such service'));
		$this->groupManager->method('get')->willReturn($this->group('admin', ['admin']));

		$this->assertSame(['admin'], $this->service()->moderators());
	}
}
