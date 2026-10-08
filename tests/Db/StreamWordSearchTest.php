<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * How `StreamRequest::searchContent()` turns the word index into a page.
 *
 * The index hands over candidates in nid order; the viewer's visibility,
 * applied when they are hydrated, decides which are answers. These pin the
 * rounds between the two, and that the old scan is what answers until the
 * index is complete.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamWordSearchTest extends TestCase {
	private SearchTermsRequest&MockObject $terms;
	/** @var list<array{list<string>, string}> nids and prefix of every hydration */
	private array $hydrated = [];

	/** @param callable(list<string>): list<string> $visible the nids the viewer may see of a round */
	private function request(bool $ready = true, ?callable $visible = null): StreamRequest&MockObject {
		$this->terms = $this->createMock(SearchTermsRequest::class);
		$this->terms->method('isReady')->willReturn($ready);

		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['searchHits', 'scanContent'])
			->getMock();
		$request->method('searchHits')->willReturnCallback(
			function (array $nids, string $prefix) use ($visible): array {
				$this->hydrated[] = [$nids, $prefix];

				return array_map(static function (string $nid): Stream {
					$note = new Note();
					$note->setNid($nid);

					return $note;
				}, ($visible === null) ? $nids : $visible($nids));
			}
		);
		(new ReflectionProperty(StreamRequest::class, 'searchTermsRequest'))->setValue($request, $this->terms);

		$config = $this->createMock(ConfigService::class);
		$config->method('getAppValueInt')->willReturn(365);
		(new ReflectionProperty(StreamRequest::class, 'configService'))->setValue($request, $config);

		return $request;
	}

	/** @param Stream[] $streams */
	private function nids(array $streams): array {
		return array_map(static fn (Stream $stream): string => (string)$stream->getNid(), $streams);
	}

	/** @return list<string> */
	private static function range(int $from, int $count): array {
		return array_map('strval', range($from, $from - $count + 1));
	}

	public function testTheWholeWordsAreLookedUpAndTheLastIsABeginning(): void {
		$request = $this->request();
		$this->terms->expects($this->once())->method('candidateNids')
			->with(['zebra'], '', 0, 0, '0', '', 100)
			->willReturn(['9', '8']);

		$this->assertSame(['9', '8'], $this->nids($request->searchContent('Zebra cro', 20)));
		$this->assertSame([[['9', '8'], 'cro']], $this->hydrated, 'the beginning is checked against the narrowed posts');
	}

	public function testALonePrefixDrivesTheIndexInsideTheWindow(): void {
		$request = $this->request();
		$this->terms->expects($this->once())->method('candidateNids')
			->with([], 'zeb', 0, 0, $this->callback(static fn (string $since): bool => $since !== '0'), '', 100)
			->willReturn(['9']);

		$request->searchContent('zeb', 20);

		$this->assertSame([[['9'], '']], $this->hydrated);
	}

	/** A round the visibility emptied is followed by the candidates below its last one. */
	public function testARoundTheViewerCannotSeeIsFollowedByTheNext(): void {
		$request = $this->request(true, static fn (array $nids): array => in_array('1000', $nids, true) ? [] : $nids);
		$calls = [];
		$this->terms->method('candidateNids')->willReturnCallback(
			function (array $whole, string $prefix, $max) use (&$calls): array {
				$calls[] = (string)$max;

				return ((string)$max === '0') ? self::range(1000, 100) : self::range(800, 30);
			}
		);

		$found = $request->searchContent('zebra', 20);

		$this->assertSame(['0', '901'], $calls);
		$this->assertSame(self::range(800, 20), $this->nids($found));
	}

	public function testTheRoundsAreBounded(): void {
		$request = $this->request(true, static fn (array $nids): array => []);
		$this->terms->expects($this->exactly(5))->method('candidateNids')->willReturnCallback(
			static fn (array $whole, string $prefix, $max): array => self::range(((string)$max === '0') ? 100000 : (int)$max - 1, 100)
		);

		$this->assertSame([], $request->searchContent('zebra', 20));
	}

	public function testAnOffsetSkipsAnswersNotCandidates(): void {
		$request = $this->request(true, static fn (array $nids): array => array_values(array_filter(
			$nids, static fn (string $nid): bool => (int)$nid % 2 === 0
		)));
		$this->terms->method('candidateNids')->willReturn(self::range(100, 100));

		$found = $request->searchContent('zebra', 5, 10);

		$this->assertSame(['80', '78', '76', '74', '72'], $this->nids($found));
	}

	public function testUntilTheIndexIsCompleteTheScanAnswers(): void {
		$request = $this->request(false);
		$this->terms->expects($this->never())->method('candidateNids');
		$request->expects($this->once())->method('scanContent')
			->with('zebra', 20, 0, '', 5, 0)
			->willReturn([]);

		$request->searchContent('zebra', 20, 0, '', 5);
	}

	public function testAQueryWithNothingSearchableReadsNothing(): void {
		$request = $this->request();
		$this->terms->expects($this->never())->method('candidateNids');

		$this->assertSame([], $request->searchContent('a ?', 20));
	}

	public function testAPagePastTheDeepestOffsetIsEmpty(): void {
		$request = $this->request();
		$this->terms->expects($this->never())->method('candidateNids');

		$this->assertSame([], $request->searchContent('zebra', 20, StreamRequest::SEARCH_MAX_OFFSET + 1));
	}

	/**
	 * The words are written in the transaction that writes the post, as the
	 * recipients and tags are: a post stored without them is one no search
	 * finds, and an edit that kept the old ones answers for words it no
	 * longer holds.
	 */
	public function testThePostsWordsAreWrittenWithItAndRewrittenWithAnEdit(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../lib/Db/StreamRequest.php');
		$body = static function (string $method) use ($source): string {
			$body = preg_split('/public function ' . $method . '\(/', $source, 2)[1] ?? '';

			return preg_split('/\n\t\}\n/', $body, 2)[0];
		};

		$this->assertMatchesRegularExpression(
			'/generateStreamTags\(\$stream\);\s+\$this->searchTermsRequest->index\(\$stream\);\s+(?:\/\/[^\n]*\s+)*\$this->dbConnection->commit\(\);/',
			$body('save')
		);
		$this->assertMatchesRegularExpression(
			'/replaceStreamTags\(\$stream\);\s+(?:\/\/[^\n]*\s+)*\$this->searchTermsRequest->reindex\(\$stream\);\s+\$this->dbConnection->commit\(\);/',
			$body('update')
		);
	}
}
