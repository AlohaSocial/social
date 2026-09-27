<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The home timeline is two pages, not one query.
 *
 * A post belongs there if the viewer follows its author *or* follows one of
 * its hashtags and the post is public. Each half is a query of its own over
 * the one indexed column the page is chosen by; this is what happens to the
 * two answers. The SQL of each half needs a database and is exercised by the
 * integration suite.
 */
#[AllowMockObjectsWithoutExpectations]
class HomeTimelineFollowedTagsTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	private FollowedTagsRequest|MockObject $followedTagsRequest;
	/** @var int[]|null the nids the tag half was asked for, null if never asked */
	private ?array $tagPage = null;
	/** @var int[] the nids the row-reading query was handed */
	private array $read = [];

	/**
	 * @param int[] $followed what the follows half returns
	 * @param int[] $tagged what the followed-tags half returns
	 * @return StreamRequest&MockObject
	 */
	private function request(array $followed, array $tagged, int $followedTags = 1, bool $viewer = true) {
		$this->followedTagsRequest = $this->createMock(FollowedTagsRequest::class);
		$this->followedTagsRequest->method('countByActor')->willReturn($followedTags);

		// the constructor takes an IDBConnection, and mocking one needs DBAL,
		// which the standalone suite cannot load — so the one dependency this
		// exercises is put in by hand
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['homeTimelineNids', 'followedTagNids', 'streamsByNids'])
			->getMock();
		(new ReflectionProperty(StreamRequest::class, 'followedTagsRequest'))
			->setValue($request, $this->followedTagsRequest);

		$request->method('homeTimelineNids')->willReturn($followed);
		$request->method('followedTagNids')
			->willReturnCallback(function () use ($tagged): array {
				$this->tagPage = $tagged;

				return $tagged;
			});
		$request->method('streamsByNids')
			->willReturnCallback(function (array $nids): array {
				$this->read = $nids;

				return array_map(static function (int $nid): Stream {
					$note = new Note();
					$note->setNid($nid);

					return $note;
				}, $nids);
			});

		if ($viewer) {
			$person = new Person();
			$person->setId(self::VIEWER);
			$request->setViewer($person);
		}

		return $request;
	}

	private function options(int $limit = 20): ProbeOptions {
		return (new ProbeOptions())->setProbe(ProbeOptions::HOME)->setLimit($limit);
	}

	/** @return int[] */
	private function nids(array $streams): array {
		return array_map(static fn (Stream $s): int => $s->getNid(), $streams);
	}

	public function testAPostCarryingAFollowedTagIsInTheTimeline(): void {
		// the whole point of the feature: without this the endpoints are decoration
		$request = $this->request([50, 30], [40]);

		$timeline = $request->getTimeline($this->options());

		$this->assertSame([50, 40, 30], $this->nids($timeline));
	}

	public function testTheTwoHalvesComeBackNewestFirstAsOnePage(): void {
		$request = $this->request([90, 60, 10], [80, 70, 20]);

		$timeline = $request->getTimeline($this->options());

		$this->assertSame([90, 80, 70, 60, 20, 10], $this->nids($timeline));
	}

	public function testAPostThatIsBothFollowedAndTaggedAppearsOnce(): void {
		// somebody you follow posting a tag you follow is in both halves
		$request = $this->request([50, 40, 30], [50, 40]);

		$timeline = $request->getTimeline($this->options());

		$this->assertSame([50, 40, 30], $this->nids($timeline));
	}

	public function testThePageIsStillNoLongerThanTheLimit(): void {
		// each half was already cut to the limit; together they are twice it,
		// and a client that asked for 3 may not be handed 6
		$request = $this->request([60, 50, 40], [35, 25, 15]);

		$timeline = $request->getTimeline($this->options(3));

		$this->assertSame([60, 50, 40], $this->nids($timeline));
		$this->assertSame([60, 50, 40], $this->read, 'only the page is read back');
	}

	public function testTheMergedPageIsTheTopOfBothHalves(): void {
		// the cut may not drop a tagged post that outranks a followed one
		$request = $this->request([60, 20, 10], [55, 50, 45]);

		$timeline = $request->getTimeline($this->options(3));

		$this->assertSame([60, 55, 50], $this->nids($timeline));
	}

	public function testAnInvertedPageIsOldestFirstBeforeItIsReversed(): void {
		// min_id paging inverts the query; getTimeline() reverses the result,
		// so the page itself has to be the *oldest* limit rows
		$request = $this->request([10, 20, 30], [15, 25, 35]);
		$options = $this->options(3)->setInverted(true);

		$timeline = $request->getTimeline($options);

		$this->assertSame([20, 15, 10], $this->nids($timeline));
		$this->assertSame([10, 15, 20], $this->read);
	}

	public function testAViewerWhoFollowsNoTagIsNotAskedForATagPage(): void {
		// which is what the feature costs everybody who does not use it: one
		// count answered out of an index
		$request = $this->request([50, 40], [99], 0);

		$timeline = $request->getTimeline($this->options());

		$this->assertNull($this->tagPage, 'the second query is never built');
		$this->assertSame([50, 40], $this->nids($timeline));
	}

	public function testWithoutAViewerNothingIsCountedOrAsked(): void {
		$request = $this->request([], [99], 1, false);
		$this->followedTagsRequest->expects($this->never())->method('countByActor');

		$request->getTimeline($this->options());

		$this->assertNull($this->tagPage);
	}

	public function testAnEmptyPageIsNotRead(): void {
		$request = $this->request([], []);

		$this->assertSame([], $request->getTimeline($this->options()));
		$this->assertSame([], $this->read);
	}
}
