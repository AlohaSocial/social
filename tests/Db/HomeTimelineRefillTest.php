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
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * A home page that filtering emptied reads on instead of ending the timeline.
 *
 * The fast page query chooses its page over one column and carries none of the
 * per-viewer filters; blocks, mutes and hidden boosts are applied to the rows
 * it chose. Reading three times the limit covers a page that loses a few posts
 * that way. It does not cover one that loses all of them — a muted account that
 * has just posted sixty times in a row — and an empty page is how both clients
 * read "there is nothing more": the web app sets `allLoaded` on a page of zero,
 * and no `Link: rel="next"` is sent. The timeline ended in the middle, with
 * older posts the reader can see sitting a few hundred rows further down.
 */
#[AllowMockObjectsWithoutExpectations]
class HomeTimelineRefillTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';

	private StreamRequest|MockObject $request;

	/** @var ProbeOptions[] what each page query was asked for */
	private array $asked = [];

	/** @var array<int, Stream[]> what hydrating each window came back with */
	private array $hydrated = [];

	/** @var int[][] the windows the page query answers with, in order */
	private array $windows = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['homeTimelineNidsFromRecipients', 'streamsByNids'])
			->getMock();

		$this->request->method('homeTimelineNidsFromRecipients')->willReturnCallback(
			function (ProbeOptions $options): ?array {
				$this->asked[] = clone $options;

				return array_shift($this->windows);
			}
		);
		$this->request->method('streamsByNids')->willReturnCallback(
			fn (array $nids): array => array_shift($this->hydrated) ?? []
		);

		$viewer = new Person();
		$viewer->setId(self::ALICE);
		(new ReflectionProperty(StreamRequest::class, 'viewer'))->setValue($this->request, $viewer);

		// the followed-tag half is not what is under test here
		$tags = $this->createStub(FollowedTagsRequest::class);
		$tags->method('countByActor')->willReturn(0);
		(new ReflectionProperty(StreamRequest::class, 'followedTagsRequest'))
			->setValue($this->request, $tags);
	}

	/** @return Stream[] */
	private function page(int $limit = 3): array {
		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::HOME)->setLimit($limit);

		return (new ReflectionMethod(StreamRequest::class, 'getTimelineHome'))
			->invoke($this->request, $options);
	}

	/** @return Stream[] */
	private function posts(int $count): array {
		return array_map(fn (): Stream => new Stream(), range(1, $count));
	}

	public function testAWindowThatFilteringEmptiedIsFollowedByTheNextOne(): void {
		$this->windows = [[100, 99, 98], [97, 96, 95], []];
		$this->hydrated = [[], $this->posts(3)];

		$this->assertCount(3, $this->page(), 'the page is filled from further down');
		$this->assertCount(2, $this->asked);
	}

	/**
	 * From below the oldest id the page has *considered*, not the oldest it
	 * kept: everything between the two was looked at and dropped.
	 */
	public function testTheNextWindowStartsBelowEverythingAlreadyLookedAt(): void {
		$this->windows = [[100, 99, 98], [97, 96, 95]];
		$this->hydrated = [[], $this->posts(3)];

		$this->page();

		$this->assertSame(0, $this->asked[0]->getMaxId(), 'the first window is the head');
		$this->assertSame(98, $this->asked[1]->getMaxId());
	}

	/** A full page asks for nothing more, which is every ordinary request. */
	public function testAFullPageIsNotReadTwice(): void {
		$this->windows = [[100, 99, 98]];
		$this->hydrated = [$this->posts(3)];

		$this->assertCount(3, $this->page());
		$this->assertCount(1, $this->asked, 'nothing to refill');
	}

	/** A page that is merely short still counts as the end when it is. */
	public function testAnExhaustedTimelineStopsAtTheEnd(): void {
		$this->windows = [[100, 99], []];
		$this->hydrated = [$this->posts(1)];

		$this->assertCount(1, $this->page());
		$this->assertCount(2, $this->asked, 'it looked, and there was nothing');
	}

	/**
	 * A reader who has muted everything they follow must not turn one request
	 * into a walk of the table.
	 */
	public function testTheReadingOnIsBounded(): void {
		$this->windows = array_fill(0, 20, [100, 99, 98]);
		$this->hydrated = [];

		$this->assertSame([], $this->page());
		$this->assertLessThanOrEqual(5, count($this->asked));
	}

	/** Paging backwards reads the other way: further up, not further down. */
	public function testAnInvertedPageReadsOnAboveWhatItHasSeen(): void {
		$this->windows = [[95, 96, 97], [98, 99, 100]];
		$this->hydrated = [[], $this->posts(3)];

		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::HOME)->setLimit(3)->setMinId(90)->setInverted(true);
		(new ReflectionMethod(StreamRequest::class, 'getTimelineHome'))
			->invoke($this->request, $options);

		$this->assertSame(97, $this->asked[1]->getMinId());
	}
}
