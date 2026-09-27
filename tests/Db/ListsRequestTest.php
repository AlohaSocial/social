<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\ListsRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What a list's storage decides without a database: the form a title is kept
 * in, and what the list timeline does with the page once the page is chosen.
 *
 * The SQL of the timeline — the join against the membership table, and the
 * home-timeline filters around it — needs a real database and is exercised by
 * the integration suite.
 */
#[AllowMockObjectsWithoutExpectations]
class ListsRequestTest extends TestCase {
	/** @var int[] the nids the row-reading query was handed, null if never asked */
	private ?array $read = null;

	/**
	 * @param int[] $page what the nid query returns
	 *
	 * @return ListsRequest&MockObject
	 */
	private function request(array $page) {
		// the constructor takes an IDBConnection, and mocking one needs DBAL,
		// which the standalone suite cannot load
		$request = $this->getMockBuilder(ListsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['listTimelineNids', 'streamsByNids'])
			->getMock();

		$request->method('listTimelineNids')->willReturn($page);
		$request->method('streamsByNids')
			->willReturnCallback(function (array $nids): array {
				$this->read = $nids;

				return array_map(static function (int $nid): Stream {
					$note = new Note();
					$note->setNid($nid);

					return $note;
				}, $nids);
			});

		return $request;
	}

	private function options(bool $inverted = false): ProbeOptions {
		$options = (new ProbeOptions())->setLimit(20);
		$options->setInverted($inverted);

		return $options;
	}

	/** @param Stream[] $posts */
	private function nids(array $posts): array {
		return array_map(static fn (Stream $post): int => $post->getNid(), $posts);
	}

	public function testTheTitleIsWhatWasTypedWithoutItsSurroundingSpace(): void {
		$this->assertSame('Friends', ListsRequest::normaliseTitle('  Friends '));
		$this->assertSame('Friends', ListsRequest::normaliseTitle("Friends\n"));
	}

	public function testTheTitleKeepsItsCaseAndItsSpacing(): void {
		// unlike a hashtag, a title is a label the user wrote and nothing
		// compares it to anything
		$this->assertSame('Work Friends', ListsRequest::normaliseTitle('Work Friends'));
		$this->assertSame('NextCloud', ListsRequest::normaliseTitle('NextCloud'));
	}

	public function testATitleIsCutToWhatTheColumnHolds(): void {
		// social_list.title is VARCHAR(255); a longer one fails the insert
		// outright on a strict MySQL
		$normalised = ListsRequest::normaliseTitle(str_repeat('a', 400));

		$this->assertSame(255, mb_strlen($normalised));
	}

	public function testTheCutCountsCharactersRatherThanBytes(): void {
		// substr() would cut a multi-byte title mid-character and store a
		// broken one
		$normalised = ListsRequest::normaliseTitle(str_repeat('é', 400));

		$this->assertSame(255, mb_strlen($normalised));
		$this->assertSame(str_repeat('é', 255), $normalised);
	}

	public function testATitleThatIsNotOneNormalisesToNothing(): void {
		// the controller turns this into Mastodon's 422 rather than storing a
		// list the user cannot tell from any other blank one
		$this->assertSame('', ListsRequest::normaliseTitle('   '));
		$this->assertSame('', ListsRequest::normaliseTitle(''));
	}

	public function testAnEmptyPageIsNotAskedForAtAll(): void {
		// reading rows `IN ()` is a query that cannot match and a syntax error
		// on some platforms
		$posts = $this->request([])->getTimeline(new MastodonList(), $this->options());

		$this->assertSame([], $posts);
		$this->assertNull($this->read, 'the row query is never run');
	}

	public function testThePageIsTheOneTheNidQueryChose(): void {
		$posts = $this->request([9, 7, 4])->getTimeline(new MastodonList(), $this->options());

		$this->assertSame([9, 7, 4], $this->read);
		$this->assertSame([9, 7, 4], $this->nids($posts));
	}

	public function testAPageAskedForUpwardsIsStillHandedBackNewestFirst(): void {
		// min_id inverts the query so the page is the *oldest* unread posts;
		// what a client renders is still newest first, as every other timeline
		// here hands back
		$options = $this->options(true);

		$posts = $this->request([4, 7, 9])->getTimeline(new MastodonList(), $options);

		$this->assertSame([4, 7, 9], $this->read, 'the rows are read in the query order');
		$this->assertSame([9, 7, 4], $this->nids($posts));
	}
}
