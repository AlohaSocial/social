<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Listeners\GroupListListener;
use OCA\Social\Service\GroupListService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupChangedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroup;
use OCP\IUser;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class GroupListListenerTest extends TestCase {
	private GroupListService|MockObject $service;
	private LoggerInterface|MockObject $logger;
	private GroupListListener $listener;
	private IGroup|Stub $group;
	private IUser|Stub $user;

	protected function setUp(): void {
		$this->service = $this->createMock(GroupListService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new GroupListListener($this->service, $this->logger);
		$this->group = $this->createStub(IGroup::class);
		$this->user = $this->createStub(IUser::class);
	}

	public function testIsAnEventListener(): void {
		$this->assertInstanceOf(IEventListener::class, $this->listener);
	}

	public function testAJoinIsPassedOn(): void {
		$this->service->expects($this->once())->method('onUserAdded')->with($this->group, $this->user);

		$this->listener->handle(new UserAddedEvent($this->group, $this->user));
	}

	public function testALeaveIsPassedOn(): void {
		$this->service->expects($this->once())->method('onUserRemoved')->with($this->group, $this->user);

		$this->listener->handle(new UserRemovedEvent($this->group, $this->user));
	}

	public function testADeletionIsPassedOn(): void {
		$this->service->expects($this->once())->method('onGroupDeleted')->with($this->group);

		$this->listener->handle(new GroupDeletedEvent($this->group));
	}

	public function testOnlyARenameAmongTheChangesMatters(): void {
		$this->service->expects($this->once())->method('onGroupRenamed')->with($this->group);

		$this->listener->handle(new GroupChangedEvent($this->group, 'displayName', 'Design', 'Old'));
		$this->listener->handle(new GroupChangedEvent($this->group, 'somethingElse', 'x', 'y'));
	}

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->service->expects($this->never())->method($this->anything());

		$this->listener->handle(new Event());
	}

	/**
	 * An administrator adding somebody to a group is not doing anything to
	 * this app, and must not be told it went wrong because this app was.
	 */
	public function testAFailureIsLoggedAndNotThrown(): void {
		$this->service->method('onUserAdded')->willThrowException(new \RuntimeException('db gone'));
		$this->logger->expects($this->once())->method('warning');

		$this->listener->handle(new UserAddedEvent($this->group, $this->user));
		$this->addToAssertionCount(1);
	}
}
