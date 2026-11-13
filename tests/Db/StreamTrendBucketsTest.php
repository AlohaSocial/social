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
		$connection = new class($marked) extends FakeConnection {
			/** @param list<array{string, array}> $marked */
			public function __construct(
				private array &$marked,
			) {
				parent::__construct();
			}

			public function insertIgnoreConflict(string $table, array $values): int {
				$this->marked[] = [$table, $values];

				return 1;
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
}
