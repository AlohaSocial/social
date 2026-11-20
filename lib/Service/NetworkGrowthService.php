<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether the fediverse is growing, and how fast, from Fediverse Observer's
 * monthly series (FediDB has no history).
 *
 * The two surveys disagree, so numbers are never mixed: current figures stay
 * FediDB's (`NetworkStatsService`), the series stays Observer's, each named.
 * Cached for a day, and switched off by the same app value as the figures.
 */
class NetworkGrowthService {
	public const SOURCE_NAME = 'Fediverse Observer';
	public const SOURCE_URL = 'https://fediverse.observer';
	private const ENDPOINT = 'https://api.fediverse.observer/';

	/**
	 * How many months the page shows: enough for a year-over-year comparison
	 * without the 2022 spike flattening everything since.
	 */
	public const MONTHS = 24;

	/**
	 * A month-over-month step (in percent) this large is the survey changing
	 * what it crawls, not the network (e.g. 25,000 to 41,000 servers between
	 * January and March 2026), so no comparison across it is printed.
	 */
	private const COVERAGE_STEP = 25.0;

	/** A monthly series changes monthly. */
	private const CACHE_TTL = 86400;
	/** And this long after a failure, so a source that is down is left alone. */
	private const FAILURE_TTL = 3600;

	private const TIMEOUT = 6;

	private ICache $cache;

	public function __construct(
		private CurlService $curlService,
		private ConfigService $configService,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('social.network');
	}

	/**
	 * The last two years, oldest first (chart order), with what changed over the
	 * last month and the last year.
	 *
	 * @return array{
	 *     months: list<array{month: string, servers: int, accounts: int, active: int, posts: int}>,
	 *     change: array{month: array<string, float>, year: array<string, float>},
	 *     coverage_changed: bool, source: string, source_url: string
	 * }|null null when it is switched off, or nobody answered
	 */
	public function growth(): ?array {
		if (trim((string)$this->configService->getAppValue(NetworkStatsService::CONFIG_KEY)) === '0') {
			return null;
		}

		$months = $this->months();
		if ($months === null || count($months) < 2) {
			// One point is not a trend.
			return null;
		}

		$year = $this->changeOver($months, 12);
		$steady = $this->steadyOver($months, 12);

		return [
			'months' => $months,
			'change' => [
				'month' => $this->changeOver($months, 1),
				// Withheld across a coverage change, which measures the survey.
				'year' => $steady ? $year : [],
			],
			// So the page can say why the year is missing.
			'coverage_changed' => !$steady,
			'source' => self::SOURCE_NAME,
			'source_url' => self::SOURCE_URL,
		];
	}

	/**
	 * The series, remembered.
	 *
	 * @return list<array{month: string, servers: int, accounts: int, active: int, posts: int}>|null
	 */
	private function months(): ?array {
		$cached = $this->cache->get('growth');
		if (is_string($cached)) {
			$decoded = json_decode($cached, true);

			return (is_array($decoded) && $decoded !== []) ? $decoded : null;
		}

		try {
			$answer = $this->curlService->retrieveJson(
				'post',
				self::ENDPOINT,
				[
					'timeout' => self::TIMEOUT,
					'json_headers' => false,
					'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
					'body' => (string)json_encode(['query' => $this->query()]),
				]
			);
		} catch (Throwable $e) {
			$this->logger->debug('[NetworkGrowthService] the series did not answer', ['exception' => $e]);
			$this->cache->set('growth', '[]', self::FAILURE_TTL);

			return null;
		}

		$months = $this->shape($answer);
		$this->cache->set(
			'growth',
			(string)json_encode($months ?? []),
			($months === null) ? self::FAILURE_TTL : self::CACHE_TTL
		);

		return $months;
	}

	/** Everything this app reads, and nothing else. */
	private function query(): string {
		return '{ monthlystats(softwarename: "all") { total_users total_servers total_posts '
			. 'total_active_users_monthly date_checked } }';
	}

	/**
	 * What this app reads out of the answer, turned to oldest first. A month with
	 * no servers is one the crawler has no answer for, and is dropped.
	 *
	 * @param array<string, mixed> $answer
	 *
	 * @return list<array{month: string, servers: int, accounts: int, active: int, posts: int}>|null
	 */
	private function shape(array $answer): ?array {
		$rows = $answer['data']['monthlystats'] ?? null;
		if (!is_array($rows) || $rows === []) {
			return null;
		}

		$months = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$servers = (int)($row['total_servers'] ?? 0);
			$month = substr((string)($row['date_checked'] ?? ''), 0, 7);
			if ($servers < 1 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
				continue;
			}

			$months[$month] = [
				'month' => $month,
				'servers' => $servers,
				'accounts' => (int)($row['total_users'] ?? 0),
				'active' => (int)($row['total_active_users_monthly'] ?? 0),
				'posts' => (int)($row['total_posts'] ?? 0),
			];
		}

		if ($months === []) {
			return null;
		}

		ksort($months);

		return array_slice(array_values($months), -self::MONTHS);
	}

	/**
	 * Whether the series over this window is the same survey throughout (see
	 * `COVERAGE_STEP`). Only servers and accounts are checked; active and post
	 * counts swing for ordinary reasons.
	 *
	 * @param list<array{month: string, servers: int, accounts: int, active: int, posts: int}> $months
	 */
	private function steadyOver(array $months, int $back): bool {
		$last = count($months) - 1;
		$first = $last - $back;
		if ($first < 0) {
			return false;
		}

		for ($i = $first; $i < $last; $i++) {
			foreach (['servers', 'accounts'] as $key) {
				$was = (int)$months[$i][$key];
				$now = (int)$months[$i + 1][$key];
				if ($was > 0 && abs((float)($now - $was)) / (float)$was * 100.0 > self::COVERAGE_STEP) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * The fractional change between the last month and the one `$back` months
	 * before it; absent where the series does not reach back that far.
	 *
	 * @param list<array{month: string, servers: int, accounts: int, active: int, posts: int}> $months
	 *
	 * @return array<string, float>
	 */
	private function changeOver(array $months, int $back): array {
		$last = count($months) - 1;
		$first = $last - $back;
		if ($last < 0 || $first < 0) {
			return [];
		}

		$latest = $months[$last];
		$earlier = $months[$first];

		$change = [];
		foreach (['servers', 'accounts', 'active', 'posts'] as $key) {
			$was = (int)$earlier[$key];
			if ($was > 0) {
				$moved = (float)((int)$latest[$key] - $was);
				$change[$key] = round($moved / (float)$was * 100.0, 1);
			}
		}

		return $change;
	}
}
