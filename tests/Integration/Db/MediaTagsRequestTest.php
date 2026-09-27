<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\MediaTagsRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The people named in a post's pictures.
 */
class MediaTagsRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/mediatag-alice';
	private const BOB = 'https://itest.example/users/mediatag-bob';
	private const TAGGER = 'https://itest.example/users/mediatag-tagger';
	private const POST = '1790000000000000001';
	private const LATER = '1790000000000000002';

	private MediaTagsRequest $tags;

	protected function setUp(): void {
		parent::setUp();
		$this->tags = Server::get(MediaTagsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->tags->deleteByStream(self::POST);
		$this->tags->deleteByStream(self::LATER);
	}

	private function tag(string $post, string $actor): bool {
		return $this->tags->tag($post, md5('itest-post-' . $post), $actor, self::TAGGER);
	}

	public function testNamingSomebodyTwiceIsNotAnError(): void {
		$this->assertTrue($this->tag(self::POST, self::ALICE));
		$this->assertFalse($this->tag(self::POST, self::ALICE));

		$this->assertSame(1, $this->tags->countForStream(self::POST));
		$this->assertTrue($this->tags->isTagged(self::POST, self::ALICE));
		$this->assertFalse($this->tags->isTagged(self::POST, self::BOB));
	}

	public function testThePeopleOnAPageOfPostsAreReadTogetherInOrder(): void {
		$this->tag(self::POST, self::ALICE);
		$this->tag(self::POST, self::BOB);
		$this->tag(self::LATER, self::BOB);

		$this->assertSame(
			[self::POST => [self::ALICE, self::BOB], self::LATER => [self::BOB]],
			$this->tags->forStreams([self::POST, self::LATER])
		);
		$this->assertSame([], $this->tags->forStreams([]));
	}

	public function testThePostsSomebodyIsInAreNewestFirstAndPaged(): void {
		$this->tag(self::POST, self::BOB);
		$this->tag(self::LATER, self::BOB);

		$this->assertSame([self::LATER, self::POST], $this->tags->streamsFor(self::BOB));
		$this->assertSame([self::POST], $this->tags->streamsFor(self::BOB, 40, self::LATER));
		$this->assertSame([self::LATER, self::POST], $this->tags->streamsFor(self::BOB, 40, 'not-a-nid'));
	}

	public function testANameComesOffOnePostOrEveryPost(): void {
		$this->tag(self::POST, self::ALICE);
		$this->tag(self::LATER, self::ALICE);

		$this->assertTrue($this->tags->untag(self::POST, self::ALICE));
		$this->assertFalse($this->tags->untag(self::POST, self::ALICE));
		$this->assertSame([self::LATER], $this->tags->streamsFor(self::ALICE));

		$this->tags->deleteByActor(self::ALICE);
		$this->assertSame([], $this->tags->streamsFor(self::ALICE));
	}
}
