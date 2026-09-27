<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ArchiveService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Putting a post away, and the two things that must stay true of it: nothing
 * is federated, and nothing anybody else can reach is changed.
 */
#[AllowMockObjectsWithoutExpectations]
class ArchiveServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';

	private StreamRequest|MockObject $streamRequest;
	private ArchiveService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->service = new ArchiveService($this->streamRequest);
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setId(self::ALICE);

		return $actor;
	}

	public function testArchivingIsOneWriteAndNoDelivery(): void {
		// the whole of it: a column on the row. Nothing is queued, because an
		// archived post is still on every server that received it
		$this->streamRequest->expects($this->once())->method('setArchived')
			->with(7, self::ALICE, true)->willReturn(true);

		$this->service->archive($this->alice(), 7);
	}

	public function testRestoringIsTheSameWriteTheOtherWay(): void {
		$this->streamRequest->expects($this->once())->method('setArchived')
			->with(7, self::ALICE, false)->willReturn(true);

		$this->service->restore($this->alice(), 7);
	}

	/**
	 * The author is a predicate of the statement, so a request naming
	 * somebody else's post changes no row — and a write that changed nothing
	 * is the same answer as a post that does not exist.
	 */
	public function testSomebodyElsesPostIsTheSameAsOneThatDoesNotExist(): void {
		$this->streamRequest->method('setArchived')->willReturn(false);

		$this->expectException(ItemNotFoundException::class);
		$this->service->archive($this->alice(), 7);
	}

	public function testTheArchiveIsTheAccountsOwnAndIsPaged(): void {
		$note = new Note();
		$note->setId('https://cloud.example/notes/1');
		$this->streamRequest->expects($this->once())->method('getArchivedByActor')
			->with(self::ALICE, 20, 99)->willReturn([$note]);

		$this->assertSame([$note], $this->service->forActor($this->alice(), 20, 99));
	}

	/** A page bigger than the ceiling is the ceiling, not a way to read everything at once. */
	public function testAPageIsBounded(): void {
		$this->streamRequest->expects($this->once())->method('getArchivedByActor')
			->with(self::ALICE, ArchiveService::PAGE, 0)->willReturn([]);

		$this->service->forActor($this->alice(), 5000);
	}
}
