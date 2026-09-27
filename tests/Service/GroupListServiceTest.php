<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Service\GroupListService;
use OCA\Social\Service\SectionsService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class GroupListServiceTest extends TestCase {
	private const CLOUD = 'https://cloud.example/apps/social/@';

	private IGroupManager|Stub $groupManager;
	private SectionsService|Stub $sectionsService;
	/** Group ids the administrator has *not* chosen to become lists. */
	private array $notChosen = [];
	private IUserManager|Stub $userManager;
	private ListsRequest|Stub $listsRequest;
	private GroupListService $service;

	/** @var array<string, string[]> group id => user ids */
	private array $groups = [];
	/** @var array<string, string> group id => display name */
	private array $groupNames = [];
	/** @var string[] the users that have a Social account */
	private array $withActor = ['alice', 'bob', 'carol'];
	/** @var array<int, MastodonList> */
	private array $lists = [];
	/** @var array<int, string[]> list id => member actor ids */
	private array $members = [];
	private array $writes = [];
	private int $nextId = 1;

	protected function setUp(): void {
		$this->groupManager = $this->createStub(IGroupManager::class);
		$this->groupManager->method('get')->willReturnCallback(fn (string $gid): ?IGroup => isset($this->groups[$gid]) ? $this->group($gid) : null);
		$this->groupManager->method('getUserGroups')->willReturnCallback(function (IUser $user): array {
			$groups = [];
			foreach ($this->groups as $gid => $uids) {
				if (in_array($user->getUID(), $uids, true)) {
					$groups[] = $this->group($gid);
				}
			}
			return $groups;
		});

		$this->userManager = $this->createStub(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => $this->user($uid));

		$actorsRequest = $this->createStub(ActorsRequest::class);
		$actorsRequest->method('getFromUserId')->willReturnCallback(function (string $uid): Person {
			if (!in_array($uid, $this->withActor, true)) {
				throw new ActorDoesNotExistException();
			}
			$person = new Person();
			$person->setId(self::CLOUD . $uid);
			$person->setUserId($uid);
			return $person;
		});

		$this->listsRequest = $this->createStub(ListsRequest::class);
		$this->listsRequest->method('getByActor')->willReturnCallback(fn (string $actorId): array => array_values(array_filter(
			$this->lists, static fn (MastodonList $l): bool => $l->getOwnerId() === $actorId
		)));
		$this->listsRequest->method('getByGroup')->willReturnCallback(fn (string $gid): array => array_values(array_filter(
			$this->lists, static fn (MastodonList $l): bool => $l->getGroupId() === $gid
		)));
		$this->listsRequest->method('getGroupIds')->willReturnCallback(fn (): array => array_values(array_unique(array_filter(
			array_map(static fn (MastodonList $l): string => $l->getGroupId(), $this->lists)
		))));
		$this->listsRequest->method('getMemberIds')->willReturnCallback(fn (MastodonList $l): array => $this->members[$l->getId()] ?? []);
		$this->listsRequest->method('create')->willReturnCallback(function (MastodonList $list): MastodonList {
			$list->setId($this->nextId++);
			$this->lists[$list->getId()] = $list;
			$this->writes[] = ['create', $list->getId(), $list->getTitle(), $list->getGroupId()];
			return $list;
		});
		$this->listsRequest->method('update')->willReturnCallback(function (MastodonList $list): void {
			$this->writes[] = ['update', $list->getId(), $list->getTitle()];
		});
		$this->listsRequest->method('delete')->willReturnCallback(function (MastodonList $list): void {
			unset($this->lists[$list->getId()], $this->members[$list->getId()]);
			$this->writes[] = ['delete', $list->getId()];
		});
		$this->listsRequest->method('deleteByGroup')->willReturnCallback(function (string $gid): void {
			foreach ($this->lists as $id => $list) {
				if ($list->getGroupId() === $gid) {
					unset($this->lists[$id], $this->members[$id]);
					$this->writes[] = ['delete', $id];
				}
			}
		});
		$this->listsRequest->method('updateTitleByGroup')->willReturnCallback(function (string $gid, string $title): int {
			$this->writes[] = ['retitle', $gid, $title];
			return 1;
		});
		$this->listsRequest->method('addMember')->willReturnCallback(function (MastodonList $list, string $actorId): void {
			$this->writes[] = ['addMember', $list->getId(), $actorId];
			$this->members[$list->getId()][] = $actorId;
		});
		$this->listsRequest->method('removeMember')->willReturnCallback(function (MastodonList $list, string $actorId): void {
			$this->writes[] = ['removeMember', $list->getId(), $actorId];
			$this->members[$list->getId()] = array_values(array_diff($this->members[$list->getId()] ?? [], [$actorId]));
		});

		// every group this test has is one an administrator chose, unless a
		// test says otherwise: what the allow-list does is its own test below
		$this->sectionsService = $this->createStub(SectionsService::class);
		$this->sectionsService->method('groupHasList')
			->willReturnCallback(fn (string $gid): bool => !in_array($gid, $this->notChosen, true));

		$this->service = new GroupListService(
			$this->groupManager, $this->userManager, $actorsRequest, $this->listsRequest,
			$this->sectionsService, new NullLogger()
		);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	private function group(string $gid): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($this->groupNames[$gid] ?? $gid);
		$group->method('getUsers')->willReturnCallback(fn (): array => array_map(fn (string $uid): IUser => $this->user($uid), $this->groups[$gid] ?? []));
		$group->method('count')->willReturnCallback(fn (): int => count($this->groups[$gid] ?? []));

		return $group;
	}

	private function viewer(string $uid): Person {
		$person = new Person();
		$person->setId(self::CLOUD . $uid);
		$person->setUserId($uid);

		return $person;
	}

	private function groupList(int $id, string $owner, string $gid, string $title): MastodonList {
		$list = (new MastodonList())->setId($id)->setOwnerId(self::CLOUD . $owner)->setGroupId($gid)->setTitle($title);
		$this->lists[$id] = $list;
		$this->nextId = max($this->nextId, $id + 1);

		return $list;
	}

	public function testEveryGroupTheViewerIsInBecomesAListOfTheirs(): void {
		$this->groups = ['design' => ['alice', 'bob', 'dave'], 'berlin' => ['alice', 'carol']];
		$this->groupNames = ['design' => 'Design team'];

		$this->assertSame(2, $this->service->ensureForViewer($this->viewer('alice')));

		$this->assertSame([
			['create', 1, 'Design team', 'design'],
			// bob has an account and is in; dave has none and is not; alice
			// owns the list and is not her own member
			['addMember', 1, self::CLOUD . 'bob'],
			['create', 2, 'berlin', 'berlin'],
			['addMember', 2, self::CLOUD . 'carol'],
		], $this->writes);
	}

	public function testAListAlreadyThereIsLeftAloneAndOneForAGroupLeftIsRemoved(): void {
		$this->groups = ['design' => ['alice', 'bob']];
		$this->groupList(4, 'alice', 'design', 'Design');
		$this->groupList(5, 'alice', 'sales', 'Sales'); // alice is not in sales any more
		$this->groupList(6, 'alice', '', 'Friends'); // her own, untouched

		$this->assertSame(0, $this->service->ensureForViewer($this->viewer('alice')));

		$this->assertSame([['delete', 5]], $this->writes);
	}

	public function testAGroupTooLargeToBeAListGetsNone(): void {
		$this->groups = ['everyone' => array_map(static fn (int $i): string => "user$i", range(1, GroupListService::MAX_GROUP_SIZE + 1))];

		$this->assertSame(0, $this->service->ensureForViewer($this->viewer('alice')));
		$this->assertSame([], $this->writes);
	}

	/**
	 * Nothing until an administrator has chosen, which is what the empty
	 * default means: a group becoming a list tells everybody in it who else is
	 * in it.
	 */
	public function testAGroupTheAdministratorDidNotChooseGetsNoList(): void {
		$this->groups = ['design' => ['alice', 'bob'], 'sales' => ['alice']];
		$this->notChosen = ['sales'];

		$this->assertSame(1, $this->service->ensureForViewer($this->viewer('alice')));

		$made = [];
		foreach ($this->writes as $write) {
			if ($write[0] === 'create') {
				$made[] = $write[3];
			}
		}
		$this->assertSame(['design'], $made);
	}

	/**
	 * Deselecting a group is the one case `ensureForViewer` cannot see -- it
	 * only ever looks at the groups the viewer is in -- so the lists go on the
	 * next reconcile.
	 */
	public function testDeselectingAGroupTakesItsListsAwayOnTheNextReconcile(): void {
		$this->groups = ['design' => ['alice']];
		$this->groupList(1, 'alice', 'design', 'Design');
		$this->notChosen = ['design'];

		$this->service->reconcile();

		$this->assertSame([['delete', 1]], $this->writes);
	}

	public function testAViewerWithoutANextcloudUserGetsNothing(): void {
		$this->groups = ['design' => ['alice']];

		$this->assertSame(0, $this->service->ensureForViewer(new Person()));
		$this->assertSame([], $this->writes);
	}

	public function testJoiningAGroupPutsTheAccountIntoEveryListThatFollowsIt(): void {
		$this->groups = ['design' => ['alice', 'bob', 'carol']];
		$this->groupList(1, 'alice', 'design', 'Design');
		$this->groupList(2, 'bob', 'design', 'Design');
		$this->groupList(3, 'carol', 'design', 'Design');

		$this->service->onUserAdded($this->group('design'), $this->user('carol'));

		// into alice's and bob's; not into her own
		$this->assertSame([
			['addMember', 1, self::CLOUD . 'carol'],
			['addMember', 2, self::CLOUD . 'carol'],
		], $this->writes);
	}

	public function testJoiningWithoutASocialAccountChangesNothingYet(): void {
		$this->groupList(1, 'alice', 'design', 'Design');

		$this->service->onUserAdded($this->group('design'), $this->user('dave'));

		$this->assertSame([], $this->writes);
	}

	public function testLeavingAGroupTakesTheAccountOutAndTheirOwnListWithIt(): void {
		$this->groupList(1, 'alice', 'design', 'Design');
		$this->groupList(2, 'bob', 'design', 'Design');
		$this->members = [1 => [self::CLOUD . 'bob'], 2 => [self::CLOUD . 'alice']];

		$this->service->onUserRemoved($this->group('design'), $this->user('bob'));

		$this->assertSame([
			['removeMember', 1, self::CLOUD . 'bob'],
			['delete', 2],
		], $this->writes);
	}

	public function testADeletedGroupTakesEveryListThatFollowedIt(): void {
		$this->groupList(1, 'alice', 'design', 'Design');
		$this->groupList(2, 'bob', 'design', 'Design');
		$this->groupList(3, 'alice', 'sales', 'Sales');

		$this->service->onGroupDeleted($this->group('design'));

		$this->assertSame([['delete', 1], ['delete', 2]], $this->writes);
	}

	public function testARenamedGroupRetitlesItsLists(): void {
		$this->groupNames = ['design' => 'Product design'];

		$this->service->onGroupRenamed($this->group('design'));

		$this->assertSame([['retitle', 'design', 'Product design']], $this->writes);
	}

	public function testReconcileMakesEveryGroupListMatchItsGroup(): void {
		$this->groups = ['design' => ['alice', 'bob', 'carol']];
		$this->groupNames = ['design' => 'Design'];
		$this->groupList(1, 'alice', 'design', 'Old name');
		// bob is missing, and dave (who left, or never had an account) is stale
		$this->members = [1 => [self::CLOUD . 'carol', self::CLOUD . 'dave']];
		$this->groupList(2, 'alice', 'gone', 'Gone'); // the group no longer exists

		$this->assertSame(1, $this->service->reconcile());

		$this->assertSame([
			['update', 1, 'Design'],
			['addMember', 1, self::CLOUD . 'bob'],
			['removeMember', 1, self::CLOUD . 'dave'],
			['delete', 2],
		], $this->writes);
	}

	public function testIsGroupListReadsTheBinding(): void {
		$this->assertTrue(GroupListService::isGroupList((new MastodonList())->setGroupId('design')));
		$this->assertFalse(GroupListService::isGroupList(new MastodonList()));
	}
}
