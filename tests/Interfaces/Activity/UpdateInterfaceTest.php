<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Interfaces\Activity\UpdateInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\Activity\Update;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

require_once __DIR__ . '/DispatchingActivityTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class UpdateInterfaceTest extends DispatchingActivityTestCase {
	protected function createHandler(): IActivityPubInterface {
		return new UpdateInterface();
	}

	protected function activityType(): string {
		return Update::TYPE;
	}

	public function testUpdateOfAnActorGoesToThePersonInterface(): void {
		$bob = $this->person(self::REMOTE_URL . '/users/bob');
		$update = $this->incoming(Update::TYPE, self::REMOTE_URL . '/users/bob#updates/1', $bob->getId(), $bob);

		$this->personInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($update), $this->identicalTo($bob));
		$this->noteInterface->expects($this->never())->method('activity');

		$this->createHandler()->processIncomingRequest($update);
	}

	public function testUpdateOfANoteGoesToTheNoteInterface(): void {
		$note = $this->note(self::REMOTE_URL . '/notes/1', self::REMOTE_URL . '/users/bob');
		$update = $this->incoming(Update::TYPE, self::REMOTE_URL . '/notes/1#updates/1', self::REMOTE_URL . '/users/bob', $note);

		$this->noteInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($update), $this->identicalTo($note));
		$this->personInterface->expects($this->never())->method('activity');

		$this->createHandler()->processIncomingRequest($update);
	}
}
