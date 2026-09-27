<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The walk over a thread, with the one query it repeats replaced: the unit suite
 * has no database, so the SQL of a level is exercised by the integration suite
 * and the traversal — which levels are asked for, in what order the rows come
 * back, where it stops — is exercised here.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamRequestTest extends TestCase {
	private const ROOT = 'https://social.example/@alice/root';

	/** @var StreamRequest&MockObject */
	private $streamRequest;
	/** @var array<int, array{0: string[], 1: int}> the (ids, limit) of every level asked for */
	private array $levels = [];

	protected function setUp(): void {
		$this->streamRequest = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getRepliesTo'])
			->getMock();
	}

	private function reply(string $id, string $parent, int $published): Note {
		$note = new Note();
		$note->setId($id);
		$note->setInReplyTo($parent);
		$note->setPublishedTime($published);

		return $note;
	}

	/**
	 * @param array<string, Stream[]> $children replies keyed by parent id
	 */
	private function thread(array $children): void {
		$this->streamRequest->method('getRepliesTo')
			->willReturnCallback(function (array $ids, int $limit) use ($children): array {
				$this->levels[] = [$ids, $limit];
				$level = [];
				foreach ($ids as $id) {
					$level = array_merge($level, $children[$id] ?? []);
				}
				usort($level, static fn (Stream $a, Stream $b): int => $a->getPublishedTime() <=> $b->getPublishedTime());

				return array_slice($level, 0, $limit);
			});
	}

	/** @return string[] */
	private function ids(array $streams): array {
		return array_map(static fn (Stream $s): string => $s->getId(), $streams);
	}

	public function testGetDescendantsWalksTheWholeSubtreeInThreadOrder(): void {
		$a = $this->reply('a', self::ROOT, 1);
		$b = $this->reply('b', self::ROOT, 2);
		$c = $this->reply('c', 'a', 3);
		$d = $this->reply('d', 'b', 4);
		$e = $this->reply('e', 'c', 5);
		$this->thread([self::ROOT => [$a, $b], 'a' => [$c], 'b' => [$d], 'c' => [$e]]);

		$descendants = $this->streamRequest->getDescendants(self::ROOT);

		// depth first, siblings oldest first: each reply follows what it answers
		$this->assertSame(['a', 'c', 'e', 'b', 'd'], $this->ids($descendants));
		$this->assertSame(
			[[self::ROOT], ['a', 'b'], ['c', 'd'], ['e']],
			array_column($this->levels, 0),
			'each level is asked for in one query, and the walk ends at the first empty level'
		);
		$this->assertSame(
			[StreamRequest::MAX_DESCENDANTS, StreamRequest::MAX_DESCENDANTS - 2, StreamRequest::MAX_DESCENDANTS - 4, StreamRequest::MAX_DESCENDANTS - 5],
			array_column($this->levels, 1),
			'every level is bounded by what is left of the count'
		);
	}

	public function testGetDescendantsStopsAtTheDepthCap(): void {
		$children = [];
		$parent = self::ROOT;
		for ($i = 1; $i <= StreamRequest::MAX_DESCENDANT_DEPTH + 5; $i++) {
			$children[$parent] = [$this->reply('r' . $i, $parent, $i)];
			$parent = 'r' . $i;
		}
		$this->thread($children);

		$descendants = $this->streamRequest->getDescendants(self::ROOT);

		$this->assertCount(StreamRequest::MAX_DESCENDANT_DEPTH, $descendants);
		$this->assertCount(StreamRequest::MAX_DESCENDANT_DEPTH, $this->levels);
	}

	public function testGetDescendantsStopsAtTheCountCap(): void {
		$replies = [];
		for ($i = 1; $i <= StreamRequest::MAX_DESCENDANTS + 10; $i++) {
			$replies[] = $this->reply('r' . $i, self::ROOT, $i);
		}
		$this->thread([self::ROOT => $replies]);

		$descendants = $this->streamRequest->getDescendants(self::ROOT);

		$this->assertCount(StreamRequest::MAX_DESCENDANTS, $descendants);
		$this->assertCount(1, $this->levels, 'a full first level leaves nothing to ask for');
	}

	public function testGetDescendantsOfALeafIsEmpty(): void {
		$this->thread([]);

		$this->assertSame([], $this->streamRequest->getDescendants(self::ROOT));
		$this->assertCount(1, $this->levels);
	}

	private function withContent(string $content): Note {
		$note = new Note();
		$note->setId(self::ROOT . '/' . md5($content));
		$note->setContent($content);

		return $note;
	}

	/**
	 * @param Stream[] $posts
	 *
	 * @return Stream[]
	 */
	private function carrying(array $posts, string $term): array {
		return (new \ReflectionMethod(StreamRequest::class, 'whoseTextCarries'))
			->invoke($this->streamRequest, $posts, $term);
	}

	/**
	 * `content` is stored as markup, so the SQL `LIKE` the content search
	 * builds matches the markup as well as the words: `span`, `href` and
	 * `class` each answered with very nearly every post the instance holds.
	 * The rows are candidates; the flattened text decides.
	 */
	public function testASearchDoesNotMatchTheMarkupAPostIsStoredIn(): void {
		$posts = [
			$this->withContent(
				'<p>a <a href="https://example.invalid/x" class="u-url">'
				. '<span class="invisible">https://</span>link</a></p>'
			),
			$this->withContent('<p>a stretch of time</p>'),
		];

		foreach (['span', 'class', 'href'] as $markup) {
			$this->assertSame(
				[], $this->ids($this->carrying($posts, $markup)),
				'"' . $markup . '" is markup, and matched nearly every post the instance holds'
			);
		}

		$this->assertSame(
			$this->ids([$posts[1]]),
			$this->ids($this->carrying($posts, 'stretch of')),
			'the words a post says are still found'
		);
	}

	public function testASearchMatchesWhateverCaseThePostWroteIt(): void {
		$posts = [$this->withContent('<p>Hello <b>Fediverse</b></p>')];

		$this->assertSame($this->ids($posts), $this->ids($this->carrying($posts, 'fediverse')));
	}

	/** An entity is the character it stands for, not the letters it is written with. */
	public function testASearchSeesThroughAnEntity(): void {
		$posts = [$this->withContent('<p>Bob &amp; Carol</p>')];

		$this->assertSame($this->ids($posts), $this->ids($this->carrying($posts, 'Bob & Carol')));
		$this->assertSame([], $this->ids($this->carrying($posts, 'amp')));
	}
}
