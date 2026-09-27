<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Internal;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

require_once __DIR__ . '/../ActivityPubTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class SocialAppNotificationInterfaceTest extends ActivityPubTestCase {
	private const ID = self::REMOTE_URL . '/follows/1/notification';

	/** @var StreamRequest&MockObject */
	private $streamRequest;
	/** @var ActorRelationRequest&MockObject */
	private $actorRelationRequest;
	/** @var MiscService&MockObject */
	private $miscService;
	private AccountRelationService|MockObject $accountRelationService;
	private SocialAppNotificationInterface $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->miscService = $this->createMock(MiscService::class);

		$this->actorRelationRequest = $this->createMock(ActorRelationRequest::class);
		$this->accountRelationService = $this->createMock(AccountRelationService::class);
		$this->handler = new SocialAppNotificationInterface(
			$this->streamRequest,
			$this->actorRelationRequest,
			$this->miscService,
			$this->createStub(NotificationService::class),
			$this->accountRelationService,
		);
	}

	private function notification(): SocialAppNotification {
		$notification = new SocialAppNotification();
		$notification->setId(self::ID);
		$notification->setSubType(Follow::TYPE);
		$notification->setTo(self::LOCAL_URL . '/users/alice');
		$notification->setLocal(true);

		return $notification;
	}

	public function testNotificationWithoutAnIdIsNotStored(): void {
		$this->streamRequest->expects($this->never())->method('save');
		$this->miscService->expects($this->never())->method('log');

		$this->handler->save(new SocialAppNotification());
	}

	public function testSaveStampsThePublicationTimeAndStoresTheNotificationAsAStream(): void {
		$notification = $this->notification();

		$this->streamRequest->expects($this->once())->method('save')->with($this->identicalTo($notification));

		$this->handler->save($notification);

		$this->assertNotSame('', $notification->getPublished());
		$this->assertEqualsWithDelta(time(), $notification->getPublishedTime(), 5);
	}

	/**
	 * The relations between recipient and originator, as getBetween() returns
	 * them, against whether the notification must still be stored.
	 *
	 * @return array<string, array{array<int, array{string, bool}>, bool}>
	 */
	public static function suppressionProvider(): array {
		return [
			'recipient blocks the actor' => [[[ActorRelation::TYPE_BLOCK, true]], false],
			'actor blocks the recipient' => [[[ActorRelation::TYPE_BLOCKED_BY, true]], false],
			'muted with notifications hidden' => [[[ActorRelation::TYPE_MUTE, true]], false],
			'muted but notifications kept' => [[[ActorRelation::TYPE_MUTE, false]], true],
			'no relation at all' => [[], true],
		];
	}

	/**
	 * @param array<int, array{string, bool}> $relations
	 */
	#[DataProvider('suppressionProvider')]
	public function testNotificationsFromBlockedOrFullyMutedActorsAreSuppressed(
		array $relations, bool $saved,
	): void {
		$notification = $this->notification();
		$notification->setAttributedTo(self::REMOTE_URL . '/users/bob');

		$this->actorRelationRequest->expects($this->once())
			->method('getBetween')
			->with(self::LOCAL_URL . '/users/alice', self::REMOTE_URL . '/users/bob')
			->willReturn(array_map(
				fn (array $relation): ActorRelation => (new ActorRelation())
					->setType($relation[0])
					->setNotifications($relation[1]),
				$relations
			));
		$this->streamRequest->expects($saved ? $this->once() : $this->never())
			->method('save')->with($this->identicalTo($notification));

		$this->handler->save($notification);
	}

	/**
	 * A mute given a duration stops applying when it runs out, and nothing
	 * deletes the row to make that happen. Suppressing on the row alone meant
	 * the notification was never stored at all, so neither the read filter nor
	 * the bell could tell that the mute had ended.
	 */
	public function testAMuteThatHasRunOutNoLongerSuppressesTheNotification(): void {
		$notification = $this->notification();
		$notification->setAttributedTo(self::REMOTE_URL . '/users/bob');

		$this->actorRelationRequest->method('getBetween')->willReturn([
			(new ActorRelation())->setType(ActorRelation::TYPE_MUTE)->setNotifications(true),
		]);
		$this->accountRelationService->expects($this->once())
			->method('isMuteExpired')
			->with(self::LOCAL_URL . '/users/alice', self::REMOTE_URL . '/users/bob')
			->willReturn(true);

		$this->streamRequest->expects($this->once())->method('save')
			->with($this->identicalTo($notification));

		$this->handler->save($notification);
	}

	/** A block is a block whatever a mute expiry says, and is not asked about. */
	public function testABlockIsNotWeighedAgainstAMuteExpiry(): void {
		$notification = $this->notification();
		$notification->setAttributedTo(self::REMOTE_URL . '/users/bob');

		$this->actorRelationRequest->method('getBetween')->willReturn([
			(new ActorRelation())->setType(ActorRelation::TYPE_BLOCK)->setNotifications(true),
		]);
		$this->accountRelationService->expects($this->never())->method('isMuteExpired');
		$this->streamRequest->expects($this->never())->method('save');

		$this->handler->save($notification);
	}

	public function testUpdateRewritesTheNotificationAndItsRecipients(): void {
		$notification = $this->notification();

		$this->streamRequest->expects($this->once())->method('update')->with($this->identicalTo($notification), true);
		$this->streamRequest->expects($this->never())->method('save');

		$this->handler->update($notification);
	}

	public function testDeleteRemovesOnlyTheNotificationRow(): void {
		$this->streamRequest->expects($this->once())->method('deleteById')->with(self::ID, SocialAppNotification::TYPE);

		$this->handler->delete($this->notification());
	}
}
