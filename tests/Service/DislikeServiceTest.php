<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Object\DislikeInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Dislike;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DislikeService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * PeerTube's other counter, in the outgoing direction.
 *
 * Arriving dislikes have been stored and counted since the video work, and
 * nothing here could send one — so a video watched on this instance was liked
 * by its viewers and disliked by nobody, and the count its author cares about
 * was a count of everybody except them.
 */
#[AllowMockObjectsWithoutExpectations]
class DislikeServiceTest extends TestCase {
	private const CLOUD_URL = 'https://cloud.example';
	private const ALICE_ID = 'https://cloud.example/@alice';
	private const BOB_ID = 'https://peertube.example/accounts/bob';
	private const VIDEO_ID = 'https://peertube.example/videos/watch/1';

	private StreamService|Stub $streamService;
	private ActivityService|MockObject $activityService;
	private ActionsRequest|Stub $actionsRequest;
	private CacheActorService|Stub $cacheActorService;
	private StreamActionService|Stub $streamActionService;
	private DislikeInterface|Stub $dislikeInterface;
	private DislikeService $service;

	/** @var array<int, array{string, string, string, bool}> every flag written */
	private array $flags = [];

	protected function setUp(): void {
		parent::setUp();

		$this->dislikeInterface = $this->createStub(DislikeInterface::class);
		$this->bootActivityPub([DislikeInterface::class => $this->dislikeInterface]);

		$this->streamService = $this->createStub(StreamService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->actionsRequest = $this->createStub(ActionsRequest::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());
		$this->streamActionService = $this->createStub(StreamActionService::class);
		$this->streamActionService->method('setActionBool')->willReturnCallback(
			function (string $actorId, string $streamId, string $flag, bool $value): void {
				$this->flags[] = [$actorId, $streamId, $flag, $value];
			}
		);

		$this->service = new DislikeService(
			$this->streamService,
			$this->createStub(SignatureService::class),
			$this->activityService,
			$this->actionsRequest,
			$this->cacheActorService,
			$this->streamActionService,
			$this->createStub(ModerationService::class),
			new NullLogger()
		);
	}

	protected function tearDown(): void {
		AP::set(null);
		parent::tearDown();
	}

	/** @param array<class-string, object> $interfaces */
	private function bootActivityPub(array $interfaces): void {
		$args = [];
		foreach ((new ReflectionClass(AP::class))->getConstructor()->getParameters() as $parameter) {
			$class = $parameter->getType()->getName();
			if (isset($interfaces[$class])) {
				$args[] = $interfaces[$class];
				continue;
			}
			$mock = $this->createStub($class);
			if ($class === ConfigService::class) {
				$mock->method('getCloudUrl')->willReturn(self::CLOUD_URL);
			}
			$args[] = $mock;
		}
		AP::set(new AP(...$args));
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE_ID);
		$alice->setPreferredUsername('alice');
		$alice->setLocal(true);

		return $alice;
	}

	private function bob(): Person {
		$bob = new Person();
		$bob->setId(self::BOB_ID);
		$bob->setInbox(self::BOB_ID . '/inbox');

		return $bob;
	}

	private function video(): Note {
		$note = new Note();
		$note->setId(self::VIDEO_ID);
		$note->setAttributedTo(self::BOB_ID);
		$note->setVideoMeta(['duration' => 91.0]);

		return $note;
	}

	private function writtenPost(): Note {
		$note = new Note();
		$note->setId('https://remote.example/notes/1');
		$note->setAttributedTo(self::BOB_ID);

		return $note;
	}

	public function testADislikeIsAddressedToTheAuthorsInbox(): void {
		$this->streamService->method('getStreamById')->willReturn($this->video());
		$sent = null;
		$this->activityService->method('request')->willReturnCallback(
			function (ACore $item) use (&$sent): string {
				$sent = $item;

				return 'token';
			}
		);

		$dislike = $this->service->create($this->alice(), self::VIDEO_ID);

		$this->assertInstanceOf(Dislike::class, $dislike);
		$this->assertSame(self::VIDEO_ID, $dislike->getObjectId());
		$this->assertSame($dislike, $sent);
		$paths = $dislike->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::BOB_ID . '/inbox', $paths[0]->getUri());
	}

	public function testTheViewersOwnDislikeIsRecorded(): void {
		$this->streamService->method('getStreamById')->willReturn($this->video());

		$this->service->create($this->alice(), self::VIDEO_ID);

		$this->assertSame(
			[[self::ALICE_ID, self::VIDEO_ID, StreamAction::DISLIKED, true]],
			$this->flags
		);
	}

	/**
	 * Mastodon has never had a dislike and is not getting one from here: a
	 * button under a written post is a product this app is not, and the
	 * activity would be dropped as unknown by the server it was sent to.
	 */
	public function testOnlyAVideoCanBeDisliked(): void {
		$this->streamService->method('getStreamById')->willReturn($this->writtenPost());
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('only a video');
		$this->service->create($this->alice(), 'https://remote.example/notes/1');
	}

	/** A post that carries a video file is a video, however it was made. */
	public function testAPostWithAVideoOnItCounts(): void {
		$note = $this->writtenPost();
		$attachment = new Document();
		$attachment->setMediaType('video/mp4');
		$note->setAttachments([$attachment]);
		$this->streamService->method('getStreamById')->willReturn($note);

		$this->service->create($this->alice(), $note->getId());

		$this->assertNotSame([], $this->flags);
	}

	public function testTakingADislikeBackSendsAnUndoAndClearsTheFlag(): void {
		$this->streamService->method('getStreamById')->willReturn($this->video());
		$stored = new Dislike();
		$stored->setId(self::ALICE_ID . '#dislike/aaaa');
		$this->dislikeInterface->method('getItem')->willReturn($stored);
		$sent = null;
		$this->activityService->method('request')->willReturnCallback(
			function (ACore $item) use (&$sent): string {
				$sent = $item;

				return 'token';
			}
		);

		$undo = $this->service->delete($this->alice(), self::VIDEO_ID);

		$this->assertSame($undo, $sent);
		$this->assertSame(self::ALICE_ID . '#dislike/aaaa/undo', $undo->getId());
		$this->assertSame(
			[[self::ALICE_ID, self::VIDEO_ID, StreamAction::DISLIKED, false]],
			$this->flags
		);
	}

	/**
	 * The Undo has nowhere to go, but the dislike still has to come off this
	 * instance — the author simply keeps theirs.
	 */
	public function testADislikeIsTakenBackLocallyEvenWhenItCannotBeSent(): void {
		$this->streamService->method('getStreamById')->willReturn($this->video());
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('getFromId')->willReturn(new Person());
		$this->service = new DislikeService(
			$this->streamService,
			$this->createStub(SignatureService::class),
			$this->activityService,
			$this->actionsRequest,
			$this->cacheActorService,
			$this->streamActionService,
			$this->createStub(ModerationService::class),
			new NullLogger()
		);

		$this->service->delete($this->alice(), self::VIDEO_ID);

		$this->assertSame(
			[[self::ALICE_ID, self::VIDEO_ID, StreamAction::DISLIKED, false]],
			$this->flags
		);
	}

	public function testAnAuthorWithNoInboxIsRefusedRatherThanQueuedNowhere(): void {
		$this->streamService->method('getStreamById')->willReturn($this->video());
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('getFromId')->willReturn(new Person());
		$this->service = new DislikeService(
			$this->streamService,
			$this->createStub(SignatureService::class),
			$this->activityService,
			$this->actionsRequest,
			$this->cacheActorService,
			$this->streamActionService,
			$this->createStub(ModerationService::class),
			new NullLogger()
		);

		$this->expectException(InvalidResourceException::class);
		$this->service->create($this->alice(), self::VIDEO_ID);
	}

	public function testWhetherThisViewerHasDislikedSomething(): void {
		$this->actionsRequest->method('getAction')->willReturnCallback(
			static function (string $actorId, string $objectId): ACore {
				if ($objectId !== self::VIDEO_ID) {
					throw new ItemNotFoundException();
				}

				return new Dislike();
			}
		);

		$this->assertTrue($this->service->disliked(self::ALICE_ID, self::VIDEO_ID));
		$this->assertFalse($this->service->disliked(self::ALICE_ID, 'https://other.example/1'));
		$this->assertFalse($this->service->disliked('', self::VIDEO_ID));
	}
}
