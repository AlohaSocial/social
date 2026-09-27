<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ConversationService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\ReactionSummaryService;
use OCA\Social\Service\StreamService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who a conversation is looked up as.
 *
 * `ConversationServiceTest` replaces `StreamService` with a double, so it
 * cannot see this: every lookup there answers whoever asks. Here the real
 * service is used over a `StreamRequest` that behaves the way the database
 * does — a direct message is addressed to nobody but its recipients, so a read
 * with no viewer set is the public-only read and finds nothing.
 *
 * That is the whole of the bug: `getPage()` said who was reading and the two
 * write routes did not, so `POST /api/v1/conversations/{id}/read` and
 * `DELETE /api/v1/conversations/{id}` answered 404 for every real
 * conversation.
 */
#[AllowMockObjectsWithoutExpectations]
class ConversationViewerTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';
	private const BOB = 'https://remote.example/users/bob';
	private const ROOT = 'https://remote.example/notes/1';

	private StreamRequest|Stub $streamRequest;
	private ConversationsRequest|MockObject $conversationsRequest;
	private ConversationService $service;

	/** Who the request has been told is reading, as the real one records it. */
	private ?Person $readerOfRequest = null;

	protected function setUp(): void {
		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->streamRequest->method('setViewer')
			->willReturnCallback(function (Person $viewer): void {
				$this->readerOfRequest = $viewer;
			});
		// what the database does: a direct message names no public collection,
		// so the public-only read a viewerless query makes cannot match it
		$this->streamRequest->method('getStreamByNid')
			->willReturnCallback(function (int $nid): Stream {
				if ($this->readerOfRequest === null) {
					throw new StreamNotFoundException('Stream not found');
				}

				return $this->directMessage($nid);
			});

		$this->conversationsRequest = $this->createMock(ConversationsRequest::class);
		$this->conversationsRequest->method('getThreadLinks')->willReturn([
			self::ROOT => ['id' => self::ROOT, 'idPrim' => md5(self::ROOT), 'nid' => 11, 'inReplyTo' => ''],
		]);
		$this->conversationsRequest->method('getThreadFor')->willReturn([
			self::ROOT => ['id' => self::ROOT, 'idPrim' => md5(self::ROOT), 'nid' => 11, 'inReplyTo' => ''],
		]);

		$this->service = $this->serviceOver($this->conversationsRequest);
	}

	/** The real service, over the real StreamService, over the mocked request. */
	private function serviceOver(ConversationsRequest $request): ConversationService {
		return new ConversationService(
			new StreamService(
				$this->createStub(IURLGenerator::class),
				$this->streamRequest,
				$this->createStub(ActivityService::class),
				$this->createStub(CacheActorService::class),
				$this->createStub(ConfigService::class),
				$this->createStub(CurlService::class),
				$this->createStub(LinkPreviewService::class),
				$this->createStub(EmojiService::class),
				$this->createStub(\OCP\EventDispatcher\IEventDispatcher::class),
				new NullLogger(),
				$this->createStub(PlaceService::class),
				$this->createStub(ReactionSummaryService::class),
				$this->createStub(MediaTagsRequest::class),
				$this->createStub(AccountService::class),
			),
			$this->createStub(CacheActorService::class),
			$request,
		);
	}

	private function viewer(): Person {
		$viewer = new Person();
		$viewer->setId(self::VIEWER);
		$viewer->setPreferredUsername('alice');

		return $viewer;
	}

	private function directMessage(int $nid): Note {
		$note = new Note();
		$note->setId(self::ROOT);
		$note->setNid($nid);
		$note->setAttributedTo(self::BOB);
		$note->setToArray([self::VIEWER]);
		$note->setVisibility(Stream::TYPE_DIRECT);

		return $note;
	}

	public function testMarkingAConversationReadFindsIt(): void {
		$this->conversationsRequest->expects($this->once())
			->method('markRead')
			->with(self::VIEWER, self::ROOT, 11);

		$conversation = $this->service->markRead($this->viewer(), 11);

		$this->assertSame(11, $conversation->getId());
		$this->assertFalse($conversation->isUnread());
		$this->assertSame($this->viewer()->getId(), $this->readerOfRequest?->getId());
	}

	public function testDismissingAConversationFindsIt(): void {
		$this->conversationsRequest->expects($this->once())
			->method('markHidden')
			->with(self::VIEWER, self::ROOT, 11);

		$this->service->remove($this->viewer(), 11);

		$this->assertSame($this->viewer()->getId(), $this->readerOfRequest?->getId());
	}

	/** A thread the viewer has no part in is still refused, viewer or no viewer. */
	public function testAConversationTheViewerHasNoPartInIsNotFound(): void {
		$empty = $this->createStub(ConversationsRequest::class);
		$empty->method('getThreadLinks')->willReturn([]);
		$empty->method('getThreadFor')->willReturn([]);

		$this->expectException(ItemNotFoundException::class);

		$this->serviceOver($empty)->markRead($this->viewer(), 11);
	}
}
