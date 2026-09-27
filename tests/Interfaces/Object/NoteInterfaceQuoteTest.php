<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\ForwardService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PushService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

require_once __DIR__ . '/../ActivityPubTestCase.php';

/**
 * An incoming quote names a post this instance may never have seen. It is
 * fetched the way a reply's parent is — through the note's cache and the stream
 * queue — so the inbox request is not held up by a stranger's server.
 */
#[AllowMockObjectsWithoutExpectations]
class NoteInterfaceQuoteTest extends ActivityPubTestCase {
	private const NOTE = self::REMOTE_URL . '/notes/1';
	private const QUOTED = 'https://other.example/notes/quoted';

	private StreamRequest|MockObject $streamRequest;
	private StreamQueueService|MockObject $streamQueueService;
	private NoteInterface $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamQueueService = $this->createMock(StreamQueueService::class);
		$this->handler = new NoteInterface(
			$this->streamRequest,
			$this->createStub(CacheActorsRequest::class),
			$this->createStub(PollService::class),
			$this->createStub(PushService::class),
			$this->streamQueueService,
			$this->createStub(LinkPreviewService::class),
			$this->createStub(ForwardService::class),
			$this->createStub(\OCA\Social\Service\NotificationService::class),
			$this->createStub(\OCA\Social\Service\StatusRevisionService::class)
		);
	}

	private function quotingNote(): Note {
		$note = $this->note(self::NOTE, self::REMOTE_URL . '/users/bob');
		$note->setQuote(self::QUOTED);

		return $note;
	}

	public function testAQuoteOfAPostWeDoNotHoldIsQueuedForFetching(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$note = $this->quotingNote();

		$this->streamRequest->expects($this->once())->method('save')
			->with($this->callback(
				static fn (Note $saved): bool => $saved->hasCache() && $saved->getCache()->hasItem(self::QUOTED)
			));
		// the same queue a reply's unknown parent uses, not a second one
		$this->streamQueueService->expects($this->once())->method('generateStreamQueue')
			->with($note->getRequestToken(), StreamQueue::TYPE_CACHE, self::NOTE);

		$this->handler->save($note);
	}

	public function testAQuoteOfAPostWeAlreadyHoldIsNotQueued(): void {
		$quoted = $this->note(self::QUOTED, 'https://other.example/users/carol');
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($quoted): Stream {
				if ($id === self::QUOTED) {
					return $quoted;
				}

				throw new StreamNotFoundException();
			});

		$this->streamQueueService->expects($this->never())->method('generateStreamQueue');

		$note = $this->quotingNote();
		$this->handler->save($note);

		$this->assertFalse($note->hasCache());
	}

	/** A note that quotes nothing brings nothing to fetch. */
	public function testANoteWithoutAQuoteIsNotQueued(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$this->streamQueueService->expects($this->never())->method('generateStreamQueue');

		$note = $this->note(self::NOTE, self::REMOTE_URL . '/users/bob');
		$this->handler->save($note);

		$this->assertFalse($note->hasCache());
	}

	/** A quote of a reply to a quote is still one queue entry and one climb. */
	public function testAnUnknownParentAndAnUnknownQuoteShareOneQueueEntry(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$note = $this->quotingNote();
		$note->setInReplyTo(self::REMOTE_URL . '/notes/parent');

		$this->streamRequest->expects($this->once())->method('save')
			->with($this->callback(static function (Note $saved): bool {
				return $saved->getCache()->hasItem(self::QUOTED)
					&& $saved->getCache()->hasItem(self::REMOTE_URL . '/notes/parent');
			}));
		$this->streamQueueService->expects($this->once())->method('generateStreamQueue');

		$this->handler->save($note);
	}

	/** The chain of fetches a quote can start is bounded like the reply climb. */
	public function testTheQuoteClimbStopsAtTheDepthCap(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$note = $this->quotingNote();
		$note->setDetailInt(Details::ANCESTOR_DEPTH, StreamQueueService::MAX_ANCESTOR_DEPTH);

		$this->streamRequest->expects($this->once())->method('save');
		$this->streamQueueService->expects($this->never())->method('generateStreamQueue');

		$this->handler->save($note);

		$this->assertFalse($note->hasCache());
	}
}
