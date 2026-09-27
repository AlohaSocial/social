<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

require_once __DIR__ . '/../ActivityPubTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class LikeInterfaceTest extends ActivityPubTestCase {
	private const POST = self::LOCAL_URL . '/notes/1';

	/** @var ActionsRequest&MockObject */
	private $actionsRequest;
	/** @var StreamRequest&MockObject */
	private $streamRequest;
	/** @var CacheActorService&Stub */
	private $cacheActorService;
	private LikeInterface $handler;

	private Person $alice;
	private Person $bob;

	protected function setUp(): void {
		parent::setUp();

		$this->actionsRequest = $this->createMock(ActionsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);

		$this->handler = new LikeInterface($this->actionsRequest, $this->streamRequest, $this->cacheActorService, $this->createStub(\OCA\Social\Service\NotificationService::class));

		$this->alice = $this->person(self::LOCAL_URL . '/users/alice', true);
		$this->bob = $this->person(self::REMOTE_URL . '/users/bob');
		$this->cacheActorService->method('getFromId')->willReturnCallback(function (string $id): Person {
			if ($id === $this->bob->getId()) {
				return $this->bob;
			}

			throw new CacheActorDoesNotExistException();
		});
	}

	/** bob (remote) likes alice's post. */
	private function incomingLike(string $origin = self::REMOTE_HOST): Like {
		$like = new Like();
		$like->setId(self::REMOTE_URL . '/likes/1');
		$like->setActorId($this->bob->getId());
		$like->setObjectId(self::POST);
		$like->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());

		return $like;
	}

	private function post(bool $local = true, int $remoteLikes = 0): Note {
		$post = $this->note(self::POST, $this->alice->getId(), $local);
		$post->setDetailInt('remote_likes', $remoteLikes);

		return $post;
	}

	private function likeNotification(string ...$accounts): SocialAppNotification {
		$notification = new SocialAppNotification();
		$notification->setId(self::POST . '/notification+like');
		foreach ($accounts as $account) {
			$notification->addDetail('accounts', $account);
		}

		return $notification;
	}

	private function noStoredLike(): void {
		$this->actionsRequest->method('getActionFromItem')->willThrowException(new ActionDoesNotExistException());
	}

	private function noNotificationYet(): void {
		$this->streamRequest->method('getStreamByObjectId')->willThrowException(new StreamNotFoundException());
	}

	public function testNewLikeIsStored(): void {
		$this->noStoredLike();
		$this->noNotificationYet();
		$this->streamRequest->method('getStreamById')->willReturn($this->post());
		$like = $this->incomingLike();

		$this->actionsRequest->expects($this->once())->method('save')->with($this->identicalTo($like));

		$this->handler->processIncomingRequest($like);
	}

	public function testLikeAddsToTheLikeCounterOfThePost(): void {
		$this->noStoredLike();
		$this->noNotificationYet();
		$post = $this->post(true, 2);
		$this->streamRequest->method('getStreamById')->willReturn($post);
		$this->actionsRequest->method('countActions')->with(self::POST, Like::TYPE)->willReturn(3);

		$updated = null;
		$this->capture($this->streamRequest, 'updateDetails', $updated);

		$this->handler->processIncomingRequest($this->incomingLike());

		$this->assertSame($post, $updated);
		$this->assertSame(5, $post->getDetailInt('likes'));
	}

	public function testLikeOnALocalPostNotifiesItsAuthor(): void {
		$this->noStoredLike();
		$this->noNotificationYet();
		$this->streamRequest->method('getStreamById')->willReturn($this->post());

		$notification = null;
		$this->capture($this->notificationInterface, 'save', $notification);
		$this->notificationInterface->expects($this->never())->method('update');

		$this->handler->processIncomingRequest($this->incomingLike());

		$this->assertInstanceOf(SocialAppNotification::class, $notification);
		$this->assertSame(Like::TYPE, $notification->getSubType());
		$this->assertSame($this->alice->getId(), $notification->getTo());
		$this->assertSame(self::POST, $notification->getObjectId());
		$this->assertSame(self::POST . '/notification+like', $notification->getId());
		$this->assertSame(['bob@remote.example'], $notification->getDetails('accounts'));
		$this->assertTrue($notification->isLocal());
	}

	public function testFurtherLikesJoinTheExistingNotification(): void {
		$this->noStoredLike();
		$this->streamRequest->method('getStreamById')->willReturn($this->post());
		$existing = $this->likeNotification('carol@other.example');
		$this->streamRequest->method('getStreamByObjectId')
			->with(self::POST, SocialAppNotification::TYPE, Like::TYPE)
			->willReturn($existing);

		$this->notificationInterface->expects($this->never())->method('save');
		$this->notificationInterface->expects($this->once())->method('update')->with($this->identicalTo($existing));

		$this->handler->processIncomingRequest($this->incomingLike());

		$this->assertSame(['carol@other.example', 'bob@remote.example'], $existing->getDetails('accounts'));
	}

	public function testLikeOnARemotePostDoesNotNotifyAnyone(): void {
		$this->noStoredLike();
		$this->streamRequest->method('getStreamById')->willReturn($this->post(false));

		$this->streamRequest->expects($this->once())->method('updateDetails');
		$this->notificationInterface->expects($this->never())->method('save');
		$this->notificationInterface->expects($this->never())->method('update');

		$this->handler->processIncomingRequest($this->incomingLike());
	}

	public function testLikeAlreadyKnownIsNotStoredTwice(): void {
		$like = $this->incomingLike();
		$this->actionsRequest->method('getActionFromItem')->willReturn($like);

		$this->actionsRequest->expects($this->never())->method('save');
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->processIncomingRequest($like);
	}

	public function testSavingAKnownLikeIsReportedToTheCaller(): void {
		$like = $this->incomingLike();
		$this->actionsRequest->method('getActionFromItem')->willReturn($like);

		$this->expectException(ItemAlreadyExistsException::class);

		$this->handler->save($like);
	}

	public function testLikeOfAnUnknownPostIsStoredWithoutCounterOrNotification(): void {
		$this->noStoredLike();
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$this->actionsRequest->expects($this->once())->method('save');
		$this->streamRequest->expects($this->never())->method('updateDetails');
		$this->notificationInterface->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->incomingLike());
	}

	public function testLikeNotSignedByTheLikersServerIsRefused(): void {
		$this->actionsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->incomingLike('evil.example'));
	}

	public function testUndoDeletesTheLikeAndRecomputesTheCounter(): void {
		$like = $this->incomingLike();
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $like);
		$post = $this->post(true, 1);
		$this->streamRequest->method('getStreamById')->willReturn($post);
		$this->actionsRequest->method('countActions')->willReturn(0);
		$this->noNotificationYet();

		$this->actionsRequest->expects($this->once())->method('delete')->with($this->identicalTo($like));
		$this->streamRequest->expects($this->once())->method('updateDetails')->with($this->identicalTo($post));

		$this->handler->activity($undo, $like);

		$this->assertSame(1, $post->getDetailInt('likes'));
	}

	public function testUndoRemovesTheLikerFromTheNotificationAndDropsItWhenNobodyIsLeft(): void {
		$like = $this->incomingLike();
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $like);
		$this->streamRequest->method('getStreamById')->willReturn($this->post());
		$notification = $this->likeNotification('bob@remote.example');
		$this->streamRequest->method('getStreamByObjectId')->willReturn($notification);

		$this->notificationInterface->expects($this->once())->method('delete')->with($this->identicalTo($notification));
		$this->notificationInterface->expects($this->never())->method('update');

		$this->handler->activity($undo, $like);
	}

	public function testUndoKeepsTheNotificationWhileOtherLikersRemain(): void {
		$like = $this->incomingLike();
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $like);
		$this->streamRequest->method('getStreamById')->willReturn($this->post());
		$notification = $this->likeNotification('bob@remote.example', 'carol@other.example');
		$this->streamRequest->method('getStreamByObjectId')->willReturn($notification);

		$this->notificationInterface->expects($this->once())->method('update')->with($this->identicalTo($notification));
		$this->notificationInterface->expects($this->never())->method('delete');

		$this->handler->activity($undo, $like);

		$this->assertSame(['carol@other.example'], array_values($notification->getDetails('accounts')));
	}

	public function testUndoNotComingFromTheLikersServerIsRefused(): void {
		$like = $this->incomingLike();
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $like, 'evil.example');

		$this->actionsRequest->expects($this->never())->method('delete');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($undo, $like);
	}

	public function testOtherActivitiesAreIgnored(): void {
		$like = $this->incomingLike();
		$create = $this->incoming(Create::TYPE, self::REMOTE_URL . '/creates/1', $this->bob->getId(), $like);

		$this->actionsRequest->expects($this->never())->method('delete');
		$this->actionsRequest->expects($this->never())->method('save');

		$this->handler->activity($create, $like);
	}

	public function testGetItemReturnsTheStoredLike(): void {
		$stored = new Like();
		$this->actionsRequest->method('getAction')
			->with($this->bob->getId(), self::POST, Like::TYPE)
			->willReturn($stored);

		$this->assertSame($stored, $this->handler->getItem($this->incomingLike()));
	}

	public function testGetItemThrowsWhenTheLikeIsUnknown(): void {
		$this->actionsRequest->method('getAction')->willThrowException(new ActionDoesNotExistException());

		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItem($this->incomingLike());
	}
}
