<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\WatchRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\WatchService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Where somebody stopped watching.
 *
 * A two-hour talk watched in three sittings is three sittings of finding the
 * place again, which is what this is for. What is held still is that a video
 * watched to the end is not a place to come back to, and that nothing here can
 * fail a request — a bookmark that was not written is a video that starts at
 * the beginning.
 */
#[AllowMockObjectsWithoutExpectations]
class WatchServiceTest extends TestCase {
	private const POST = 'https://cloud.example/@alice/1';
	private const BOB = 'https://cloud.example/@bob';

	private WatchRequest|MockObject $watchRequest;
	private WatchService $service;

	protected function setUp(): void {
		$this->watchRequest = $this->createMock(WatchRequest::class);
		$this->service = new WatchService(
			$this->watchRequest,
			$this->createStub(StreamRequest::class),
			new NullLogger(),
		);
	}

	private function post(): Note {
		$note = new Note();
		$note->setId(self::POST);

		return $note;
	}

	private function bob(): Person {
		$bob = new Person();
		$bob->setId(self::BOB);

		return $bob;
	}

	public function testWhereSomebodyGotToIsRemembered(): void {
		$this->watchRequest->expects($this->once())->method('remember')
			->with(self::POST, self::BOB, 300, 3600);

		$this->service->remember($this->post(), $this->bob(), 300, 3600);
	}

	/**
	 * A "continue watching" row that offers back a video somebody watched to
	 * the end is a row nobody presses twice, so the bookmark is dropped rather
	 * than left pointing at the credits.
	 */
	public function testAVideoWatchedToTheEndIsForgotten(): void {
		$this->watchRequest->expects($this->once())->method('forget')->with(self::POST, self::BOB);
		$this->watchRequest->expects($this->never())->method('remember');

		$this->service->remember($this->post(), $this->bob(), 3500, 3600);
	}

	/** A player that reports past the end is reporting the end. */
	public function testAPositionPastTheEndIsTheEnd(): void {
		$this->watchRequest->expects($this->once())->method('forget');

		$this->service->remember($this->post(), $this->bob(), 99999, 3600);
	}

	/** Nothing to measure against: the position is taken at its word. */
	public function testAVideoOfUnknownLengthIsAlwaysRemembered(): void {
		$this->watchRequest->expects($this->once())->method('remember')
			->with(self::POST, self::BOB, 300, 0);

		$this->service->remember($this->post(), $this->bob(), 300, 0);
	}

	public function testNobodySignedInIsNotRemembered(): void {
		$this->watchRequest->expects($this->never())->method('remember');

		$this->service->remember($this->post(), null, 300, 3600);
	}

	/** A bookmark that was not written is a video that starts at the beginning. */
	public function testAFailingBookmarkNeverFailsTheRequest(): void {
		$this->watchRequest->method('remember')->willThrowException(new \RuntimeException('nope'));

		$this->service->remember($this->post(), $this->bob(), 300, 3600);

		$this->addToAssertionCount(1);
	}

	public function testWhereSomebodyGotToComesBack(): void {
		$this->watchRequest->method('positionOf')->with(self::POST, self::BOB)->willReturn(300);

		$this->assertSame(300, $this->service->positionOf($this->post(), $this->bob()));
	}

	public function testAVideoNobodyOpenedStartsAtTheBeginning(): void {
		$this->watchRequest->method('positionOf')->willReturn(0);

		$this->assertSame(0, $this->service->positionOf($this->post(), $this->bob()));
		$this->assertSame(0, $this->service->positionOf($this->post(), null));
	}
}
