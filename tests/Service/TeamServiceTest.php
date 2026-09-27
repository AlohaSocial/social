<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\TeamsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\TeamService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An account a group posts from: who may, who is told, and what is recorded.
 */
#[AllowMockObjectsWithoutExpectations]
class TeamServiceTest extends TestCase {
	private const TEAM = 'https://cloud.example/@press';
	private const ALICE = 'https://cloud.example/@alice';

	private TeamsRequest|MockObject $teamsRequest;
	private AccountService|MockObject $accountService;
	private IGroupManager|Stub $groupManager;
	private IUserManager|Stub $userManager;
	private TeamService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->teamsRequest = $this->createMock(TeamsRequest::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->groupManager = $this->createStub(IGroupManager::class);
		$this->userManager = $this->createStub(IUserManager::class);

		$this->userManager->method('get')->willReturn($this->createStub(IUser::class));

		$this->service = new TeamService(
			$this->teamsRequest,
			$this->accountService,
			$this->groupManager,
			$this->userManager,
			new NullLogger(),
		);
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	/**
	 * A user id may not contain a slash, so nothing a real account is stored
	 * under can collide with this — and a team actor therefore never resolves
	 * as somebody's own account on a path that looks one up by user.
	 */
	public function testATeamAccountIsStoredUnderAnIdNoPersonCanHave(): void {
		$this->assertSame('team/press', TeamService::userIdFor('press'));
		$this->assertTrue(TeamService::isTeamUserId('team/press'));
		$this->assertFalse(TeamService::isTeamUserId('alice'));
	}

	public function testGivingAGroupAnAccountBindsTheTwo(): void {
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->teamsRequest->method('getByGroups')->willReturn([]);
		$this->accountService->method('getActor')->willReturn($this->person(self::TEAM));

		$this->accountService->expects($this->once())->method('createActor')
			->with('team/press', 'press');
		$this->teamsRequest->expects($this->once())->method('create')
			->with(self::TEAM, 'press');

		$this->service->create('press', 'press');
	}

	public function testAGroupThatDoesNotExistIsRefusedByName(): void {
		$this->groupManager->method('groupExists')->willReturn(false);

		$this->accountService->expects($this->never())->method('createActor');
		$this->expectException(InvalidResourceException::class);

		$this->service->create('nobody', 'press');
	}

	/** One account per team; a second would be a second voice for one group. */
	public function testAGroupThatAlreadyPostsAsSomethingIsRefused(): void {
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->teamsRequest->method('getByGroups')
			->willReturn([['actor_id' => self::TEAM, 'group_id' => 'press']]);

		$this->accountService->expects($this->never())->method('createActor');
		$this->expectException(InvalidResourceException::class);

		$this->service->create('press', 'press2');
	}

	/**
	 * Asked of the group manager rather than of a stored membership: somebody
	 * who left the group this morning may not post as it this afternoon.
	 */
	public function testAMemberMayPostAsTheirTeam(): void {
		$this->accountService->method('getActor')->willReturn($this->person(self::TEAM));
		$this->teamsRequest->method('groupOf')->willReturn('press');
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertSame(self::TEAM, $this->service->assertMayPostAs('alice', '@press')->getId());
	}

	/**
	 * Which groups exist and what they post as is not a thing to confirm to
	 * somebody outside them, so this is the same answer as a handle that names
	 * no team at all.
	 */
	public function testSomebodyOutsideTheGroupIsToldThereIsNoSuchTeam(): void {
		$this->accountService->method('getActor')->willReturn($this->person(self::TEAM));
		$this->teamsRequest->method('groupOf')->willReturn('press');
		$this->groupManager->method('isInGroup')->willReturn(false);

		$this->expectException(ItemNotFoundException::class);

		$this->service->assertMayPostAs('bob', 'press');
	}

	public function testAnOrdinaryAccountIsNotATeamToPostAs(): void {
		$this->accountService->method('getActor')->willReturn($this->person(self::ALICE));
		$this->teamsRequest->method('groupOf')
			->willThrowException(new ItemNotFoundException('not a team account'));

		$this->expectException(ItemNotFoundException::class);

		$this->service->assertMayPostAs('alice', 'alice');
	}

	public function testAnEmptyHandleIsNothingToPostAs(): void {
		$this->accountService->expects($this->never())->method('getActor');
		$this->expectException(ItemNotFoundException::class);

		$this->service->assertMayPostAs('alice', '  ');
	}

	/** The composer asks this on every page; it is one query, not one per group. */
	public function testTheTeamsSomebodyMayPostAsComeFromTheirGroups(): void {
		$this->groupManager->method('getUserGroupIds')->willReturn(['press', 'staff']);
		$this->teamsRequest->expects($this->once())->method('getByGroups')
			->with(['press', 'staff'])
			->willReturn([['actor_id' => self::TEAM, 'group_id' => 'press']]);
		$this->accountService->method('getFromId')->willReturn($this->person(self::TEAM));

		$teams = $this->service->forUser('alice');

		$this->assertCount(1, $teams);
		$this->assertSame(self::TEAM, $teams[0]->getId());
	}

	/** A team whose account has gone is not one to offer. */
	public function testATeamWhoseAccountIsMissingIsLeftOut(): void {
		$this->groupManager->method('getUserGroupIds')->willReturn(['press']);
		$this->teamsRequest->method('getByGroups')
			->willReturn([['actor_id' => self::TEAM, 'group_id' => 'press']]);
		$this->accountService->method('getFromId')
			->willThrowException(new \RuntimeException('gone'));

		$this->assertSame([], $this->service->forUser('alice'));
	}

	public function testSomebodyWhoIsNotAUserHasNoTeams(): void {
		$this->userManager = $this->createStub(IUserManager::class);
		$this->userManager->method('get')->willReturn(null);
		$service = new TeamService(
			$this->teamsRequest, $this->accountService,
			$this->groupManager, $this->userManager, new NullLogger()
		);

		$this->teamsRequest->expects($this->never())->method('getByGroups');

		$this->assertSame([], $service->forUser('ghost'));
	}

	/**
	 * The post is already out by the time this runs; losing the trail is bad,
	 * but it is not a reason to fail a post that has been federated.
	 */
	public function testAFailureToRecordTheAuthorDoesNotRaise(): void {
		$this->teamsRequest->method('recordAuthor')
			->willThrowException(new \RuntimeException('the database is down'));

		$this->service->recordAuthor(self::TEAM . '/1', $this->person(self::ALICE));
		$this->addToAssertionCount(1);
	}

	/**
	 * A reader who is neither in the team nor a moderator gets an empty answer
	 * rather than a filtered one, so a caller cannot forget to check.
	 */
	public function testNobodyIsToldWhoWroteATeamPostUnlessTheyMayKnow(): void {
		$this->teamsRequest->expects($this->never())->method('authorsOf');

		$this->assertSame([], $this->service->authorsFor(['a'], null, false));
	}

	public function testAModeratorIsTold(): void {
		$this->teamsRequest->expects($this->once())->method('authorsOf')
			->willReturn([md5('a') => self::ALICE]);

		$this->assertSame(
			[md5('a') => self::ALICE],
			$this->service->authorsFor(['a'], null, true)
		);
	}

	/**
	 * Found on devel: the first post from a team came back "Stream not found".
	 *
	 * `cacheLocalActorByUsername()` gave up before caching when there was no
	 * Nextcloud user to read a display name off — which for a team account is
	 * always — so the actor was never in the actor cache and every read of it
	 * 404d. The name of a team is its group's, which is the only name it has.
	 */
	public function testATeamAccountIsCachedLikeAnyOther(): void {
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->teamsRequest->method('getByGroups')->willReturn([]);
		$this->accountService->method('getActor')->willReturn($this->person(self::TEAM));

		// the caching is AccountService's, and createActor() is what calls it:
		// what is asserted here is that a team goes through the same call as
		// anybody else rather than a path of its own
		$this->accountService->expects($this->once())->method('createActor')
			->with('team/press', 'press');

		$this->service->create('press', 'press');
	}
}
