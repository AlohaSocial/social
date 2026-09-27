<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamViewsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ViewCountService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who is counted, who is told, and what must never happen to a read.
 */
#[AllowMockObjectsWithoutExpectations]
class ViewCountServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';
	private const BOB = 'https://cloud.example/@bob';
	private const POST = 'https://cloud.example/@alice/notes/7';

	private StreamViewsRequest|MockObject $streamViewsRequest;
	private ViewCountService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->streamViewsRequest = $this->createMock(StreamViewsRequest::class);
		$this->service = new ViewCountService(
			$this->streamViewsRequest,
			$this->createStub(\OCA\Social\Db\StreamRequest::class),
			$this->createStub(\OCA\Social\Service\CacheActorService::class),
			$this->createStub(\OCA\Social\Service\SignatureService::class),
			$this->createStub(\OCA\Social\Service\ActivityService::class),
			new NullLogger(),
		);
	}

	private function person(string $id): Person {
		$actor = new Person();
		$actor->setId($id);

		return $actor;
	}

	private function post(): Note {
		$note = new Note();
		$note->setId(self::POST);
		$note->setAttributedTo(self::ALICE);

		return $note;
	}

	public function testAReaderOpeningAPostIsCounted(): void {
		$this->streamViewsRequest->expects($this->once())->method('seen')
			->with(self::POST, self::BOB);

		$this->service->seen($this->post(), $this->person(self::BOB));
	}

	/** Re-reading your own post is not a reader. */
	public function testTheAuthorLookingAtTheirOwnPostIsNotCounted(): void {
		$this->streamViewsRequest->expects($this->never())->method('seen');

		$this->service->seen($this->post(), $this->person(self::ALICE));
	}

	/**
	 * An anonymous reader cannot be counted without keeping something about
	 * them that this app deliberately does not keep.
	 */
	public function testNobodySignedInIsNotCounted(): void {
		$this->streamViewsRequest->expects($this->never())->method('seen');

		$this->service->seen($this->post(), null);
	}

	/** How many people read a post is the author's business. */
	public function testOnlyTheAuthorIsToldTheNumber(): void {
		$this->streamViewsRequest->method('countFor')->willReturn(42);

		$mine = $this->post();
		$this->service->seen($mine, $this->person(self::ALICE));
		$this->assertSame(42, $mine->getViewCount());

		$theirs = $this->post();
		$this->service->seen($theirs, $this->person(self::BOB));
		$this->assertNull($theirs->getViewCount(), 'null, not zero: it is not theirs to know');
	}

	/**
	 * A profile is twenty posts, and twenty queries to put a number under each
	 * is the kind of thing that is invisible in development and is the whole
	 * page in production.
	 */
	public function testAPageOfOwnPostsIsCountedInOneQuery(): void {
		$mine = $this->post();
		$theirs = (new Note())->setId('https://cloud.example/@bob/notes/1');
		$theirs->setAttributedTo(self::BOB);

		$this->streamViewsRequest->expects($this->once())->method('countForMany')
			->with([self::POST])
			->willReturn([md5(self::POST) => 12]);

		$this->service->attachAll([$mine, $theirs], $this->person(self::ALICE));

		$this->assertSame(12, $mine->getViewCount());
		$this->assertNull($theirs->getViewCount());
	}

	/** Somebody opening a post gets the post, whatever the counter does. */
	public function testAFailingCounterNeverFailsTheRead(): void {
		$this->streamViewsRequest->method('seen')
			->willThrowException(new \RuntimeException('the database is down'));

		$this->service->seen($this->post(), $this->person(self::BOB));
		$this->addToAssertionCount(1);
	}

	// --- the other server's readers, and ours ------------------------------

	/**
	 * PeerTube sends a `View` to the owner of a video for every watch. This is
	 * the one place this app's view count takes a number from anywhere else,
	 * and it does so on the same terms it counts a local one: one row per
	 * (post, person), so it counts people rather than plays.
	 */
	public function testAViewFromAnotherServerIsCountedOnOurOwnPost(): void {
		$service = $this->serviceHolding($this->localPost());
		$this->streamViewsRequest->expects($this->once())->method('seen')
			->with(self::POST, 'https://peertube.example/accounts/carol');

		$service->receiveView(self::POST, 'https://peertube.example/accounts/carol');
	}

	/**
	 * A view of somebody else's video is their server's business; adding it to
	 * a copy we hold would be a count about their readers on our row.
	 */
	public function testAViewOfARemotePostIsNotCountedHere(): void {
		$service = $this->serviceHolding($this->localPost(local: false));
		$this->streamViewsRequest->expects($this->never())->method('seen');

		$service->receiveView(self::POST, 'https://peertube.example/accounts/carol');
	}

	public function testTheAuthorsOwnViewIsNotCountedEitherWay(): void {
		$service = $this->serviceHolding($this->localPost());
		$this->streamViewsRequest->expects($this->never())->method('seen');

		$service->receiveView(self::POST, self::ALICE);
	}

	/**
	 * A receipt goes out the first time and not the second: `seen()` is
	 * idempotent on (post, viewer), so a second play is not a second view —
	 * and sending one per press of play would be a way to inflate somebody
	 * else's counter from here.
	 */
	public function testAReceiptIsSentOnceForARemoteVideo(): void {
		$video = $this->localPost(local: false);
		$video->setType('Video');
		$video->setAttributedTo('https://peertube.example/video-channels/news');

		$owner = $this->person('https://peertube.example/video-channels/news');
		$owner->setInbox('https://peertube.example/inbox');
		$cacheActorService = $this->createStub(\OCA\Social\Service\CacheActorService::class);
		$cacheActorService->method('getFromId')->willReturn($owner);

		$activityService = $this->createMock(\OCA\Social\Service\ActivityService::class);
		$activityService->expects($this->once())->method('request');

		$this->streamViewsRequest->method('seen')->willReturnOnConsecutiveCalls(true, false);

		$service = new ViewCountService(
			$this->streamViewsRequest,
			$this->createStub(\OCA\Social\Db\StreamRequest::class),
			$cacheActorService,
			$this->createStub(\OCA\Social\Service\SignatureService::class),
			$activityService,
			new NullLogger(),
		);

		$service->seen($video, $this->person(self::BOB));
		$service->seen($video, $this->person(self::BOB));
	}

	/** Everything else is read rather than watched, and nothing counts those. */
	public function testNoReceiptIsSentForAPostThatIsNotAVideo(): void {
		$post = $this->localPost(local: false);
		$post->setAttributedTo('https://remote.example/users/carol');

		$activityService = $this->createMock(\OCA\Social\Service\ActivityService::class);
		$activityService->expects($this->never())->method('request');
		$this->streamViewsRequest->method('seen')->willReturn(true);

		$service = new ViewCountService(
			$this->streamViewsRequest,
			$this->createStub(\OCA\Social\Db\StreamRequest::class),
			$this->createStub(\OCA\Social\Service\CacheActorService::class),
			$this->createStub(\OCA\Social\Service\SignatureService::class),
			$activityService,
			new NullLogger(),
		);

		$service->seen($post, $this->person(self::BOB));
	}

	private function localPost(bool $local = true): \OCA\Social\Model\ActivityPub\Object\Note {
		$note = new \OCA\Social\Model\ActivityPub\Object\Note();
		$note->setId(self::POST)->setLocal($local)->setAttributedTo(self::ALICE);

		return $note;
	}

	private function serviceHolding(\OCA\Social\Model\ActivityPub\Stream $post): ViewCountService {
		$streamRequest = $this->createStub(\OCA\Social\Db\StreamRequest::class);
		$streamRequest->method('getStreamById')->willReturn($post);

		return new ViewCountService(
			$this->streamViewsRequest,
			$streamRequest,
			$this->createStub(\OCA\Social\Service\CacheActorService::class),
			$this->createStub(\OCA\Social\Service\SignatureService::class),
			$this->createStub(\OCA\Social\Service\ActivityService::class),
			new NullLogger(),
		);
	}
}
