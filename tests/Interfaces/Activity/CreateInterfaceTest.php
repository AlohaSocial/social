<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Interfaces\Activity\CreateInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Service\StreamQueueService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

require_once __DIR__ . '/DispatchingActivityTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class CreateInterfaceTest extends DispatchingActivityTestCase {
	/** @var StreamQueueService&MockObject */
	private $streamQueueService;

	protected function createHandler(): IActivityPubInterface {
		$this->streamQueueService ??= $this->createMock(StreamQueueService::class);

		return new CreateInterface($this->streamQueueService);
	}

	protected function activityType(): string {
		return Create::TYPE;
	}

	public function testCreateOfANoteGoesToTheNoteInterface(): void {
		$note = $this->note(self::REMOTE_URL . '/notes/1', self::REMOTE_URL . '/users/bob');
		$create = $this->incoming(Create::TYPE, self::REMOTE_URL . '/notes/1/activity', self::REMOTE_URL . '/users/bob', $note);

		$this->noteInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($create), $this->identicalTo($note));

		$this->createHandler()->processIncomingRequest($create);
	}
	public function testACreateOfAnObjectByIdFetchesItFromItsOrigin(): void {
		$create = $this->incoming(Create::TYPE, self::REMOTE_URL . '/notes/1/activity', self::REMOTE_URL . '/users/bob');
		$create->setObjectId(self::REMOTE_URL . '/notes/1');
		$create->setRequestToken('tok');
		$handler = $this->createHandler();
		$this->streamQueueService->expects($this->once())->method('queueFetch')
			->with('tok', self::REMOTE_URL . '/notes/1')->willReturn(true);

		$handler->processIncomingRequest($create);
	}

	public function testAnObjectIdOnAnotherHostIsNotFetched(): void {
		$create = $this->incoming(Create::TYPE, self::REMOTE_URL . '/notes/1/activity', self::REMOTE_URL . '/users/bob');
		$create->setObjectId('https://elsewhere.example/notes/1');
		$handler = $this->createHandler();
		$this->streamQueueService->expects($this->never())->method('queueFetch');

		$handler->processIncomingRequest($create);
	}
}
