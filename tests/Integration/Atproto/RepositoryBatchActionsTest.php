<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Db\AccessBlocksRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Model\AccessBlock;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\LikeService;
use OCA\Social\Tests\Integration\Api\ApiTestCase;
use OCP\IDBConnection;
use OCP\Server;

/**
 * The Social actions a Bluesky app's `createRecord`, `deleteRecord` and
 * `applyWrites` perform, each inside the repository batch `WriteService`
 * opens around it: one database transaction for the Social rows, the staged
 * repository record and its commit.
 *
 * On PostgreSQL a statement that fails aborts the whole transaction, so an
 * INSERT refused by a unique index — the per-viewer action row a like made
 * before, met again by the unlike — used to fail the batch at its commit.
 * Each step here is one batch, as one XRPC call is, and must commit.
 */
class RepositoryBatchActionsTest extends ApiTestCase {
	private const DID = 'did:plc:batchactionstest00000000';
	private const BLOCKED = '198.51.100.77';

	private RepositoryService $repositories;
	private PrivateKey $key;

	protected function setUp(): void {
		parent::setUp();
		$this->repositories = Server::get(RepositoryService::class);
		$this->key = PrivateKey::generate(Curve::K256);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		if ($this->repositories->batchActive()) {
			$this->repositories->rollbackBatch();
		}
		$this->cleanUp();
		parent::tearDown();
	}

	public function testLikeUnlikeLikeAndUnRepostEachCommitTheirBatch(): void {
		$alice = $this->account('alice');
		$this->account('bob');
		$postId = (string)$this->publish('bob', ['status' => 'Liked, unliked and liked again', 'visibility' => 'public'])->uri;
		$likes = Server::get(LikeService::class);
		$boosts = Server::get(BoostService::class);

		$this->inBatch(fn () => $likes->create($alice, $postId));
		$this->assertTrue($this->flag($alice->getId(), $postId, StreamAction::LIKED));

		$this->inBatch(fn () => $likes->delete($alice, $postId));
		$this->assertFalse($this->flag($alice->getId(), $postId, StreamAction::LIKED), 'the unlike met the row the like made');

		$this->inBatch(fn () => $likes->create($alice, $postId));
		$this->assertTrue($this->flag($alice->getId(), $postId, StreamAction::LIKED), 'liking again updated the same row');

		$this->inBatch(fn () => $boosts->create($alice, $postId));
		$this->inBatch(fn () => $boosts->delete($alice, $postId));
		$this->assertFalse($this->flag($alice->getId(), $postId, StreamAction::BOOSTED), 'the un-repost met the row as well');
		$this->assertTrue($this->flag($alice->getId(), $postId, StreamAction::LIKED), 'and left the like alone');
	}

	public function testRelationAndBlockRowsThatAreThereAlreadyLeaveTheBatchUsable(): void {
		$alice = $this->account('alice')->getId();
		$bob = $this->account('bob')->getId();
		$relations = Server::get(ActorRelationRequest::class);
		$expiries = Server::get(MuteExpiryRequest::class);
		$blocks = Server::get(AccessBlocksRequest::class);

		$this->inBatch(function () use ($relations, $expiries, $blocks, $alice, $bob): void {
			$relations->save($alice, $bob, ActorRelation::TYPE_MUTE, true);
			$relations->save($alice, $bob, ActorRelation::TYPE_MUTE, false);
			$expiries->save($alice, $bob, 2_000_000_000);
			$expiries->save($alice, $bob, 2_000_000_600);
			$blocks->save(new AccessBlock(AccessBlock::TYPE_IP, self::BLOCKED, AccessBlock::SEVERITY_NO_ACCESS, 'first'));
			$blocks->save(new AccessBlock(AccessBlock::TYPE_IP, self::BLOCKED, AccessBlock::SEVERITY_NO_ACCESS, 'second'));
		});

		$this->assertFalse($relations->getRelation($alice, $bob, ActorRelation::TYPE_MUTE)?->isNotifications() ?? true);
		$this->assertSame(2_000_000_600, $expiries->getExpiry($alice, $bob));
		$comments = array_map(
			static fn (AccessBlock $block): string => $block->getComment(),
			array_filter($blocks->getByType(AccessBlock::TYPE_IP), static fn (AccessBlock $block): bool => $block->getValue() === self::BLOCKED)
		);
		$this->assertSame(['second'], array_values($comments));
	}

	/**
	 * One XRPC write as `WriteService` runs it: the Social action, a record
	 * staged in the repository, and one commit of both.
	 */
	private function inBatch(callable $action): void {
		$activities = Server::get(ActivityService::class);
		$this->repositories->beginBatch(self::DID, $this->key);
		$activities->beginDeferredDeliveries();
		try {
			$action();
			$this->repositories->write(self::DID, $this->key, [
				RepoWrite::create('app.bsky.feed.like', [
					'$type' => 'app.bsky.feed.like',
					'subject' => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/' . Tid::next(), 'cid' => Cid::forDagCbor(DagCbor::encode(['text' => 'liked']))->toString()],
					'createdAt' => '2026-11-08T10:00:00.000Z',
				], '', Tid::next()),
			]);
			$commit = $this->repositories->commitBatch();
		} finally {
			$activities->discardDeferredDeliveries();
		}

		$this->assertNotNull($commit, 'the batch committed');
		$this->assertSame($commit->commitCid->toString(), $this->repositories->getHead(self::DID)?->commitCid);
	}

	private function flag(string $actorId, string $postId, string $flag): bool {
		return (bool)(Server::get(StreamActionsRequest::class)->getAction($actorId, $postId)->getValues()[$flag] ?? false);
	}

	private function cleanUp(): void {
		$this->repositories->delete(self::DID);
		$connection = Server::get(IDBConnection::class);
		$qb = $connection->getQueryBuilder();
		$qb->delete('social_atproto_event')->where($qb->expr()->eq('did', $qb->createNamedParameter(self::DID)));
		$qb->executeStatement();
		$blocks = Server::get(AccessBlocksRequest::class);
		foreach ($blocks->getByType(AccessBlock::TYPE_IP) as $block) {
			if ($block->getValue() === self::BLOCKED) {
				$blocks->delete($block->getId(), AccessBlock::TYPE_IP);
			}
		}
	}
}
