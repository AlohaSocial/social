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
	private const MOVED = 'https://itest.example/users/react-alice-new';
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

	public function testTakingOneReactionBackLeavesTheOthers(): void {
		$this->react(self::ALICE, self::POST, "\u{1F525}");
		$this->react(self::ALICE, self::POST, "\u{1F44D}");

		$this->reactions->deleteReaction(self::ALICE, self::POST, "\u{1F525}");

		$this->assertCount(1, $this->reactions->getByObjectId(self::POST));
		$this->expectException(ActionDoesNotExistException::class);
		$this->reactions->getReaction(self::ALICE, self::POST, "\u{1F525}");
	}

	public function testReactionsFollowAnAccountThatMoves(): void {
		$this->react(self::ALICE, self::POST, "\u{1F525}");

		$this->reactions->moveAccount(self::ALICE, self::MOVED);

		$this->assertSame(self::MOVED, $this->reactions->getReaction(self::MOVED, self::POST, "\u{1F525}")->getActorId());
		$this->reactions->deleteByActor(self::MOVED);
		$this->assertSame([], $this->reactions->getByObjectId(self::POST));
	}
}
