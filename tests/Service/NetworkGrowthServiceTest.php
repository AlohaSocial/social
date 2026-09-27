<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\NetworkGrowthService;
use OCA\Social\Service\NetworkStatsService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Whether the network is growing.
 *
 * The figures beside this say how big the fediverse is today, and a single
 * number cannot answer the thing somebody deciding whether to write here
 * actually wants to know. What is asserted is mostly the honesty of the
 * answer: that it is a second source and says so, that one point is not drawn
 * as a trend, and that a year-over-year figure is absent rather than computed
 * over eight months.
 */
class NetworkGrowthServiceTest extends TestCase {
	private CurlService|Stub $curlService;
	private ConfigService|Stub $configService;
	private NetworkGrowthService $service;

	/** what the survey answers with, or a Throwable it raises */
	private mixed $answer = null;
	/** the `network_stats` app value */
	private string $configured = '';
	/** how many requests were made */
	private int $asked = 0;
	/** what the request was */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();

		$this->curlService = $this->createStub(CurlService::class);
		$this->curlService->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url, array $options): array {
				$this->asked++;
				$this->sent = ['method' => $method, 'url' => $url, 'options' => $options];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return is_array($this->answer) ? $this->answer : [];
			});

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string
				=> ($key === NetworkStatsService::CONFIG_KEY) ? $this->configured : '');

		$this->service = $this->build($this->createStub(ICache::class));
	}

	private function build(ICache|MockObject $cache): NetworkGrowthService {
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new NetworkGrowthService(
			$this->curlService, $this->configService, new NullLogger(), $factory
		);
	}

	/**
	 * The series in the shape Fediverse Observer sends it: newest first, with
	 * a full timestamp rather than a month.
	 *
	 * @param int $months how many to make
	 */
	private function series(int $months = 13, float $step = 1.1): array {
		$rows = [];
		for ($i = 0; $i < $months; $i++) {
			$scale = $step ** ($months - 1 - $i);
			$rows[] = [
				'total_servers' => (int)round(40000 * $scale),
				'total_users' => (int)round(30000000 * $scale),
				'total_active_users_monthly' => (int)round(1000000 * $scale),
				'total_posts' => (int)round(1500000000 * $scale),
				'date_checked' => sprintf('2026-%02d-01 01:01:01', 12 - $i),
			];
		}

		return ['data' => ['monthlystats' => $rows]];
	}

	public function testTheSeriesComesBackOldestFirstBecauseThatIsHowAChartIsDrawn(): void {
		$this->answer = $this->series(3);

		$months = $this->service->growth()['months'];

		$this->assertSame(['2026-10', '2026-11', '2026-12'], array_column($months, 'month'));
		$this->assertLessThan($months[2]['accounts'], $months[0]['accounts']);
	}

	public function testWhatChangedOverTheLastMonthAndTheLastYear(): void {
		$this->answer = $this->series(13, 1.1);

		$change = $this->service->growth()['change'];

		// a tenth more each month, so a tenth over the month and rather more
		// over the year
		$this->assertEqualsWithDelta(10.0, $change['month']['accounts'], 0.2);
		$this->assertGreaterThan(100.0, $change['year']['accounts']);
	}

	/** A year-over-year figure computed over eight months has a wrong name on it. */
	public function testAYearIsAbsentWhereTheSeriesDoesNotReachBackThatFar(): void {
		$this->answer = $this->series(4);

		$change = $this->service->growth()['change'];

		$this->assertNotSame([], $change['month']);
		$this->assertSame([], $change['year']);
	}

	/** One point is not a trend, and drawing it as one claims something untrue. */
	public function testOneMonthIsNotGrowth(): void {
		$this->answer = $this->series(1);

		$this->assertNull($this->service->growth());
	}

	public function testTheSourceIsNamedBecauseItIsNotTheOneAbove(): void {
		$this->answer = $this->series(3);

		$growth = $this->service->growth();

		$this->assertSame('Fediverse Observer', $growth['source']);
		$this->assertSame('https://fediverse.observer', $growth['source_url']);
	}

	public function testTheSameAppValueSwitchesItOff(): void {
		$this->configured = '0';
		$this->answer = $this->series(3);

		$this->assertNull($this->service->growth());
		$this->assertSame(0, $this->asked, 'the request was made anyway');
	}

	public function testASourceThatDoesNotAnswerLeavesTheSectionOut(): void {
		$this->answer = new RuntimeException('down');

		$this->assertNull($this->service->growth());
	}

	public function testAnAnswerThatIsNotASeriesIsRefused(): void {
		$this->answer = ['data' => ['monthlystats' => []]];

		$this->assertNull($this->service->growth());
	}

	/**
	 * A month the crawler has no answer for is not a month the fediverse did
	 * not exist, and drawing it as zero would be a cliff in the chart.
	 */
	public function testAMonthWithNoServersInItIsDroppedRatherThanDrawnAsACliff(): void {
		$series = $this->series(3);
		$series['data']['monthlystats'][1]['total_servers'] = 0;
		$this->answer = $series;

		$months = $this->service->growth()['months'];

		$this->assertSame(['2026-10', '2026-12'], array_column($months, 'month'));
	}

	public function testItAsksTheGraphqlEndpointForWhatItReadsAndNothingElse(): void {
		$this->answer = $this->series(3);

		$this->service->growth();

		$this->assertSame('post', $this->sent['method']);
		$this->assertSame('https://api.fediverse.observer/', $this->sent['url']);
		$this->assertStringContainsString('monthlystats', $this->sent['options']['body']);
		$this->assertStringContainsString('total_servers', $this->sent['options']['body']);
		$this->assertStringNotContainsString('nodes', $this->sent['options']['body']);
	}

	public function testTheSeriesIsKeptRatherThanFetchedForEveryReader(): void {
		$this->answer = $this->series(3);
		$kept = [];
		$cache = $this->createStub(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, $value) use (&$kept): bool {
			$kept[$key] = $value;

			return true;
		});
		// by reference: an arrow function captures `$kept` as it was when the
		// closure was made, which is empty
		$cache->method('get')->willReturnCallback(function (string $key) use (&$kept) {
			return is_string($kept[$key] ?? null) ? $kept[$key] : null;
		});
		$this->service = $this->build($cache);

		$first = $this->service->growth();
		$second = $this->service->growth();

		$this->assertSame($first, $second);
		$this->assertSame(1, $this->asked);
	}

	/** Two years of it: enough for a shape, short enough to have one. */
	public function testAtMostTwoYearsAreDrawn(): void {
		$this->answer = ['data' => ['monthlystats' => array_map(
			static fn (int $i): array => [
				'total_servers' => 40000 + $i,
				'total_users' => 30000000 + $i,
				'total_active_users_monthly' => 1000000,
				'total_posts' => 1500000000,
				'date_checked' => sprintf('%04d-%02d-01 01:01:01', 2019 + intdiv($i, 12), ($i % 12) + 1),
			],
			range(0, 59)
		)]];

		$months = $this->service->growth()['months'];

		$this->assertCount(NetworkGrowthService::MONTHS, $months);
	}

	/**
	 * Observer's own series shows it plainly: 25,000 servers in January 2026
	 * and 41,000 in March, 20 million accounts in December and 36 million in
	 * February. Fifteen million people did not join the fediverse that
	 * quarter — the crawler reached servers it had not reached before, and a
	 * comparison across that step measures the survey rather than the network.
	 */
	public function testAYearAcrossACoverageChangeIsWithheldRatherThanPrinted(): void {
		$series = $this->series(13, 1.0);
		// one month where the crawler doubled what it reached
		$series['data']['monthlystats'][6]['total_servers'] *= 2;
		$series['data']['monthlystats'][6]['total_users'] *= 2;
		$this->answer = $series;

		$growth = $this->service->growth();

		$this->assertSame([], $growth['change']['year']);
		$this->assertTrue($growth['coverage_changed'], 'the page has nothing to explain the gap with');
	}

	public function testASteadySeriesStillGetsItsYear(): void {
		$this->answer = $this->series(13, 1.02);

		$growth = $this->service->growth();

		$this->assertNotSame([], $growth['change']['year']);
		$this->assertFalse($growth['coverage_changed']);
	}

	/**
	 * Only the two figures that move with coverage are held to it: the active
	 * and post counts swing for ordinary reasons, and holding them to this
	 * would withhold a comparison over a busy month.
	 */
	public function testABusyMonthIsNotACoverageChange(): void {
		$series = $this->series(13, 1.0);
		$series['data']['monthlystats'][6]['total_active_users_monthly'] *= 3;
		$series['data']['monthlystats'][6]['total_posts'] *= 3;
		$this->answer = $series;

		$this->assertFalse($this->service->growth()['coverage_changed']);
	}
}
