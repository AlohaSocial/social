<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Reader\BlueskyCountSource;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCA\Social\Service\Counts\CountWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The counts of a post read from Bluesky, as the AppView's `getPosts` has them now. */
#[AllowMockObjectsWithoutExpectations]
class BlueskyCountSourceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private PostStore&MockObject $store;
	private CountWriter&MockObject $writer;
	private bool $enabled = true;

	protected function setUp(): void {
		$this->store = $this->createMock(PostStore::class);
		$this->writer = $this->createMock(CountWriter::class);
	}

	private function source(): BlueskyCountSource {
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);

		return new BlueskyCountSource($config, $this->store, $this->writer);
	}

	private static function id(string $rkey): string {
		return 'https://bsky.app/profile/' . self::DID . '/post/' . $rkey;
	}

	private function note(string $id, bool $local = false): Note {
		$note = new Note();
		$note->setId($id);
		$note->setLocal($local);

		return $note;
	}

	public function testOnlyAPostReadFromBlueskyIsAskedAboutAndOnlyWhileBlueskyIsOn(): void {
		$this->assertTrue($this->source()->supports($this->note(self::id('3k1'))));
		$this->assertFalse($this->source()->supports($this->note(self::id('3k1'), true)), 'a post of this server is counted here');
		$this->assertFalse($this->source()->supports($this->note('https://bsky.app/profile/' . self::DID . '/convo/c/m')), 'a direct message has no counts');
		$this->assertFalse($this->source()->supports($this->note('https://remote.example/notes/1')));
		$this->enabled = false;
		$this->assertFalse($this->source()->supports($this->note(self::id('3k1'))));
	}

	public function testTheAppViewsCountsAreWrittenWithTheQuoteCountInTheBlueskyBlock(): void {
		$this->store->expects($this->once())->method('postViews')->with([self::id('a'), self::id('gone')])->willReturn([
			'posts' => [self::id('a') => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/a', 'likeCount' => 12, 'repostCount' => 3, 'replyCount' => 2, 'quoteCount' => 1]],
			'deleted' => 1,
		]);
		$this->writer->expects($this->once())->method('write')
			->with(self::id('a'), 12, 3, 2, [Details::ATPROTO => ['likes' => 12, 'reposts' => 3, 'replies' => 2, 'quotes' => 1]])
			->willReturn(true);
		$this->writer->expects($this->never())->method('stamp');

		$this->assertSame(1, $this->source()->refresh([$this->note(self::id('a')), $this->note(self::id('gone'))]));
	}

	public function testACountThatIsNoNumberIsNotTaken(): void {
		$this->store->method('postViews')->willReturn(['posts' => [self::id('a') => ['likeCount' => -1, 'repostCount' => '3', 'replyCount' => 2]], 'deleted' => 0]);
		$this->writer->expects($this->once())->method('write')
			->with(self::id('a'), null, null, 2, [Details::ATPROTO => ['replies' => 2]])
			->willReturn(true);

		$this->source()->refresh([$this->note(self::id('a'))]);
	}

	public function testPostsTheAppViewDidNotAnswerForAreStampedAndAskedLater(): void {
		$this->store->method('postViews')->willReturn(null);
		$this->writer->expects($this->never())->method('write');
		$this->writer->expects($this->exactly(2))->method('stamp');

		$this->assertSame(0, $this->source()->refresh([$this->note(self::id('a')), $this->note(self::id('b'))]));
	}

	public function testTwentyFivePostsAreOneCall(): void {
		$posts = [];
		for ($i = 0; $i < BlueskyCountSource::BATCH + 1; $i++) {
			$posts[] = $this->note(self::id('3k' . $i));
		}
		$this->store->expects($this->exactly(2))->method('postViews')->willReturn(['posts' => [], 'deleted' => 0]);

		$this->source()->refresh($posts);
	}
}
