<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\External;

use OCA\Social\Db\ExternalUsersRequest;
use OCA\Social\External\ExternalGroupBackend;
use OCA\Social\External\ExternalUserBackend;
use OCP\GroupInterface;
use PHPUnit\Framework\TestCase;

class ExternalGroupBackendTest extends TestCase {
	private ExternalGroupBackend $groups;

	protected function setUp(): void {
		$users = $this->createStub(ExternalUserBackend::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'alice');
		$request = $this->createStub(ExternalUsersRequest::class);
		$request->method('search')->willReturn([
			['uid' => 'alice', 'password' => '', 'displayname' => '', 'origin' => '', 'creation' => 0],
		]);
		$request->method('count')->willReturn(1);

		$this->groups = new ExternalGroupBackend($users, $request);
	}

	public function testItHoldsExactlyTheExternalUsers(): void {
		$this->assertTrue($this->groups->inGroup('alice', ExternalGroupBackend::GROUP_ID));
		$this->assertFalse($this->groups->inGroup('bob', ExternalGroupBackend::GROUP_ID));
		$this->assertFalse($this->groups->inGroup('alice', 'admin'));
		$this->assertSame([ExternalGroupBackend::GROUP_ID], $this->groups->getUserGroups('alice'));
		$this->assertSame([], $this->groups->getUserGroups('bob'));
		$this->assertSame(['alice'], $this->groups->usersInGroup(ExternalGroupBackend::GROUP_ID));
		$this->assertSame([], $this->groups->usersInGroup('admin'));
		$this->assertSame(1, $this->groups->countUsersInGroup(ExternalGroupBackend::GROUP_ID));
	}

	public function testItIsOneGroupFoundByNameOrId(): void {
		$this->assertTrue($this->groups->groupExists(ExternalGroupBackend::GROUP_ID));
		$this->assertFalse($this->groups->groupExists('admin'));
		$this->assertSame([ExternalGroupBackend::GROUP_ID], $this->groups->getGroups());
		$this->assertSame([ExternalGroupBackend::GROUP_ID], $this->groups->getGroups('external'));
		$this->assertSame([], $this->groups->getGroups('admin'));
		$this->assertSame([], $this->groups->getGroups('', -1, 1));
	}

	public function testNobodyCanAddOrRemoveMembersAndItIsHiddenFromSharing(): void {
		$this->assertFalse($this->groups->implementsActions(GroupInterface::ADD_TO_GROUP));
		$this->assertFalse($this->groups->implementsActions(GroupInterface::REMOVE_FROM_GOUP));
		$this->assertFalse($this->groups->implementsActions(GroupInterface::CREATE_GROUP));
		$this->assertTrue($this->groups->hideGroup(ExternalGroupBackend::GROUP_ID));
		$this->assertFalse($this->groups->hideGroup('admin'));
	}
}
