<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
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

	private ReactionsRequest $reactions;

	protected function setUp(): void {
		parent::setUp();
		$this->reactions = Server::get(ReactionsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->reactions->deleteByObjectId(self::POST);
		$this->reactions->deleteByObjectId(self::OTHER);
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

		$this->assertCount(2, $this->reactions->getByObjectId(self::POST));
		$this->assertSame("\u{1F525}", $this->reactions->getReaction(self::ALICE, self::POST, "\u{1F525}")->getContent());
	}

	public function testManyPostsAreReadAtOnceKeyedByTheirHash(): void {
		$this->react(self::ALICE, self::POST, "\u{1F525}");
		$this->react(self::BOB, self::OTHER, "\u{1F44D}");

		$byPost = $this->reactions->getByObjectIds([self::POST, self::OTHER]);

		$this->assertCount(1, $byPost[md5(self::POST)] ?? []);
		$this->assertCount(1, $byPost[md5(self::OTHER)] ?? []);
		$this->assertSame([], $this->reactions->getByObjectIds([]));
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
		$this->assertSame([], $this->reactions->getByObjectId(self::POST));
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

		$this->assertCount(1, $this->reactions->getByObjectId(self::POST));
		$this->expectException(ActionDoesNotExistException::class);
		$this->reactions->getReaction(self::ALICE, self::POST, "\u{1F525}");
	}
}
