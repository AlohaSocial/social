<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTime;
use OCA\Social\AP;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\LikeService;
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

#[AllowMockObjectsWithoutExpectations]
class LikeServiceTest extends TestCase {
	private const CLOUD_URL = 'https://cloud.example';
	private const ALICE_ID = 'https://social.example/@alice';
	private const BOB_ID = 'https://remote.example/users/bob';
	private const POST_ID = 'https://remote.example/notes/1';

	private StreamService|MockObject $streamService;
	private SignatureService|MockObject $signatureService;
	private ActivityService|MockObject $activityService;
	private StreamActionService|MockObject $streamActionService;
	private CacheActorService|MockObject $cacheActorService;
	private LikeInterface|MockObject $likeInterface;
	private ModerationService|Stub $moderationService;
	private LikeService $service;

	protected function setUp(): void {
		$this->moderationService = $this->createStub(ModerationService::class);
		$this->likeInterface = $this->createMock(LikeInterface::class);
		$this->bootActivityPub([LikeInterface::class => $this->likeInterface]);

		$this->streamService = $this->createMock(StreamService::class);
		$this->signatureService = $this->createMock(SignatureService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->streamActionService = $this->createMock(StreamActionService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);

		$this->service = new LikeService(
			$this->streamService,
			$this->signatureService,
			$this->activityService,
			$this->streamActionService,
			$this->cacheActorService,
			new NullLogger(),
			$this->moderationService
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	/**
	 * @param array<class-string, object> $interfaces
	 */
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
		$alice->setFollowers(self::ALICE_ID . '/followers');
		$alice->setLocal(true);

		return $alice;
	}

	private function bob(): Person {
		$bob = new Person();
		$bob->setId(self::BOB_ID);
		$bob->setPreferredUsername('bob');
		$bob->setInbox(self::BOB_ID . '/inbox');
		$bob->setSharedInbox('https://remote.example/inbox');

		return $bob;
	}

	private function note(): Note {
		$note = new Note();
		$note->setId(self::POST_ID);
		$note->setAttributedTo(self::BOB_ID);
		$note->setTo(ACore::CONTEXT_PUBLIC);

		return $note;
	}

	private function existingLike(): Like {
		$like = new Like();
		$like->setId(self::ALICE_ID . '#like/abcd1234');
		$like->setActorId(self::ALICE_ID);
		$like->setObjectId(self::POST_ID);

		return $like;
	}

	// create()

	public function testCreateBuildsLikeSavesFlagsAndFederatesIt(): void {
		$alice = $this->alice();
		$bob = $this->bob();
		$this->streamService->expects($this->once())
			->method('getStreamById')
			->with(self::POST_ID, true)
			->willReturn($this->note());
		$this->cacheActorService->method('getFromId')->with(self::BOB_ID)->willReturn($bob);

		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Like::class));
		$saved = null;
		$this->likeInterface->expects($this->once())
			->method('save')
			->with($this->callback(function (ACore $item) use (&$saved): bool {
				$saved = $item;

				return true;
			}));
		$this->streamActionService->expects($this->once())
			->method('setActionBool')
			->with(self::ALICE_ID, self::POST_ID, StreamAction::LIKED, true);
		$this->activityService->expects($this->once())
			->method('request')
			->with($this->isInstanceOf(Like::class))
			->willReturn('token-like');

		$token = '';
		$like = $this->service->create($alice, self::POST_ID, $token);

		$this->assertSame('token-like', $token);
		$this->assertSame($saved, $like);
		$this->assertInstanceOf(Like::class, $like);
		$this->assertStringStartsWith(self::ALICE_ID . '#like/', $like->getId());
		$this->assertSame(8, strlen(substr($like->getId(), strlen(self::ALICE_ID . '#like/'))));
		$this->assertSame($alice, $like->getActor());
		$this->assertSame(self::POST_ID, $like->getObjectId());
		$this->assertSame(self::BOB_ID, $like->getTo());
		$this->assertSame(self::CLOUD_URL, $like->getUrlCloud());
		$this->assertEqualsWithDelta(time(), (new DateTime($like->getPublished()))->getTimestamp(), 5);

		$paths = $like->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::BOB_ID . '/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());
	}

	/**
	 * The author's actor URL was queued as if it were their inbox: the peer
	 * answers 4xx, the row is deleted as permanently rejected, and the like is
	 * lost without a word. There is nowhere to send it, so it fails here.
	 */
	public function testCreateFailsLoudlyWhenTheAuthorsActorCannotBeResolved(): void {
		$this->streamService->method('getStreamById')->willReturn($this->note());
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->likeInterface->expects($this->never())->method('save');
		$this->streamActionService->expects($this->never())->method('setActionBool');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->service->create($this->alice(), self::POST_ID);
	}

	public function testCreateFailsLoudlyWhenTheAuthorPublishesNoInbox(): void {
		$bob = new Person();
		$bob->setId(self::BOB_ID);
		$this->streamService->method('getStreamById')->willReturn($this->note());
		$this->cacheActorService->method('getFromId')->willReturn($bob);
		$this->likeInterface->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidResourceException::class);
		$this->service->create($this->alice(), self::POST_ID);
	}

	/**
	 * The un-like still has to happen here, whatever the author's server
	 * publishes; only the Undo cannot go out.
	 */
	public function testDeleteStillRemovesTheLikeLocallyWhenTheAuthorCannotBeResolved(): void {
		$this->streamService->method('getStreamById')->willReturn($this->note());
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->likeInterface->method('getItem')->willReturn($this->existingLike());
		$this->likeInterface->expects($this->once())->method('delete');
		$this->streamActionService->expects($this->once())
			->method('setActionBool')
			->with(self::ALICE_ID, self::POST_ID, StreamAction::LIKED, false);

		$undo = $this->service->delete($this->alice(), self::POST_ID);

		$this->assertSame([], $undo->getInstancePaths());
	}

	public function testCreateRefusesToLikeSomethingThatIsNotANote(): void {
		$announce = new Announce();
		$announce->setId(self::POST_ID);
		$this->streamService->method('getStreamById')->willReturn($announce);
		$this->likeInterface->expects($this->never())->method('save');
		$this->streamActionService->expects($this->never())->method('setActionBool');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(StreamNotFoundException::class);
		$this->expectExceptionMessage('Stream is not a Note');
		$this->service->create($this->alice(), self::POST_ID);
	}

	public function testCreateFailsForUnknownPost(): void {
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException('Stream not found'));
		$this->likeInterface->expects($this->never())->method('save');
		$this->streamActionService->expects($this->never())->method('setActionBool');

		$this->expectException(StreamNotFoundException::class);
		$this->service->create($this->alice(), 'https://remote.example/notes/missing');
	}

	public function testCreateDoesNotFlagOrFederateWhenTheLikeAlreadyExists(): void {
		$this->streamService->method('getStreamById')->willReturn($this->note());
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());
		$this->likeInterface->method('save')->willThrowException(new ItemAlreadyExistsException());
		$this->streamActionService->expects($this->never())->method('setActionBool');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(ItemAlreadyExistsException::class);
		$this->service->create($this->alice(), self::POST_ID);
	}

	// delete()

	public function testDeleteSendsUndoRemovesLikeAndClearsFlag(): void {
		$alice = $this->alice();
		$existing = $this->existingLike();
		$this->streamService->method('getStreamById')->with(self::POST_ID, true)->willReturn($this->note());
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());

		$this->likeInterface->expects($this->once())
			->method('getItem')
			->with($this->callback(function (ACore $probe): bool {
				return $probe instanceof Like
					&& $probe->getActorId() === self::ALICE_ID
					&& $probe->getObjectId() === self::POST_ID;
			}))
			->willReturn($existing);
		$this->likeInterface->expects($this->once())->method('delete')->with($this->identicalTo($existing));
		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Undo::class));
		$this->activityService->expects($this->once())
			->method('request')
			->with($this->isInstanceOf(Undo::class))
			->willReturn('token-undo');
		$this->streamActionService->expects($this->once())
			->method('setActionBool')
			->with(self::ALICE_ID, self::POST_ID, StreamAction::LIKED, false);

		$token = '';
		$undo = $this->service->delete($alice, self::POST_ID, $token);

		$this->assertSame('token-undo', $token);
		$this->assertInstanceOf(Undo::class, $undo);
		$this->assertSame($existing->getId() . '/undo', $undo->getId());
		$this->assertSame($alice, $undo->getActor());
		$this->assertSame($existing, $undo->getObject());
		$this->assertSame($undo, $existing->getParent());
		$this->assertEqualsWithDelta(time(), (new DateTime($undo->getPublished()))->getTimestamp(), 5);

		$paths = $undo->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::BOB_ID . '/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());
	}

	public function testDeleteStillClearsFlagWhenNoLikeIsStored(): void {
		$this->streamService->method('getStreamById')->willReturn($this->note());
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());
		$this->likeInterface->method('getItem')->willThrowException(new ItemNotFoundException());
		$this->likeInterface->expects($this->never())->method('delete');
		$this->signatureService->expects($this->never())->method('signObject');
		$this->activityService->expects($this->never())->method('request');
		$this->streamActionService->expects($this->once())
			->method('setActionBool')
			->with(self::ALICE_ID, self::POST_ID, StreamAction::LIKED, false);

		$token = 'untouched';
		$undo = $this->service->delete($this->alice(), self::POST_ID, $token);

		$this->assertSame('untouched', $token);
		$this->assertInstanceOf(Undo::class, $undo);
		$this->assertFalse($undo->hasObject());
	}

	public function testDeleteRefusesSomethingThatIsNotANote(): void {
		$announce = new Announce();
		$announce->setId(self::POST_ID);
		$this->streamService->method('getStreamById')->willReturn($announce);
		$this->streamActionService->expects($this->never())->method('setActionBool');

		$this->expectException(StreamNotFoundException::class);
		$this->service->delete($this->alice(), self::POST_ID);
	}

	public function testDeleteFailsForUnknownPost(): void {
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->likeInterface->expects($this->never())->method('getItem');
		$this->streamActionService->expects($this->never())->method('setActionBool');

		$this->expectException(StreamNotFoundException::class);
		$this->service->delete($this->alice(), 'https://remote.example/notes/missing');
	}

	/** A suspended account may not act; see the boost path for the reasoning. */
	public function testASuspendedAccountCannotLike(): void {
		$this->moderationService->method('assertNotSuspended')
			->willThrowException(new InvalidActionException('account is suspended'));

		$this->expectException(InvalidActionException::class);
		$this->service->create($this->alice(), self::POST_ID);
	}
}
