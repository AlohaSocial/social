<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Model\Cache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class StreamQueueServiceTest extends TestCase {
	private const STREAM_ID = 'https://cloud.example.com/apps/social/@alice/1';
	private const REPLY_URL = 'https://remote.example/notes/99';
	private const PARENT_URL = 'https://remote.example/notes/parent';
	private const BOB = 'https://remote.example/users/bob';

	private StreamRequest|MockObject $streamRequest;
	private StreamQueueRequest|MockObject $streamQueueRequest;
	private CacheActorService|MockObject $cacheActorService;
	private CurlService|MockObject $curlService;
	private MiscService|MockObject $miscService;
	private LinkPreviewService|MockObject $linkPreviewService;
	private AP|MockObject $ap;
	private ImportService|MockObject $importService;
	private StreamQueueService $service;

	protected function setUp(): void {
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamQueueRequest = $this->createMock(StreamQueueRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->miscService = $this->createMock(MiscService::class);
		$this->linkPreviewService = $this->createMock(LinkPreviewService::class);
		$this->ap = $this->createMock(AP::class);
		$this->importService = $this->createMock(ImportService::class);
		AP::set($this->ap);

		$this->service = new StreamQueueService(
			$this->streamRequest,
			$this->streamQueueRequest,
			$this->cacheActorService,
			$this->importService,
			$this->curlService,
			$this->miscService,
			$this->linkPreviewService,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	private function queue(string $type = StreamQueue::TYPE_CACHE): StreamQueue {
		return new StreamQueue('tok', $type, self::STREAM_ID);
	}

	/** A local boost whose cache is waiting for the remote object it repeats. */
	private function streamWithCache(): Announce {
		$announce = new Announce();
		$announce->setId(self::STREAM_ID);
		$announce->addCacheItem(self::REPLY_URL);

		return $announce;
	}

	/** A remote reply whose parent this instance never received. */
	private function replyWithCache(int $depth = 0): Note {
		$reply = new Note();
		$reply->setId(self::STREAM_ID);
		$reply->setInReplyTo(self::PARENT_URL);
		$reply->addCacheItem(self::PARENT_URL);
		if ($depth > 0) {
			$reply->setDetailInt(Details::ANCESTOR_DEPTH, $depth);
		}

		return $reply;
	}

	public function testGenerateStreamQueueCreatesAStandbyEntry(): void {
		$this->streamQueueRequest->expects($this->once())
			->method('create')
			->with($this->callback(function (StreamQueue $queue) {
				$this->assertSame('tok', $queue->getToken());
				$this->assertSame(StreamQueue::TYPE_CACHE, $queue->getType());
				$this->assertSame(self::STREAM_ID, $queue->getStreamId());
				$this->assertSame(StreamQueue::STATUS_STANDBY, $queue->getStatus());

				return true;
			}));

		$this->service->generateStreamQueue('tok', StreamQueue::TYPE_CACHE, self::STREAM_ID);
	}

	public function testGetRequestStandbyHandsOnWhatTheQueryFoundDue(): void {
		// the backoff is the query's (Backoff::inbound()); filtering again here
		// could only disagree with it
		$now = time();
		$fresh = $this->queue()->setTries(0)->setLast($now - 1);
		$old = $this->queue()->setTries(3)->setLast($now - 60);
		$this->streamQueueRequest->method('getStandby')->willReturn([$fresh, $old]);

		$total = 0;
		$ready = $this->service->getRequestStandby($total);

		$this->assertSame(2, $total);
		$this->assertSame([$fresh, $old], $ready);
	}

	public function testManageStreamQueueStopsWhenTheEntryIsAlreadyRunning(): void {
		$this->streamQueueRequest->method('setAsRunning')->willThrowException(new QueueStatusException());
		$this->streamRequest->expects($this->never())->method('getStreamById');
		$this->streamQueueRequest->expects($this->never())->method('delete');

		$this->service->manageStreamQueue($this->queue());
	}

	/**
	 * The row is marked `running` before the work starts and only the handlers
	 * take it out of that state: an exception they do not name — an
	 * ItemAlreadyExistsException from a parent that arrived meanwhile, a
	 * database error, an unconfigured app — stranded it there for ever, since
	 * nothing but the reaper ever looks at a running row again.
	 */
	public function testAnItemThatFailsUnexpectedlyGoesBackToStandbyInsteadOfStayingRunning(): void {
		$queue = $this->queue();
		$this->streamRequest->method('getStreamById')
			->willThrowException(new ItemAlreadyExistsException('the parent arrived meanwhile'));
		$this->streamQueueRequest->expects($this->once())
			->method('setAsFailure')->with($this->identicalTo($queue));
		$this->streamQueueRequest->expects($this->never())->method('setAsSuccess');

		$this->service->manageStreamQueue($queue);
	}

	/** One bad item costs that item: it is ended, and nothing is rethrown. */
	public function testAnItemThatFailsUnexpectedlyDoesNotStopTheCaller(): void {
		$this->streamRequest->method('getStreamById')
			->willThrowException(new \RuntimeException('the database is gone'));

		$this->service->manageStreamQueue($this->queue());
		$this->addToAssertionCount(1);
	}

	public function testReapStaleRunningReturnsStrandedRunningItemsToStandby(): void {
		$cutoff = null;
		$this->streamQueueRequest->expects($this->once())
			->method('resetStaleRunning')
			->willReturnCallback(function (int $before) use (&$cutoff): int {
				$cutoff = $before;

				return 3;
			});

		$this->assertSame(3, $this->service->reapStaleRunning());
		$this->assertEqualsWithDelta(time() - StreamQueueService::STALE_RUNNING_SECONDS, $cutoff, 2);
	}

	public function testUnknownQueueTypesAreDropped(): void {
		$queue = $this->queue('Signature');
		$this->streamQueueRequest->expects($this->once())->method('setAsRunning');
		$this->streamQueueRequest->expects($this->once())->method('delete')->with($this->identicalTo($queue));
		$this->streamRequest->expects($this->never())->method('getStreamById');

		$this->service->manageStreamQueue($queue);
	}

	public function testALinkPreviewEntryReadsThePageOnceAndIsDropped(): void {
		$queue = $this->queue(StreamQueue::TYPE_LINK_PREVIEW);
		$note = new Note();
		$note->setId(self::STREAM_ID);
		$this->streamRequest->method('getStreamById')->with(self::STREAM_ID)->willReturn($note);
		$this->linkPreviewService->expects($this->once())->method('generate')->with($this->identicalTo($note));
		// reading the page again on the next run would hit the same wall
		$this->streamQueueRequest->expects($this->once())->method('delete')->with($this->identicalTo($queue));
		$this->streamQueueRequest->expects($this->never())->method('setAsSuccess');

		$this->service->manageStreamQueue($queue);
	}

	public function testALinkPreviewEntryForAMissingStreamIsDropped(): void {
		$queue = $this->queue(StreamQueue::TYPE_LINK_PREVIEW);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->linkPreviewService->expects($this->never())->method('generate');
		$this->streamQueueRequest->expects($this->once())->method('delete')->with($this->identicalTo($queue));

		$this->service->manageStreamQueue($queue);
	}

	public function testCacheEntryForAMissingStreamIsDropped(): void {
		$queue = $this->queue();
		$this->streamRequest->method('getStreamById')->with(self::STREAM_ID)->willThrowException(new StreamNotFoundException());
		$this->streamQueueRequest->expects($this->once())->method('delete')->with($this->identicalTo($queue));

		$this->service->manageStreamQueue($queue);
	}

	public function testCacheEntryForAStreamWithoutCacheIsDropped(): void {
		$queue = $this->queue();
		$note = new Note();
		$note->setId(self::STREAM_ID);
		$this->streamRequest->method('getStreamById')->willReturn($note);
		$this->streamQueueRequest->expects($this->once())->method('delete')->with($this->identicalTo($queue));
		$this->streamQueueRequest->expects($this->never())->method('setAsSuccess');

		$this->service->manageStreamQueue($queue);
	}

	public function testAlreadyKnownReplyIsCachedFromTheDatabase(): void {
		$queue = $this->queue();
		$stream = $this->streamWithCache();
		$reply = new Note();
		$reply->setId(self::REPLY_URL);
		$reply->setContent('a reply');
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(fn (string $id) => $id === self::STREAM_ID ? $stream : $reply);
		$this->curlService->expects($this->never())->method('retrieveObject');

		$updatedCache = null;
		$this->streamRequest->expects($this->once())
			->method('updateCache')
			->willReturnCallback(function (Stream $s, Cache $cache) use (&$updatedCache) {
				$updatedCache = $cache;
			});
		$noteInterface = $this->createMock(NoteInterface::class);
		$noteInterface->expects($this->once())->method('event')->with($this->identicalTo($stream), 'updateCache');
		$this->ap->method('getInterfaceForItem')->with($this->identicalTo($stream))->willReturn($noteInterface);
		$this->streamQueueRequest->expects($this->once())->method('setAsSuccess')->with($this->identicalTo($queue));
		$this->streamQueueRequest->expects($this->never())->method('setAsFailure');

		$this->service->manageStreamQueue($queue);

		$item = $updatedCache->getItem(self::REPLY_URL);
		$this->assertSame(StreamQueue::STATUS_SUCCESS, $item->getStatus());
		$this->assertSame(json_encode($reply, JSON_UNESCAPED_SLASHES), $item->getContent());
	}

	public function testUnknownReplyIsFetchedValidatedAndSaved(): void {
		$queue = $this->queue();
		$stream = $this->streamWithCache();
		$fetched = new Note();
		$fetched->setId(self::REPLY_URL);
		$fetched->setAttributedTo(self::BOB);
		$saved = false;
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($stream, $fetched, &$saved) {
				if ($id === self::STREAM_ID) {
					return $stream;
				}
				if (!$saved) {
					throw new StreamNotFoundException();
				}

				return $fetched;
			});
		$this->curlService->expects($this->once())
			->method('retrieveObject')
			->with(self::REPLY_URL)
			->willReturn(['id' => self::REPLY_URL, 'type' => 'Note']);
		$this->ap->method('getItemFromData')->with(['id' => self::REPLY_URL, 'type' => 'Note'])->willReturn($fetched);
		$this->cacheActorService->expects($this->once())->method('getFromId')->with(self::BOB)->willReturn(new Person());
		$noteInterface = $this->createMock(NoteInterface::class);
		$noteInterface->expects($this->once())
			->method('save')
			->with($this->identicalTo($fetched))
			->willReturnCallback(function () use (&$saved) {
				$saved = true;
			});
		$this->ap->method('getInterfaceForItem')->willReturn($noteInterface);
		$this->streamQueueRequest->expects($this->once())->method('setAsSuccess');

		$this->service->manageStreamQueue($queue);

		$this->assertSame('remote.example', $fetched->getOrigin());
		$this->assertSame(SignatureService::ORIGIN_REQUEST, $fetched->getOriginSource());
		// the object of a boost starts an ancestor climb of its own
		$this->assertSame(1, $fetched->getDetailInt(Details::ANCESTOR_DEPTH));
	}

	/**
	 * The parent of a reply is fetched, checked and saved like any cached object;
	 * it is stamped with how deep the climb is, so the save of the parent queues
	 * *its* parent with the count bumped and NoteInterface can stop at the cap.
	 * The reply itself keeps no copy: the parent is a row of its own now.
	 */
	public function testTheParentOfAReplyIsFetchedStampedAndDroppedFromTheReplysCache(): void {
		$queue = $this->queue();
		$reply = $this->replyWithCache(2);
		$parent = new Note();
		$parent->setId(self::PARENT_URL);
		$parent->setAttributedTo(self::BOB);
		$saved = false;
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($reply, $parent, &$saved) {
				if ($id === self::STREAM_ID) {
					return $reply;
				}
				if (!$saved) {
					throw new StreamNotFoundException();
				}

				return $parent;
			});
		$this->curlService->expects($this->once())
			->method('retrieveObject')
			->with(self::PARENT_URL)
			->willReturn(['id' => self::PARENT_URL, 'type' => 'Note']);
		$this->ap->method('getItemFromData')->willReturn($parent);
		$this->cacheActorService->method('getFromId')->willReturn(new Person());
		$noteInterface = $this->createMock(NoteInterface::class);
		$noteInterface->expects($this->once())
			->method('save')
			->with($this->identicalTo($parent))
			->willReturnCallback(function () use (&$saved) {
				$saved = true;
			});
		$this->ap->method('getInterfaceForItem')->willReturn($noteInterface);
		$updatedCache = null;
		$this->streamRequest->expects($this->once())
			->method('updateCache')
			->willReturnCallback(function (Stream $s, Cache $cache) use (&$updatedCache) {
				$updatedCache = $cache;
			});
		$this->streamQueueRequest->expects($this->once())->method('setAsSuccess')->with($this->identicalTo($queue));

		$this->service->manageStreamQueue($queue);

		$this->assertSame(3, $parent->getDetailInt(Details::ANCESTOR_DEPTH));
		$this->assertFalse($updatedCache->hasItem(self::PARENT_URL));
	}

	/** The replies stored before the parent arrived are counted on it once it does. */
	public function testAFetchedParentLearnsHowManyRepliesItAlreadyHas(): void {
		$reply = $this->replyWithCache();
		$parent = new Note();
		$parent->setId(self::PARENT_URL);
		$parent->setAttributedTo(self::BOB);
		$parent->setDetailInt('remote_replies', 5);
		$saved = false;
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($reply, $parent, &$saved) {
				if ($id === self::STREAM_ID) {
					return $reply;
				}
				if (!$saved) {
					throw new StreamNotFoundException();
				}

				return $parent;
			});
		$this->curlService->method('retrieveObject')->willReturn(['id' => self::PARENT_URL, 'type' => 'Note']);
		$this->ap->method('getItemFromData')->willReturn($parent);
		$this->cacheActorService->method('getFromId')->willReturn(new Person());
		$noteInterface = $this->createStub(NoteInterface::class);
		$noteInterface->method('save')->willReturnCallback(function () use (&$saved) {
			$saved = true;
		});
		$this->ap->method('getInterfaceForItem')->willReturn($noteInterface);
		$this->streamRequest->method('countRepliesTo')->with(self::PARENT_URL)->willReturn(2);

		$this->streamRequest->expects($this->once())->method('updateDetails')->with($this->identicalTo($parent));

		$this->service->manageStreamQueue($this->queue());

		$this->assertSame(7, $parent->getDetailInt('replies'));
	}

	public function testReplyWithMismatchingIdIsRemovedFromTheCache(): void {
		$queue = $this->queue();
		$stream = $this->streamWithCache();
		$fetched = new Note();
		$fetched->setId('https://remote.example/notes/other');
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($stream) {
				if ($id === self::STREAM_ID) {
					return $stream;
				}
				throw new StreamNotFoundException();
			});
		$this->curlService->method('retrieveObject')->willReturn(['id' => 'x']);
		$this->ap->method('getItemFromData')->willReturn($fetched);
		$this->ap->method('getInterfaceForItem')->willReturn($this->createStub(NoteInterface::class));
		$this->miscService->expects($this->once())
			->method('log')
			->with($this->stringContains('InvalidOriginException'), 1);
		$updatedCache = null;
		$this->streamRequest->method('updateCache')
			->willReturnCallback(function (Stream $s, Cache $cache) use (&$updatedCache) {
				$updatedCache = $cache;
			});
		// nothing left to cache: the queue entry is complete
		$this->streamQueueRequest->expects($this->once())->method('setAsSuccess');

		$this->service->manageStreamQueue($queue);

		$this->assertFalse($updatedCache->hasItem(self::REPLY_URL));
	}

	public function testGoneReplyIsRemovedFromTheCache(): void {
		$queue = $this->queue();
		$stream = $this->streamWithCache();
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($stream) {
				if ($id === self::STREAM_ID) {
					return $stream;
				}
				throw new StreamNotFoundException();
			});
		$this->curlService->method('retrieveObject')->willThrowException(new RequestContentException('gone', 410));
		$this->ap->method('getInterfaceForItem')->willReturn($this->createStub(NoteInterface::class));
		$this->miscService->expects($this->once())->method('log')->with($this->stringContains('RequestContentException'), 1);
		$this->streamQueueRequest->expects($this->once())->method('setAsSuccess');

		$this->service->manageStreamQueue($queue);

		$this->assertFalse($stream->getCache()->hasItem(self::REPLY_URL));
	}

	public function testNetworkErrorKeepsTheItemAndMarksTheEntryFailed(): void {
		$queue = $this->queue();
		$stream = $this->streamWithCache();
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($stream) {
				if ($id === self::STREAM_ID) {
					return $stream;
				}
				throw new StreamNotFoundException();
			});
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException('timeout'));
		$this->ap->method('getInterfaceForItem')->willReturn($this->createStub(NoteInterface::class));
		$this->streamRequest->expects($this->once())->method('updateCache');
		$this->streamQueueRequest->expects($this->once())->method('setAsFailure')->with($this->identicalTo($queue));
		$this->streamQueueRequest->expects($this->never())->method('setAsSuccess');

		$this->service->manageStreamQueue($queue);

		$item = $stream->getCache()->getItem(self::REPLY_URL);
		$this->assertSame(1, $item->getError());
		$this->assertNotSame(StreamQueue::STATUS_SUCCESS, $item->getStatus());
	}

	public function testCacheStreamByTokenProcessesEveryEntry(): void {
		$first = $this->queue('Unknown');
		$second = $this->queue('Unknown');
		$this->streamQueueRequest->method('getFromToken')->with('tok')->willReturn([$first, $second]);
		$this->streamQueueRequest->expects($this->exactly(2))->method('setAsRunning');
		$deleted = [];
		$this->streamQueueRequest->expects($this->exactly(2))
			->method('delete')
			->willReturnCallback(function (StreamQueue $item) use (&$deleted): void {
				$deleted[] = $item;
			});

		$this->service->cacheStreamByToken('tok');
		$this->assertSame([$first, $second], $deleted);
	}
	public function testQueueFetchQueuesAnHttpUrl(): void {
		$this->streamQueueRequest->expects($this->once())->method('create')
			->with($this->callback(function (StreamQueue $queue): bool {
				$this->assertSame(StreamQueue::TYPE_FETCH, $queue->getType());
				$this->assertSame(self::REPLY_URL, $queue->getStreamId());

				return true;
			}));

		$this->assertTrue($this->service->queueFetch('tok', self::REPLY_URL));
	}

	public function testQueueFetchRefusesWhatIsNotAnHttpUrlOrDoesNotFit(): void {
		$this->streamQueueRequest->expects($this->never())->method('create');

		$this->assertFalse($this->service->queueFetch('tok', 'file:///etc/passwd'));
		$this->assertFalse($this->service->queueFetch('tok', 'https://remote.example/' . str_repeat('a', 300)));
	}

	private function fetchQueue(): StreamQueue {
		return new StreamQueue('tok', StreamQueue::TYPE_FETCH, self::REPLY_URL);
	}

	private function fetchedNote(string $id = self::REPLY_URL, string $updated = ''): Note {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo(self::BOB);
		$note->setUpdated($updated);
		$this->curlService->expects($this->once())->method('retrieveObject')->with(self::REPLY_URL)
			->willReturn(['id' => $id, 'type' => 'Note']);
		$this->ap->method('getItemFromData')->willReturn($note);

		return $note;
	}

	public function testAFetchedPostIsTakenInAsACreateOfItsAuthorFromItsHost(): void {
		$note = $this->fetchedNote();
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->cacheActorService->expects($this->once())->method('getFromId')->with(self::BOB);
		$this->importService->expects($this->once())->method('parseIncomingRequest')
			->with($this->callback(function (ACore $activity) use ($note): bool {
				$this->assertInstanceOf(Create::class, $activity);
				$this->assertSame(self::BOB, $activity->getActorId());
				$this->assertSame($note, $activity->getObject());
				$this->assertSame('remote.example', $activity->getOrigin());
				$this->assertSame(SignatureService::ORIGIN_REQUEST, $activity->getOriginSource());

				return true;
			}));
		$this->streamQueueRequest->expects($this->once())->method('delete');

		$this->service->manageStreamQueue($this->fetchQueue());
	}

	public function testAFetchedPostThatIsNotTheOneAskedForIsDropped(): void {
		$this->fetchedNote('https://remote.example/notes/other');
		$this->importService->expects($this->never())->method('parseIncomingRequest');
		$this->streamQueueRequest->expects($this->once())->method('delete');
		$this->streamQueueRequest->expects($this->never())->method('setAsFailure');

		$this->service->manageStreamQueue($this->fetchQueue());
	}

	public function testAStoredPostEditedSinceIsTakenInAsAnUpdate(): void {
		$this->fetchedNote(self::REPLY_URL, '2026-09-02T00:00:00Z');
		$stored = new Note();
		$stored->setUpdated('2026-09-01T00:00:00Z');
		$this->streamRequest->method('getStreamById')->willReturn($stored);
		$this->importService->expects($this->once())->method('parseIncomingRequest')
			->with($this->isInstanceOf(Update::class));

		$this->service->manageStreamQueue($this->fetchQueue());
	}

	public function testAStoredPostThatHasNotChangedIsLeftAlone(): void {
		$this->fetchedNote(self::REPLY_URL, '2026-09-01T00:00:00Z');
		$stored = new Note();
		$stored->setUpdated('2026-09-01T00:00:00Z');
		$this->streamRequest->method('getStreamById')->willReturn($stored);
		$this->importService->expects($this->never())->method('parseIncomingRequest');
		$this->streamQueueRequest->expects($this->once())->method('delete');

		$this->service->manageStreamQueue($this->fetchQueue());
	}

	public function testAnUnreachableOriginIsAskedAgainLater(): void {
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException('timeout'));
		$this->streamQueueRequest->expects($this->once())->method('setAsFailure');
		$this->streamQueueRequest->expects($this->never())->method('delete');

		$this->service->manageStreamQueue($this->fetchQueue());
	}
}
