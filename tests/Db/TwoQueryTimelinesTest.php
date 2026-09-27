<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The direct, account and hashtag timelines are two queries, like every
 * other: the page is decided over one indexed column, then exactly those
 * rows are read. What is pinned here is the shape -- which query decides and
 * that the wide read is handed the page and nothing else, or is not made at
 * all. The SQL of each page needs a database and is exercised by the
 * integration suite (TimelineSeedTest, StreamFilterTest, MediaTypeTimelineTest).
 */
#[AllowMockObjectsWithoutExpectations]
class TwoQueryTimelinesTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	/** @var int[]|null what the wide read was handed, null if it was never made */
	private ?array $read = null;

	/** @return StreamRequest&\PHPUnit\Framework\MockObject\MockObject */
	private function request(string $pageMethod, array $page) {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['directTimelineNids', 'accountTimelineNids', 'hashtagTimelineNids', 'streamsByNids'])
			->getMock();
		$request->method($pageMethod)->willReturn($page);
		$request->method('streamsByNids')->willReturnCallback(function (array $nids): array {
			$this->read = $nids;

			return array_map(static function (int $nid): Stream {
				$note = new Note();
				$note->setNid($nid);

				return $note;
			}, $nids);
		});
		$person = new Person();
		$person->setId(self::VIEWER);
		$request->setViewer($person);

		return $request;
	}

	/** @return iterable<string, array{string, string, ProbeOptions}> */
	public static function timelines(): iterable {
		yield 'direct' => ['directTimelineNids', ProbeOptions::DIRECT, new ProbeOptions()];
		yield 'account' => ['accountTimelineNids', ProbeOptions::ACCOUNT, (new ProbeOptions())->setAccountId('https://remote.example/users/bob')];
		yield 'hashtag' => ['hashtagTimelineNids', ProbeOptions::HASHTAG, (new ProbeOptions())->setArgument('nextcloud')];
	}

	#[DataProvider('timelines')]
	public function testThePageDecidesAndTheWideReadGetsExactlyIt(string $pageMethod, string $probe, ProbeOptions $options): void {
		$request = $this->request($pageMethod, [50, 40, 30]);

		$timeline = $request->getTimeline($options->setProbe($probe)->setLimit(20));

		$this->assertSame([50, 40, 30], array_map(static fn (Stream $s): int => $s->getNid(), $timeline));
		$this->assertSame([50, 40, 30], $this->read, 'only the page is read back');
	}

	#[DataProvider('timelines')]
	public function testAnEmptyPageMakesNoWideReadAtAll(string $pageMethod, string $probe, ProbeOptions $options): void {
		$request = $this->request($pageMethod, []);

		$this->assertSame([], $request->getTimeline($options->setProbe($probe)->setLimit(20)));
		$this->assertNull($this->read, 'nothing to read, nothing asked');
	}

	public function testAnAccountTimelineWithNoAccountAsksNothing(): void {
		$request = $this->request('accountTimelineNids', [1, 2]);
		$request->expects($this->never())->method('accountTimelineNids');

		$this->assertSame([], $request->getTimeline((new ProbeOptions())->setProbe(ProbeOptions::ACCOUNT)->setLimit(20)));
		$this->assertNull($this->read);
	}
}
