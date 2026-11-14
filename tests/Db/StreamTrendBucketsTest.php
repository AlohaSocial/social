<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Tests\Migration\FakeConnection;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The hourly hashtag buckets the trend windows are counted from: which hours
 * a pass recounts, and when the windows are counted from the posts instead.
 *
 * What the buckets add up to against real tables is
 * `Integration\Db\TrendWindowsTest`'s and `HashtagTrendsTest`'s business.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamTrendBucketsTest extends TestCase {
	/** @var array<string, string> */
	private array $config = [];
	/** @var list<array{string, array}> */
	private array $marked = [];
	/** @var list<array{0: string, 1: array, 2: array}> the raw statements run, with their parameters and types */
	private array $statements = [];

	/** @param string[] $methods */
	private function request(array $methods): StreamRequest&MockObject {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(array_merge(['getQueryBuilder'], $methods))
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(function (): SocialQueryBuilder {
			$qb = $this->createMock(SocialQueryBuilder::class);
			foreach (['delete', 'where', 'andWhere'] as $method) {
				$qb->method($method)->willReturnSelf();
			}
			$qb->method('expr')->willReturn(new FakeExpressions());

			return $qb;
		});

		$config = $this->createMock(ConfigService::class);
		$config->method('getAppValueInt')->willReturnCallback(fn (string $key): int => (int)($this->config[$key] ?? 0));
		$config->method('setAppValue')->willReturnCallback(function (string $key, $value): void {
			$this->config[$key] = (string)$value;
		});
		$marked = &$this->marked;
		$statements = &$this->statements;
		$connection = new class($marked, $statements) extends FakeConnection {
			/**
			 * @param list<array{string, array}> $marked
			 * @param list<array{0: string, 1: array, 2: array}> $statements
			 */
			public function __construct(
				private array &$marked,
				private array &$statements,
			) {
				parent::__construct();
			}

			public function insertIgnoreConflict(string $table, array $values): int {
				$this->marked[] = [$table, $values];

				return 1;
			}

			public function executeStatement($sql, ?array $params = null, ?array $types = null): int {
				$this->statements[] = [$sql, $params ?? [], $types ?? []];

				return count($params ?? []) / 3;
			}
		};
		foreach (['configService' => $config, 'dbConnection' => $connection] as $property => $value) {
			(new \ReflectionProperty(CoreRequestBuilder::class, $property))->setValue($request, $value);
		}

		return $request;
	}

	public function testABucketStartsOnTheHour(): void {
		$this->assertSame(1790000000 - 1790000000 % 3600, StreamRequest::trendHourOf(1790000000));
		$this->assertSame(7200, StreamRequest::trendHourOf(7200));
		$this->assertSame(7200, StreamRequest::trendHourOf(10799));
		$this->assertSame(
			HashtagService::TREND_10D + StreamRequest::TREND_BUCKET,
			StreamRequest::TREND_BUCKET_SPAN,
			'the widest window, and the hour its edge falls in'
		);
	}

	public function testTheFirstPassBuildsEveryBucketOfTheWindow(): void {
		$request = $this->request(['rebuildTrendBuckets', 'recountTrendHours', 'takeDirtyTrendHours', 'trendHoursStoredSince']);
		$request->expects($this->once())->method('rebuildTrendBuckets');
		$request->expects($this->never())->method('recountTrendHours');

		$request->settleTrendBuckets();

		$this->assertEqualsWithDelta(time(), (int)$this->config[StreamRequest::TREND_BUCKETS_MARK], 2);
	}

	public function testALaterPassRecountsTheHoursThatChangedAndNoOthers(): void {
		$this->config[StreamRequest::TREND_BUCKETS_MARK] = (string)(time() - 720);
		$now = StreamRequest::trendHourOf(time());
		$tooOld = StreamRequest::trendHourOf(time() - StreamRequest::TREND_BUCKET_SPAN - 7200);

		$request = $this->request(['rebuildTrendBuckets', 'recountTrendHours', 'takeDirtyTrendHours', 'trendHoursStoredSince']);
		$request->expects($this->never())->method('rebuildTrendBuckets');
		$request->method('takeDirtyTrendHours')->willReturn([$now - 86400, $tooOld]);
		$request->expects($this->once())->method('trendHoursStoredSince')
			->with($this->callback(static fn (int $since): bool => abs($since - (time() - 720 - 300)) <= 2))
			->willReturn([$now, $now - 86400]);
		$recounted = [];
		$request->method('recountTrendHours')->willReturnCallback(function (array $hours) use (&$recounted): void {
			$recounted = array_values($hours);
		});

		$request->settleTrendBuckets();

		sort($recounted);
		$this->assertSame([$now - 86400, $now], $recounted, 'each hour once, and none older than the buckets kept');
	}

	public function testAWindowOlderThanTheBucketsIsCountedFromThePosts(): void {
		$request = $this->request(['countHashtagsInWindowsFromPosts', 'countHashtagsFromBuckets', 'settleTrendBuckets']);
		$request->expects($this->once())->method('countHashtagsInWindowsFromPosts')->willReturn(['30d' => ['x' => 1]]);
		$request->expects($this->never())->method('settleTrendBuckets');
		$request->expects($this->never())->method('countHashtagsFromBuckets');

		$this->assertSame(['30d' => ['x' => 1]], $request->countHashtagsInWindows(['30d' => time() - 30 * 86400]));
	}

	public function testTheTrendWindowsAreCountedOffSettledBuckets(): void {
		$windows = ['1h' => time() - HashtagService::TREND_1H, '10d' => time() - HashtagService::TREND_10D];
		$request = $this->request(['countHashtagsInWindowsFromPosts', 'countHashtagsFromBuckets', 'settleTrendBuckets']);
		$request->expects($this->never())->method('countHashtagsInWindowsFromPosts');
		$request->expects($this->once())->method('settleTrendBuckets');
		$request->expects($this->once())->method('countHashtagsFromBuckets')->with($windows)->willReturn(['1h' => [], '10d' => ['x' => 2]]);

		$this->assertSame(['1h' => [], '10d' => ['x' => 2]], $request->countHashtagsInWindows($windows));
	}

	public function testAnEditOrADeletionMarksItsHourOnlyWhereABucketIsKept(): void {
		$request = $this->request([]);

		$request->markTrendHour(0);
		$request->markTrendHour(time() - StreamRequest::TREND_BUCKET_SPAN - 7200);
		$request->markTrendHour(time() - 60);

		$this->assertSame(
			[[CoreRequestBuilder::TABLE_TAG_HOURS_DIRTY, ['hour_start' => StreamRequest::trendHourOf(time() - 60)]]],
			$this->marked
		);
	}

	/**
	 * A bucket is only recounted when its hour is known to have changed, and a
	 * deletion or an edit changes an hour long after its posts were stored:
	 * each of them marks the hour while the row still says when it was.
	 */
	public function testEveryDeletionAndEditMarksTheHourItChanges(): void {
		$deletion = (string)file_get_contents(__DIR__ . '/../../lib/Db/StreamDeletion.php');
		foreach ([
			'deleteById' => "\$this->markTrendHoursOf([\$prim]);\n\t\t\$deleted = \$qb->executeStatement();",
			'deleteByAuthor' => "\$this->markTrendHoursOf(\$prims);\n\t\t\t\$this->deleteRelatedTo(\$prims);",
			'deleteByPrims' => "\$this->markTrendHoursOf(\$prims);\n\t\t\$qb = \$this->getStreamDeleteSql();",
		] as $method => $call) {
			$this->assertStringContainsString($call, $deletion, $method . '() deletes posts without marking their hours');
		}

		$update = (string)file_get_contents(__DIR__ . '/../../lib/Db/StreamRequest.php');
		$this->assertStringContainsString(
			"\$this->markTrendHoursOf(array_filter([\$qb->prim(\$stream->getId())]));\n\t\t\$this->markTrendHour(\$stream->getPublishedTime());",
			$update,
			'an edit can change the tags, the audience and the time a trend counts by'
		);
		foreach (['IndexService' => 'lib/Service/IndexService.php', 'CheckInstall' => 'lib/Command/CheckInstall.php'] as $name => $file) {
			$this->assertStringContainsString(
				'$this->streamRequest->markTrendHour($stream->getPublishedTime());',
				(string)file_get_contents(__DIR__ . '/../../' . $file),
				$name . ' repairs tag rows without marking their hour'
			);
		}
	}

	public function testRecountingAnHourThatDidNotMoveWritesNothing(): void {
		$this->assertSame(
			['stale' => [], 'rows' => []],
			StreamRequest::trendBucketChanges(7200, ['cats' => 2, 'dogs' => 1], ['dogs' => 1, 'cats' => 2])
		);
	}

	public function testRecountingWritesOnlyTheBucketsThatMoved(): void {
		$this->assertSame(
			[
				// moved, and no longer used
				'stale' => ['cats', 'birds'],
				// moved, and new
				'rows' => [['cats', 7200, 3], ['fish', 7200, 1]],
			],
			StreamRequest::trendBucketChanges(7200, ['cats' => 3, 'dogs' => 1, 'fish' => 1], ['cats' => 2, 'dogs' => 1, 'birds' => 4])
		);
	}

	public function testANumericHashtagIsWrittenAsTheTextItIs(): void {
		// an array key that looks like a number is an int in PHP
		$changes = StreamRequest::trendBucketChanges(7200, ['2026' => 1], ['1999' => 1]);

		$this->assertSame(['1999'], $changes['stale']);
		$this->assertSame([['2026', 7200, 1]], $changes['rows']);
	}

	public function testBucketsAreWrittenManyToAStatement(): void {
		$rows = [];
		for ($i = 0; $i < 600; $i++) {
			$rows[] = ['tag' . $i, 7200, 1 + $i % 3];
		}
		$rows[] = ['unused', 7200, 0];

		$insert = new \ReflectionMethod(StreamRequest::class, 'insertTrendBucketRows');
		$insert->invoke($this->request([]), $rows);

		$this->assertCount(3, $this->statements, 'six hundred buckets, TREND_INSERT_ROWS to a statement');
		$sizes = array_map(static fn (array $statement): int => count($statement[1]) / 3, $this->statements);
		$this->assertSame([StreamRequest::TREND_INSERT_ROWS, StreamRequest::TREND_INSERT_ROWS, 100], $sizes, 'a bucket of no uses is not written');

		[$sql, $params, $types] = $this->statements[2];
		$this->assertStringStartsWith('INSERT INTO `*PREFIX*' . CoreRequestBuilder::TABLE_TAG_HOURS . '` (`hashtag`, `hour_start`, `uses`) VALUES (?, ?, ?), ', $sql);
		$this->assertSame(100, substr_count($sql, '(?, ?, ?)'));
		$this->assertSame(['tag500', 7200, 1 + 500 % 3], array_slice($params, 0, 3));
		$this->assertSame([IQueryBuilder::PARAM_STR, IQueryBuilder::PARAM_INT, IQueryBuilder::PARAM_INT], array_slice($types, 0, 3));
	}

	public function testADeletionMarksEachHourItsPostsWerePublishedInOnce(): void {
		$hour = StreamRequest::trendHourOf(time() - 7200);
		$published = [$hour + 60, $hour + 600, $hour + 3599, $hour + 3600 + 5, 0];

		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(function () use ($published): SocialQueryBuilder {
			$qb = $this->createMock(SocialQueryBuilder::class);
			foreach (['select', 'from', 'where', 'andWhere'] as $method) {
				$qb->method($method)->willReturnSelf();
			}
			$qb->method('expr')->willReturn(new FakeExpressions());
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn(array_map(
				static fn (int $time): array => ['published_time' => $time > 0 ? gmdate('Y-m-d H:i:s', $time) : ''],
				$published
			));
			$qb->method('executeQuery')->willReturn($result);

			return $qb;
		});
		$this->marked = [];
		$connection = $this->request([]);
		(new \ReflectionProperty(CoreRequestBuilder::class, 'dbConnection'))->setValue(
			$request,
			(new \ReflectionProperty(CoreRequestBuilder::class, 'dbConnection'))->getValue($connection)
		);

		$request->markTrendHoursOf(['a', 'b', 'c', 'd', 'e']);

		$this->assertSame(
			[
				[CoreRequestBuilder::TABLE_TAG_HOURS_DIRTY, ['hour_start' => $hour]],
				[CoreRequestBuilder::TABLE_TAG_HOURS_DIRTY, ['hour_start' => $hour + 3600]],
			],
			$this->marked,
			'five posts in two hours, and one with no time: two marks'
		);
	}
}
