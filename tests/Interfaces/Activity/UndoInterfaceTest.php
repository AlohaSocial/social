<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Activity\ActivityObjectResolver;
use OCA\Social\Interfaces\Activity\UndoInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\NullLogger;

require_once __DIR__ . '/DispatchingActivityTestCase.php';
require_once __DIR__ . '/TResolvesActivityObjects.php';

#[AllowMockObjectsWithoutExpectations]
class UndoInterfaceTest extends DispatchingActivityTestCase {
	use TResolvesActivityObjects;

	private ActivityObjectResolver $objectResolver;

	protected function setUp(): void {
		parent::setUp();

		$this->objectResolver = $this->objectResolverOver();
	}

	protected function createHandler(): IActivityPubInterface {
		return new UndoInterface($this->objectResolver, new NullLogger());
	}

	protected function activityType(): string {
		return Undo::TYPE;
	}

	public function testUndoOfALikeGoesToTheLikeInterface(): void {
		$like = new Like();
		$like->setId(self::REMOTE_URL . '/likes/1');
		$like->setActorId(self::REMOTE_URL . '/users/bob');
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', self::REMOTE_URL . '/users/bob', $like);

		$this->likeInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($undo), $this->identicalTo($like));
		$this->followInterface->expects($this->never())->method('activity');
		$this->announceInterface->expects($this->never())->method('activity');

		$this->createHandler()->processIncomingRequest($undo);
	}

	public function testUndoOfABoostGoesToTheAnnounceInterface(): void {
		$announce = new Announce();
		$announce->setId(self::REMOTE_URL . '/announces/1');
		$announce->setActorId(self::REMOTE_URL . '/users/bob');
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', self::REMOTE_URL . '/users/bob', $announce);

		$this->announceInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($undo), $this->identicalTo($announce));
		$this->likeInterface->expects($this->never())->method('activity');

		$this->createHandler()->processIncomingRequest($undo);
	}

	/** An unfollow from a peer that links its Follow instead of embedding it. */
	public function testUndoCarryingTheFollowAsALinkReachesTheFollowInterface(): void {
		$follow = new Follow();
		$follow->setId(self::REMOTE_URL . '/follows/1');
		$follow->setActorId(self::REMOTE_URL . '/users/bob');
		$this->objectResolver = $this->objectResolverOver([self::REMOTE_URL . '/follows/1' => $follow]);

		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', self::REMOTE_URL . '/users/bob');
		$undo->setObjectId(self::REMOTE_URL . '/follows/1');

		$this->followInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($undo), $this->identicalTo($follow));

		$this->createHandler()->processIncomingRequest($undo);
	}

	/**
	 * The interfaces below check the host an Undo came from, which on a shared
	 * server says nothing about which of its accounts sent it — and an action
	 * is deleted by its id, so naming a neighbour's like and one's own `actor`
	 * took the neighbour's like down.
	 */
	public function testUndoOfANeighboursLikeIsRefused(): void {
		$like = new Like();
		$like->setId(self::REMOTE_URL . '/likes/1');
		$like->setActorId(self::REMOTE_URL . '/users/bob');
		$undo = $this->incoming(
			Undo::TYPE, self::REMOTE_URL . '/undo/1', self::REMOTE_URL . '/users/mallory', $like
		);

		$this->likeInterface->expects($this->never())->method('activity');

		$this->expectException(InvalidOriginException::class);

		$this->createHandler()->processIncomingRequest($undo);
	}

	/**
	 * The embedded copy is written by the sender, so it is only ever consulted
	 * when this instance holds nothing under that id: naming a neighbour's like
	 * and calling it one's own is exactly what the stored row disproves.
	 */
	public function testTheStoredRowIsPreferredOverTheEmbeddedCopy(): void {
		$stored = new Like();
		$stored->setId(self::REMOTE_URL . '/likes/1');
		$stored->setActorId(self::REMOTE_URL . '/users/bob');
		$this->objectResolver = $this->objectResolverOver([self::REMOTE_URL . '/likes/1' => $stored]);

		$claimed = new Like();
		$claimed->setId(self::REMOTE_URL . '/likes/1');
		$claimed->setActorId(self::REMOTE_URL . '/users/mallory');
		$undo = $this->incoming(
			Undo::TYPE, self::REMOTE_URL . '/undo/1', self::REMOTE_URL . '/users/mallory', $claimed
		);

		$this->likeInterface->expects($this->never())->method('activity');

		$this->expectException(InvalidOriginException::class);

		$this->createHandler()->processIncomingRequest($undo);
	}

	/** An unlike from a peer that links its Like instead of embedding it. */
	public function testUndoCarryingTheLikeAsALinkReachesTheLikeInterface(): void {
		$like = new Like();
		$like->setId(self::REMOTE_URL . '/likes/1');
		$like->setActorId(self::REMOTE_URL . '/users/bob');
		$this->objectResolver = $this->objectResolverOver([self::REMOTE_URL . '/likes/1' => $like]);

		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', self::REMOTE_URL . '/users/bob');
		$undo->setObjectId(self::REMOTE_URL . '/likes/1');

		$this->likeInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($undo), $this->identicalTo($like));

		$this->createHandler()->processIncomingRequest($undo);
	}
}
