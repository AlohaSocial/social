<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActorRelation;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Emoji reactions: one per account, post and emoji, readable per post and
 * following an account that moves.
 */
class ReactionsRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/react-alice';
	private const BOB = 'https://itest.example/users/react-bob';
	private const POST = 'https://itest.example/posts/react-1';
	private const OTHER = 'https://itest.example/posts/react-2';

	private const NOW = 1790000000;

	private ReactionsRequest $reactions;
	private ActorRelationRequest $relations;
	private MuteExpiryRequest $expiries;

	protected function setUp(): void {
		parent::setUp();
		$this->reactions = Server::get(ReactionsRequest::class);
		$this->relations = Server::get(ActorRelationRequest::class);
		$this->expiries = Server::get(MuteExpiryRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->reactions->deleteByObjectId(self::POST);
		$this->reactions->deleteByObjectId(self::OTHER);
		$this->relations->deleteByActor(self::ALICE);
		$this->expiries->deleteByActor(self::ALICE);
	}

	/** @return array<string, int> */
	private function countsOf(string $post): array {
		return $this->reactions->countByObjectIds([$post])[md5($post)] ?? [];
	}

	private function react(string $actor, string $post, string $emoji): bool {
		$reaction = new EmojiReact();
		$reaction->setId($actor . '#react/' . md5($post . $emoji));
		$reaction->setActorId($actor);
		$reaction->setObjectId($post);
		$reaction->setContent($emoji);

		return $this->reactions->save($reaction);
	}

	public function testAReactionIsKeptOncePerAccountPostAndEmoji(): void {
		$this->assertTrue($this->react(self::ALICE, self::POST, "\u{1F525}"));
		$this->assertFalse($this->react(self::ALICE, self::POST, "\u{1F525}"), 'the same reaction twice');
		$this->assertTrue($this->react(self::ALICE, self::POST, "\u{1F44D}"), 'another emoji is another reaction');

		$this->assertSameCounts(["\u{1F525}" => 1, "\u{1F44D}" => 1], $this->countsOf(self::POST));
		$this->assertSame("\u{1F525}", $this->reactions->getReaction(self::ALICE, self::POST, "\u{1F525}")->getContent());
	}

	public function testDuplicateReactionsDoNotAbortTheSurroundingTransaction(): void {
		$connection = Server::get(IDBConnection::class);
		$connection->beginTransaction();
		try {
			$this->assertTrue($this->react(self::ALICE, self::POST, '👍'));
			$this->assertFalse($this->react(self::ALICE, self::POST, '👍'));
			$first = $this->reactions->getReaction(self::ALICE, self::POST, '👍');
			$duplicate = clone $first;
			$duplicate->setId(self::ALICE . '#overlapping-copy');
			$this->assertFalse($this->reactions->save($duplicate));
			$this->assertSame($first->getId(), $this->reactions->getReaction(self::ALICE, self::POST, '👍')->getId());
			$this->assertTrue($this->react(self::ALICE, self::POST, '🔥'));
			$this->assertSameCounts(['👍' => 1, '🔥' => 1], $this->countsOf(self::POST));
		} finally {
			$connection->rollBack();
		}
	}

	public function testManyPostsAreCountedAtOnceKeyedByTheirHash(): void {
		$this->react(self::ALICE, self::POST, "\u{1F525}");
		$this->react(self::BOB, self::POST, "\u{1F525}");
		$this->react(self::BOB, self::OTHER, "\u{1F44D}");

		$counts = $this->reactions->countByObjectIds([self::POST, self::OTHER]);

		$this->assertSame(["\u{1F525}" => 2], $counts[md5(self::POST)] ?? []);
		$this->assertSame(["\u{1F44D}" => 1], $counts[md5(self::OTHER)] ?? []);
		$this->assertSame([], $this->reactions->countByObjectIds([]));
		$this->assertSame([], $this->reactions->countByObjectIds(['']));
	}

	/** Counted by the database: a post is counted in full, past any page of rows. */
	public function testEveryReactionIsCountedWhateverTheirNumber(): void {
		for ($i = 0; $i < 60; $i++) {
			$this->react('https://itest.example/users/react-many-' . $i, self::POST, $i % 3 === 0 ? '🎉' : '👍');
		}

		$this->assertSameCounts(['👍' => 40, '🎉' => 20], $this->countsOf(self::POST));
	}

	/**
	 * The emoji of a post come in the order they were first used, and those
	 * first used in the same second by their bytes, on every database.
	 */
	public function testEmojiAreOrderedByTheirFirstUseThenByTheirBytes(): void {
		$this->react(self::ALICE, self::POST, '👍');
		$this->react(self::BOB, self::POST, '🔥');
		$this->react(self::BOB, self::POST, '🎉');
		$this->react(self::ALICE, self::POST, '🎉');
		$this->setCreation(self::POST, '🔥', '2026-01-01 10:00:00');
		$this->setCreation(self::POST, '👍', '2026-01-01 10:00:05');
		$this->setCreation(self::POST, '🎉', '2026-01-01 10:00:05');

		$this->assertSame(['🔥', '🎉', '👍'], array_keys($this->countsOf(self::POST)), '🎉 sorts before 👍 by its bytes');
		$this->assertSame(2, $this->countsOf(self::POST)['🎉']);
	}

	/** The counts, whatever order emoji used in the same second come in. */
	private function assertSameCounts(array $expected, array $actual): void {
		ksort($expected);
		ksort($actual);
		$this->assertSame($expected, $actual);
	}

	private function setCreation(string $post, string $emoji, string $at): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('social_reaction')
			->set('creation', $qb->createNamedParameter(new \DateTime($at), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('object_id_prim', $qb->createNamedParameter(md5($post))))
			->andWhere($qb->expr()->eq('emoji', $qb->createNamedParameter($emoji)))
			->executeStatement();
	}

	public function testTheReadersOwnEmojiAreReadForAPage(): void {
		$this->react(self::ALICE, self::POST, '👍');
		$this->react(self::ALICE, self::OTHER, '🎉');
		$this->react(self::BOB, self::OTHER, '🔥');

		$this->assertSame([
			md5(self::POST) => ['👍' => true],
			md5(self::OTHER) => ['🎉' => true],
		], $this->reactions->emojisOf(self::ALICE, [self::POST, self::OTHER]));
		$this->assertSame([], $this->reactions->emojisOf('', [self::POST]));
	}

	public function testModeratedAccountsAndInstancesAreNotCounted(): void {
		$this->react(self::ALICE, self::POST, '👍');
		$this->react(self::BOB, self::POST, '👍');
		$this->react('https://evil.example/users/troll', self::POST, '👍');
		$this->react('https://sub.evil.example/users/troll', self::POST, '👍');
		$this->react('https://notevil.example/users/fine', self::POST, '👍');

		$counts = $this->reactions->countByObjectIds([self::POST], '', [self::BOB], ['evil.example']);

		$this->assertSame(['👍' => 2], $counts[md5(self::POST)] ?? [], 'alice and notevil.example');
	}

	/**
	 * The reactions of an account the reader blocks, is blocked by or mutes
	 * are not counted for them; a mute that ran out no longer hides anything.
	 */
	public function testTheReadersBlocksAndMutesAreNotCountedForThem(): void {
		$carol = 'https://itest.example/users/react-carol';
		$dave = 'https://itest.example/users/react-dave';
		$erin = 'https://itest.example/users/react-erin';
		$frank = 'https://itest.example/users/react-frank';
		foreach ([self::BOB, $carol, $dave, $erin, $frank] as $actor) {
			$this->react($actor, self::POST, '👍');
		}
		$this->relations->save(self::ALICE, self::BOB, ActorRelation::TYPE_BLOCK);
		$this->relations->save(self::ALICE, $carol, ActorRelation::TYPE_BLOCKED_BY);
		$this->relations->save(self::ALICE, $dave, ActorRelation::TYPE_MUTE);
		$this->relations->save(self::ALICE, $erin, ActorRelation::TYPE_MUTE);
		$this->expiries->save(self::ALICE, $erin, self::NOW - 60);
		$this->relations->save(self::ALICE, $frank, ActorRelation::TYPE_MUTE);
		$this->expiries->save(self::ALICE, $frank, self::NOW + 3600);

		$forAlice = $this->reactions->countByObjectIds([self::POST], self::ALICE, [], [], self::NOW);
		$forAnybody = $this->reactions->countByObjectIds([self::POST], '', [], [], self::NOW);

		$this->assertSame(['👍' => 1], $forAlice[md5(self::POST)] ?? [], 'only erin, whose mute ran out');
		$this->assertSame(['👍' => 5], $forAnybody[md5(self::POST)] ?? []);
	}

	public function testALateUndoOfAnOldReactionKeepsItsNewerReplacement(): void {
		$this->assertTrue($this->react(self::ALICE, self::POST, '👍'));
		$old = $this->reactions->getReaction(self::ALICE, self::POST, '👍');
		$this->reactions->delete($old);
		$new = clone $old;
		$new->setId(self::ALICE . '#new-reaction');
		$this->assertTrue($this->reactions->save($new));

		$this->reactions->delete($old);

		$this->assertSame($new->getId(), $this->reactions->getReaction(self::ALICE, self::POST, '👍')->getId());
		$this->reactions->delete($new);
		$this->assertSame([], $this->countsOf(self::POST));
	}

	public function testAReactionIdMustAlsoMatchTheActorPostAndEmojiBeingRemoved(): void {
		$this->assertTrue($this->react(self::ALICE, self::POST, '👍'));
		$stored = $this->reactions->getReaction(self::ALICE, self::POST, '👍');
		foreach (['actor', 'post', 'emoji'] as $mismatch) {
			$wrong = clone $stored;
			match ($mismatch) {
				'actor' => $wrong->setActorId(self::BOB),
				'post' => $wrong->setObjectId(self::OTHER),
				'emoji' => $wrong->setContent('🔥'),
			};
			$this->reactions->delete($wrong);
			$this->assertSame($stored->getId(), $this->reactions->getReaction(self::ALICE, self::POST, '👍')->getId());
		}
	}

	public function testTakingOneReactionBackLeavesTheOthers(): void {
		$this->react(self::ALICE, self::POST, "\u{1F525}");
		$this->react(self::ALICE, self::POST, "\u{1F44D}");

		$this->reactions->deleteReaction(self::ALICE, self::POST, "\u{1F525}");

		$this->assertSame(["\u{1F44D}" => 1], $this->countsOf(self::POST));
		$this->expectException(ActionDoesNotExistException::class);
		$this->reactions->getReaction(self::ALICE, self::POST, "\u{1F525}");
	}
}
