<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tools\Nid;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * How far back a page of the home timeline reads.
 *
 * Across several collections `(actor_id, type, nid)` cannot hand the rows out
 * in `nid` order, so the database reads everything the predicate admits and
 * sorts it: without a bound on the nid, a page cost everything the reader's
 * follows ever posted, every thirty seconds. The page is read within a window
 * of publication time instead and widened only when it comes back short —
 * which must give exactly the page the unbounded query gives, and that is what
 * is checked here, against a recipient table held in memory.
 */
#[AllowMockObjectsWithoutExpectations]
class HomeTimelineWindowTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';
	private const DAY = 86400;

	/** when the test started; the request reads the clock itself */
	private int $now = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->now = time();
	}

	/** @var list<array{actor: string, nid: string}> the recipient rows */
	private array $rows = [];

	/** @var list<array{window: string, returned: int}> every page query run */
	private array $runs = [];

	/** @var list<string> every predicate the collections were matched with */
	private array $collectionPredicates = [];

	private function followed(): string {
		return md5('https://remote.example/users/bob/followers');
	}

	private function own(): string {
		return md5(self::ALICE . '/followers');
	}

	/** One recipient row, `$ago` seconds before NOW. */
	private function row(string $actor, int $ago, int $random = 1): void {
		$this->rows[] = ['actor' => $actor, 'nid' => Nid::fromPublishedTime($this->now - $ago, $random, StreamRequest::NID_LIMIT)];
	}

	/**
	 * A query builder that runs the query it was built into against $rows.
	 *
	 * It understands exactly the predicates the home page query is made of:
	 * a collection predicate, `sd.type`, and `sd.nid` compared with a bound
	 * parameter — and fails on anything else, so the test cannot pass by
	 * ignoring a condition it did not recognise.
	 */
	private function builder(): SocialQueryBuilder&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$state = new \stdClass();
		$state->params = [];
		$state->where = [];
		$state->order = 'desc';
		$state->limit = 0;

		$qb->method('expr')->willReturn(new FakeExpressions());
		foreach (['select', 'from'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value, $type = null, $placeholder = null) use ($state): string {
				$name = ($placeholder === null) ? ':p' . count($state->params) : $placeholder;
				$state->params[ltrim($name, ':')] = $value;

				return $name;
			}
		);
		$qb->method('setParameter')->willReturnCallback(static function ($key, $value) use ($qb, $state) {
			$state->params[$key] = $value;

			return $qb;
		});
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(static function ($predicate) use ($qb, $state) {
				$state->where[] = (string)$predicate;

				return $qb;
			});
		}
		$qb->method('orderBy')->willReturnCallback(static function (string $sort, ?string $direction = null) use ($qb, $state) {
			$state->order = (string)$direction;

			return $qb;
		});
		$qb->method('setMaxResults')->willReturnCallback(static function (int $limit) use ($qb, $state) {
			$state->limit = $limit;

			return $qb;
		});
		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->execute($state));

		return $qb;
	}

	private function execute(\stdClass $state): IResult {
		$matched = [];
		foreach ($this->rows as $row) {
			if ($this->admits($state, $row)) {
				$matched[] = $row['nid'];
			}
		}

		usort($matched, static fn (string $a, string $b): int => ($state->order === 'asc') ? Nid::compare($a, $b) : Nid::compare($b, $a));
		$matched = array_slice($matched, 0, $state->limit);
		$this->runs[] = ['window' => (string)($state->params['home_window'] ?? ''), 'returned' => count($matched)];

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			...array_merge(array_map(static fn (string $nid): array => ['nid' => $nid], $matched), [false])
		);

		return $result;
	}

	/** @param array{actor: string, nid: string} $row */
	private function admits(\stdClass $state, array $row): bool {
		foreach ($state->where as $predicate) {
			if ($predicate === 'HOME COLLECTIONS') {
				if ($row['actor'] !== $this->followed()) {
					return false;
				}
			} elseif (preg_match('/^sd\.actor_id = :(\w+)$/', $predicate, $m) === 1) {
				if ($row['actor'] !== $state->params[$m[1]]) {
					return false;
				}
			} elseif (preg_match('/^actor_id IN \(:(\w+)\)$/', $predicate, $m) === 1) {
				if (!in_array($row['actor'], $state->params[$m[1]], true)) {
					return false;
				}
			} elseif (preg_match('/^(sd\.)?type = :(\w+)$/', $predicate, $m) === 1) {
				$this->assertSame('recipient', $state->params[$m[2]]);
			} elseif (preg_match('/^sd\.nid (<|>) :(\w+)$/', $predicate, $m) === 1) {
				$comparison = Nid::compare($row['nid'], (string)$state->params[$m[2]]);
				if (($m[1] === '>' && $comparison <= 0) || ($m[1] === '<' && $comparison >= 0)) {
					return false;
				}
			} else {
				$this->fail('the page query carries a predicate this test does not model: ' . $predicate);
			}
		}

		return true;
	}

	private function request(): StreamRequest {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(fn (): SocialQueryBuilder => $this->builder());

		$follows = $this->createMock(FollowsRequest::class);
		$follows->expects($this->never())->method('getHomeCollectionPrims');
		$follows->method('limitToHomeCollections')->willReturnCallback(
			function (SocialQueryBuilder $qb, string $field, string $actorId, array $also = []): string {
				$this->assertSame('sd.actor_id', $field);
				$this->assertSame(self::ALICE, $actorId);
				// the own collection is read apart: `OR`ed beside the
				// sub-select, it cost MariaDB its semi-join
				$this->assertSame([], $also);
				$this->collectionPredicates[] = $field;

				return 'HOME COLLECTIONS';
			}
		);

		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueBool')->willReturn(true);

		$viewer = new Person();
		$viewer->setId(self::ALICE);
		$viewer->setFollowers(self::ALICE . '/followers');

		foreach (['followsRequest' => $follows, 'configService' => $config, 'viewer' => $viewer, 'recipientNidsFilled' => null] as $name => $value) {
			(new ReflectionProperty(StreamRequest::class, $name))->setValue($request, $value);
		}

		return $request;
	}

	/** @return string[] */
	private function page(ProbeOptions $options): array {
		return (new ReflectionMethod(StreamRequest::class, 'homeTimelineNidsFromRecipients'))
			->invoke($this->request(), $options);
	}

	private function options(int $limit = 20): ProbeOptions {
		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::HOME)->setLimit($limit);

		return $options;
	}

	/**
	 * What the unbounded query would return: the $wanted rows nearest the
	 * cursor, over both collections.
	 *
	 * @return string[]
	 */
	private function unbounded(int $wanted, string $maxId = '0', string $minId = '0'): array {
		$nids = [];
		foreach ($this->rows as $row) {
			if (Nid::compare($maxId, '0') > 0 && Nid::compare($row['nid'], $maxId) >= 0) {
				continue;
			}
			if (Nid::compare($minId, '0') > 0 && Nid::compare($row['nid'], $minId) <= 0) {
				continue;
			}
			$nids[] = $row['nid'];
		}
		$ascending = Nid::compare($minId, '0') > 0;
		usort($nids, static fn (string $a, string $b): int => $ascending ? Nid::compare($a, $b) : Nid::compare($b, $a));

		return array_slice($nids, 0, $wanted);
	}

	/** An account that posts every twenty minutes, and a reader who posted twice. */
	private function busyInstance(): void {
		for ($i = 0; $i < 72 * 200; $i++) {
			$this->row($this->followed(), $i * 1200 + 60, $i + 1);
		}
		$this->row($this->own(), 30 * 60, 7);
		$this->row($this->own(), 3 * self::DAY, 8);
	}

	public function testABusyTimelineIsReadWithinTheLastDay(): void {
		$this->busyInstance();

		$page = $this->page($this->options(20));

		$this->assertSame($this->unbounded(60), $page);
		// the day before the cursor held sixty rows, so one query answered it
		$this->assertCount(1, $this->windowedRuns());
		$this->assertADayBeforeNow($this->windowedRuns()[0]['window']);
	}

	/** A sparse timeline: a few posts a month, the latest two weeks ago. */
	public function testAShortPageIsReadAgainWiderUntilItIsFull(): void {
		for ($i = 0; $i < 200; $i++) {
			$this->row($this->followed(), 14 * self::DAY + $i * 10 * self::DAY, $i + 1);
		}

		$page = $this->page($this->options(20));

		$this->assertSame($this->unbounded(60), $page);
		$windows = array_column($this->windowedRuns(), 'window');
		$this->assertCount(5, $windows, 'a day, a week, a month, a year, then without a bound');
		$this->assertSame('0', end($windows), 'the last attempt has no bound at all');
	}

	public function testAWindowThatFillsThePageEndsTheWidening(): void {
		for ($i = 0; $i < 100; $i++) {
			$this->row($this->followed(), 2 * self::DAY + $i * 3600, $i + 1);
		}

		$this->assertSame($this->unbounded(60), $this->page($this->options(20)));
		// nothing in the last day, sixty within the week: two queries
		$this->assertCount(2, $this->windowedRuns());
	}

	/**
	 * A page further down is measured from its cursor, not from now: reading
	 * it within "the last day" would find nothing below the cursor and widen
	 * all the way every time.
	 */
	public function testAnOlderPageIsMeasuredFromItsCursor(): void {
		$this->busyInstance();
		$cursor = $this->unbounded(2000)[1500];

		$options = $this->options(20);
		$options->setMaxId($cursor);

		$this->assertSame($this->unbounded(60, $cursor), $this->page($options));
		$this->assertCount(1, $this->windowedRuns());
		$this->assertSame(
			Nid::fromPublishedTime(Nid::publishedTimeOf($cursor, StreamRequest::NID_LIMIT) - self::DAY, 0, StreamRequest::NID_LIMIT),
			$this->windowedRuns()[0]['window']
		);
	}

	public function testAPageReadForwardsIsWindowedAboveItsCursor(): void {
		$this->busyInstance();
		$cursor = $this->unbounded(2000)[1500];

		$options = $this->options(20);
		$options->setMinId($cursor);

		$page = $this->page($options);
		$this->assertTrue($options->isInverted());
		$this->assertSame($this->unbounded(60, '0', $cursor), $page);
		$this->assertSame(
			Nid::fromPublishedTime(Nid::publishedTimeOf($cursor, StreamRequest::NID_LIMIT) + self::DAY, 0, StreamRequest::NID_LIMIT),
			$this->windowedRuns()[0]['window']
		);
	}

	/**
	 * The reader's own posts come from their own follower collection, read as
	 * a query of its own and merged in order.
	 */
	#[DataProvider('provideLimits')]
	public function testTheReadersOwnPostsAreMergedInOrder(int $limit): void {
		$this->busyInstance();

		$page = $this->page($this->options($limit));

		$this->assertSame($this->unbounded(min(300, 3 * $limit)), $page);
		$this->assertContains($this->rows[count($this->rows) - 2]['nid'], $page, 'the reader\'s own post of half an hour ago');
	}

	/** @return array<string, array{int}> */
	public static function provideLimits(): array {
		return ['a page of 20' => [20], 'a page of 40' => [40], 'a page of 1' => [1]];
	}

	public function testAReaderWhoFollowsNobodyStillReadsTheirOwnPosts(): void {
		$this->row($this->own(), 3600, 1);
		$request = $this->request();
		$follows = $this->createStub(FollowsRequest::class);
		$follows->method('limitToHomeCollections')->willReturn('');
		(new ReflectionProperty(StreamRequest::class, 'followsRequest'))->setValue($request, $follows);

		$page = (new ReflectionMethod(StreamRequest::class, 'homeTimelineNidsFromRecipients'))
			->invoke($request, $this->options());

		$this->assertSame([$this->rows[0]['nid']], $page);
	}

	public function testTheEntityTagIsTheNewestNidInTheSameWindows(): void {
		$this->busyInstance();

		$viewer = new Person();
		$viewer->setId(self::ALICE);
		$viewer->setFollowers(self::ALICE . '/followers');

		$this->assertSame($this->unbounded(1)[0], $this->request()->newestHomeNid($viewer));
		$this->assertADayBeforeNow($this->windowedRuns()[0]['window']);
	}

	/** The window is publication time: a nid of a day before the clock read. */
	private function assertADayBeforeNow(string $bound): void {
		$time = Nid::publishedTimeOf($bound, StreamRequest::NID_LIMIT);
		$this->assertSame(Nid::fromPublishedTime($time, 0, StreamRequest::NID_LIMIT), $bound);
		$this->assertGreaterThanOrEqual($this->now - self::DAY, $time);
		$this->assertLessThanOrEqual(time() - self::DAY, $time);
	}

	/** @return list<array{window: string, returned: int}> the runs of the followed half */
	private function windowedRuns(): array {
		return array_values(array_filter($this->runs, static fn (array $run): bool => $run['window'] !== ''));
	}
}
