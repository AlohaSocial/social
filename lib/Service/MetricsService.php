<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\InvalidResourceException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * What the instance has been doing, as Mastodon's admin metrics ask it.
 *
 * Three shapes, and they are Mastodon's: a **measure** is one number a day
 * over a window, a **dimension** is a ranked list of the things behind one
 * number, and **retention** is how much of each month's new accounts is still
 * posting later. A moderation client draws all three, and until now asked for
 * each of them and got a 404.
 *
 * ### Which keys exist here, and which do not
 *
 * A key this instance cannot answer is **refused**, not answered with zeroes.
 * Mastodon's list is written for a server that owns its sign-up, its email
 * and its own media store; several of its keys describe things this app has
 * no row for. Answering `0` to "how many accounts signed up through an
 * invite" reads as "none did", which is a different claim from "this instance
 * has no invites", and it is the reading an admin acts on.
 *
 * ### Why the SQL is in here
 *
 * Same reason as `AdminApiService`: these counts are asked nowhere else, and
 * they are written against `IDBConnection` in `protected` methods so that what
 * decides which question gets asked can be tested without a database — the
 * standalone suite cannot even mock `IQueryBuilder`.
 */
class MetricsService {
	/** A day, which is the grain Mastodon's measures are drawn at. */
	private const DAY = 86400;

	/** How many days one call may span. Mastodon's own charts show a month. */
	public const MAX_DAYS = 370;

	/** The measures this instance can answer instance-wide. */
	public const MEASURE_ACTIVE_USERS = 'active_users';
	public const MEASURE_NEW_USERS = 'new_users';
	public const MEASURE_INTERACTIONS = 'interactions';
	public const MEASURE_OPENED_REPORTS = 'opened_reports';
	public const MEASURE_RESOLVED_REPORTS = 'resolved_reports';

	/** The measures that describe one other instance, and need `instance`. */
	public const MEASURE_INSTANCE_ACCOUNTS = 'instance_accounts';
	public const MEASURE_INSTANCE_STATUSES = 'instance_statuses';
	public const MEASURE_INSTANCE_REPORTS = 'instance_reports';

	/** The measures that describe one hashtag, and need `id`. */
	public const MEASURE_TAG_USES = 'tag_uses';
	public const MEASURE_TAG_ACCOUNTS = 'tag_accounts';

	public const MEASURES = [
		self::MEASURE_ACTIVE_USERS,
		self::MEASURE_NEW_USERS,
		self::MEASURE_INTERACTIONS,
		self::MEASURE_OPENED_REPORTS,
		self::MEASURE_RESOLVED_REPORTS,
		self::MEASURE_INSTANCE_ACCOUNTS,
		self::MEASURE_INSTANCE_STATUSES,
		self::MEASURE_INSTANCE_REPORTS,
		self::MEASURE_TAG_USES,
		self::MEASURE_TAG_ACCOUNTS,
	];

	/** The dimensions this instance can answer. */
	public const DIMENSION_SERVERS = 'servers';
	public const DIMENSION_TAG_SERVERS = 'tag_servers';
	public const DIMENSION_SOFTWARE_VERSIONS = 'software_versions';
	public const DIMENSIONS = [
		self::DIMENSION_SERVERS,
		self::DIMENSION_TAG_SERVERS,
		self::DIMENSION_SOFTWARE_VERSIONS,
	];

	public function __construct(
		private IDBConnection $dbConnection,
		private ConfigService $configService,
	) {
	}

	/**
	 * One measure a key, each as Mastodon's `Admin::Measure`.
	 *
	 * `previous_total` is the same span ending where this one starts, which is
	 * the comparison the charts draw the arrow from.
	 *
	 * @param string[] $keys
	 *
	 * @throws InvalidResourceException a key this instance cannot answer, or a
	 *                                  window it will not read
	 */
	public function measures(
		array $keys, int $startAt, int $endAt, string $instance = '', string $tag = '',
	): array {
		[$startAt, $endAt] = $this->assertWindow($startAt, $endAt);
		$span = $endAt - $startAt;

		$measures = [];
		foreach ($keys as $key) {
			$key = (string)$key;
			if (!in_array($key, self::MEASURES, true)) {
				throw new InvalidResourceException($this->whyNot($key, self::MEASURES));
			}

			$daily = $this->measureDaily($key, $startAt, $endAt, $instance, $tag);
			$previous = $this->measureDaily($key, $startAt - $span, $startAt, $instance, $tag);

			$measures[] = [
				'key' => $key,
				'unit' => null,
				'total' => (string)array_sum(array_column($daily, 'value')),
				'previous_total' => (string)array_sum(array_column($previous, 'value')),
				'data' => array_map(
					static fn (array $point): array => [
						'date' => gmdate('Y-m-d', $point['day']) . 'T00:00:00.000Z',
						'value' => (string)$point['value'],
					],
					$daily
				),
			];
		}

		return $measures;
	}

	/**
	 * One dimension a key, each as Mastodon's `Admin::Dimension`.
	 *
	 * @param string[] $keys
	 *
	 * @throws InvalidResourceException
	 */
	public function dimensions(
		array $keys, int $startAt, int $endAt, int $limit = 10, string $tag = '',
	): array {
		[$startAt, $endAt] = $this->assertWindow($startAt, $endAt);
		$limit = max(1, min(100, $limit));

		$dimensions = [];
		foreach ($keys as $key) {
			$key = (string)$key;
			if (!in_array($key, self::DIMENSIONS, true)) {
				throw new InvalidResourceException($this->whyNot($key, self::DIMENSIONS));
			}

			$dimensions[] = ['key' => $key, 'data' => $this->dimensionData($key, $startAt, $endAt, $limit, $tag)];
		}

		return $dimensions;
	}

	/**
	 * How much of each cohort of new local accounts is still posting later.
	 *
	 * Mastodon's `Admin::Cohort`, monthly: one row per month of sign-ups, and
	 * within it one value per month since, counting the accounts of that
	 * cohort that posted in it. `rate` is that count over the size of the
	 * cohort, so the first value of every row is 1 or 0 — an account that
	 * never posted at all is in the cohort and in none of its buckets.
	 *
	 * @throws InvalidResourceException
	 */
	public function retention(int $startAt, int $endAt): array {
		[$startAt, $endAt] = $this->assertWindow($startAt, $endAt);

		$cohorts = [];
		foreach ($this->monthsBetween($startAt, $endAt) as $month) {
			$monthEnd = strtotime('+1 month', $month);
			$size = count($this->localActorsCreatedBetween($month, $monthEnd));
			$data = [];

			foreach ($this->monthsBetween($month, $endAt) as $since) {
				$active = $this->localActorsCreatedBetween($month, $monthEnd, $since, strtotime('+1 month', $since));
				$data[] = [
					'date' => gmdate('Y-m-d', $since) . 'T00:00:00.000Z',
					'rate' => $size === 0 ? 0.0 : round(count($active) / $size, 4),
					'value' => (string)count($active),
				];
			}

			$cohorts[] = [
				'period' => gmdate('Y-m-d', $month) . 'T00:00:00.000Z',
				'frequency' => 'month',
				'data' => $data,
			];
		}

		return $cohorts;
	}

	/**
	 * Why a key was refused, naming what this instance does have.
	 *
	 * The list is the answer: a client that asked for something Mastodon
	 * documents deserves to be told which of its keys this server can draw,
	 * rather than a bare "unknown".
	 *
	 * @param string[] $known
	 */
	private function whyNot(string $key, array $known): string {
		return 'this instance cannot answer "' . $key . '". It can answer: '
			. implode(', ', $known) . '. The rest of Mastodon\'s keys describe a sign-up, '
			. 'an invite system, an email address or a media store this app does not own — '
			. 'and answering 0 would read as "none", which is a different claim.';
	}

	/**
	 * @throws InvalidResourceException
	 *
	 * @return array{0: int, 1: int} the window, snapped to whole days
	 */
	private function assertWindow(int $startAt, int $endAt): array {
		if ($startAt <= 0 || $endAt <= 0 || $endAt <= $startAt) {
			throw new InvalidResourceException('start_at must be before end_at, and both must be dates');
		}

		// snapped so that every point of every measure names the same day,
		// whatever hour the client asked from
		$startAt = intdiv($startAt, self::DAY) * self::DAY;
		$endAt = intdiv($endAt + self::DAY - 1, self::DAY) * self::DAY;

		if (($endAt - $startAt) / self::DAY > self::MAX_DAYS) {
			throw new InvalidResourceException(
				'a window of at most ' . self::MAX_DAYS . ' days, please'
			);
		}

		return [$startAt, $endAt];
	}

	/**
	 * One measure, a value a day.
	 *
	 * @return array<int, array{day: int, value: int}>
	 */
	private function measureDaily(
		string $key, int $startAt, int $endAt, string $instance, string $tag,
	): array {
		$counts = match ($key) {
			self::MEASURE_ACTIVE_USERS => $this->activeUsersPerDay($startAt, $endAt),
			self::MEASURE_NEW_USERS => $this->newUsersPerDay($startAt, $endAt),
			self::MEASURE_INTERACTIONS => $this->interactionsPerDay($startAt, $endAt),
			self::MEASURE_OPENED_REPORTS => $this->reportsPerDay($startAt, $endAt, 'creation'),
			self::MEASURE_RESOLVED_REPORTS => $this->reportsPerDay($startAt, $endAt, 'action_taken_at'),
			self::MEASURE_INSTANCE_ACCOUNTS => $this->instanceAccountsPerDay($startAt, $endAt, $instance),
			self::MEASURE_INSTANCE_STATUSES => $this->instanceStatusesPerDay($startAt, $endAt, $instance),
			self::MEASURE_INSTANCE_REPORTS => $this->instanceReportsPerDay($startAt, $endAt, $instance),
			self::MEASURE_TAG_USES => $this->tagUsesPerDay($startAt, $endAt, $tag),
			self::MEASURE_TAG_ACCOUNTS => $this->tagAccountsPerDay($startAt, $endAt, $tag),
			default => [],
		};

		// every day in the window, whether or not anything happened on it: a
		// chart with holes in it is a chart nobody can read
		$daily = [];
		for ($day = $startAt; $day < $endAt; $day += self::DAY) {
			$daily[] = ['day' => $day, 'value' => $counts[gmdate('Y-m-d', $day)] ?? 0];
		}

		return $daily;
	}

	/** @return array<int, array{key: string, human_key: string, value: string}> */
	private function dimensionData(string $key, int $startAt, int $endAt, int $limit, string $tag): array {
		return match ($key) {
			self::DIMENSION_SERVERS => $this->ranked($this->serversByStatuses($startAt, $endAt, $limit)),
			self::DIMENSION_TAG_SERVERS => $this->ranked($this->serversByTag($startAt, $endAt, $limit, $tag)),
			self::DIMENSION_SOFTWARE_VERSIONS => $this->softwareVersions(),
			default => [],
		};
	}

	/**
	 * @param array<string, int> $counts
	 *
	 * @return array<int, array{key: string, human_key: string, value: string}>
	 */
	private function ranked(array $counts): array {
		$data = [];
		foreach ($counts as $name => $value) {
			$data[] = ['key' => (string)$name, 'human_key' => (string)$name, 'value' => (string)$value];
		}

		return $data;
	}

	/**
	 * What this instance runs on.
	 *
	 * Mastodon lists the versions of its own dependencies here; the honest
	 * equivalent is the app, the server it is an app of, and the two things
	 * underneath that an admin would be asked for in a bug report.
	 *
	 * @return array<int, array{key: string, human_key: string, value: string}>
	 */
	private function softwareVersions(): array {
		return [
			[
				'key' => 'social',
				'human_key' => 'Aloha Social',
				'value' => (string)$this->configService->getAppValue('installed_version'),
			],
			[
				'key' => 'nextcloud',
				'human_key' => 'Nextcloud',
				'value' => (string)$this->configService->getSystemValue('version'),
			],
			['key' => 'php', 'human_key' => 'PHP', 'value' => PHP_VERSION],
			[
				'key' => 'database',
				'human_key' => 'Database',
				'value' => $this->databaseProvider(),
			],
		];
	}

	/** Which database this is running on, as an admin would be asked in a bug report. */
	protected function databaseProvider(): string {
		return (string)$this->dbConnection->getDatabaseProvider();
	}

	/** @return int[] the first second of each month the window touches */
	private function monthsBetween(int $startAt, int $endAt): array {
		$months = [];
		$month = (int)strtotime(gmdate('Y-m-01\T00:00:00\Z', $startAt));
		while ($month < $endAt) {
			$months[] = $month;
			$month = (int)strtotime('+1 month', $month);
		}

		return $months;
	}

	// the queries

	/**
	 * Distinct local authors who posted on each day.
	 *
	 * "Active" as Mastodon counts it, which is not "logged in": this app has
	 * no session of its own to count, and posting is the thing it can see.
	 *
	 * @return array<string, int> Y-m-d => how many
	 */
	protected function activeUsersPerDay(int $startAt, int $endAt): array {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->selectDistinct(['attributed_to_prim', 'published_time'])
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->eq('local', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->gte('published_time', $this->date($qb, $startAt)))
			->andWhere($qb->expr()->lt('published_time', $this->date($qb, $endAt)));

		$seen = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$day = gmdate('Y-m-d', (int)strtotime((string)$row['published_time']));
			$seen[$day][(string)$row['attributed_to_prim']] = true;
		}
		$cursor->closeCursor();

		return array_map('count', $seen);
	}

	/** @return array<string, int> */
	protected function newUsersPerDay(int $startAt, int $endAt): array {
		return $this->countByDay(CoreRequestBuilder::TABLE_ACTORS, 'creation', $startAt, $endAt);
	}

	/** @return array<string, int> */
	protected function interactionsPerDay(int $startAt, int $endAt): array {
		return $this->countByDay(CoreRequestBuilder::TABLE_ACTIONS, 'creation', $startAt, $endAt);
	}

	/** @return array<string, int> */
	protected function reportsPerDay(int $startAt, int $endAt, string $column): array {
		return $this->countByDay(CoreRequestBuilder::TABLE_REPORTS, $column, $startAt, $endAt);
	}

	/** @return array<string, int> */
	protected function instanceAccountsPerDay(int $startAt, int $endAt, string $instance): array {
		return $this->countByDay(
			CoreRequestBuilder::TABLE_CACHE_ACTORS, 'creation', $startAt, $endAt,
			'account', '%@' . $this->escapeLike($this->assertInstance($instance))
		);
	}

	/** @return array<string, int> */
	protected function instanceStatusesPerDay(int $startAt, int $endAt, string $instance): array {
		return $this->countByDay(
			CoreRequestBuilder::TABLE_STREAM, 'published_time', $startAt, $endAt,
			'attributed_to', '%://' . $this->escapeLike($this->assertInstance($instance)) . '/%'
		);
	}

	/** @return array<string, int> */
	protected function instanceReportsPerDay(int $startAt, int $endAt, string $instance): array {
		return $this->countByDay(
			CoreRequestBuilder::TABLE_REPORTS, 'creation', $startAt, $endAt,
			'account_id', '%://' . $this->escapeLike($this->assertInstance($instance)) . '/%'
		);
	}

	/** @return array<string, int> */
	protected function tagUsesPerDay(int $startAt, int $endAt, string $tag): array {
		$qb = $this->tagQuery($startAt, $endAt, $tag);
		$qb->select('s.published_time');

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$day = gmdate('Y-m-d', (int)strtotime((string)$row['published_time']));
			$counts[$day] = ($counts[$day] ?? 0) + 1;
		}
		$cursor->closeCursor();

		return $counts;
	}

	/** @return array<string, int> */
	protected function tagAccountsPerDay(int $startAt, int $endAt, string $tag): array {
		$qb = $this->tagQuery($startAt, $endAt, $tag);
		$qb->select('s.published_time', 's.attributed_to_prim');

		$seen = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$day = gmdate('Y-m-d', (int)strtotime((string)$row['published_time']));
			$seen[$day][(string)$row['attributed_to_prim']] = true;
		}
		$cursor->closeCursor();

		return array_map('count', $seen);
	}

	/**
	 * The instances whose accounts posted most in the window.
	 *
	 * The host is read off the author's id in PHP rather than in SQL: there is
	 * no host column, and four databases do not agree on how to cut one out of
	 * a string. The set is one window of posts, which is what the chart is
	 * about.
	 *
	 * @return array<string, int>
	 */
	protected function serversByStatuses(int $startAt, int $endAt, int $limit): array {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('attributed_to')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->gte('published_time', $this->date($qb, $startAt)))
			->andWhere($qb->expr()->lt('published_time', $this->date($qb, $endAt)));

		return $this->topHosts($qb, 'attributed_to', $limit);
	}

	/** @return array<string, int> */
	protected function serversByTag(int $startAt, int $endAt, int $limit, string $tag): array {
		$qb = $this->tagQuery($startAt, $endAt, $tag);
		$qb->select('s.attributed_to');

		return $this->topHosts($qb, 'attributed_to', $limit);
	}

	/**
	 * The local accounts of a cohort that posted in a period — or the whole
	 * cohort, when no period is given.
	 *
	 * @return string[] actor ids
	 */
	protected function localActorsCreatedBetween(
		int $cohortStart, int $cohortEnd, ?int $activeFrom = null, ?int $activeTo = null,
	): array {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->selectDistinct('a.id')
			->from(CoreRequestBuilder::TABLE_ACTORS, 'a')
			->where($qb->expr()->gte('a.creation', $this->date($qb, $cohortStart)))
			->andWhere($qb->expr()->lt('a.creation', $this->date($qb, $cohortEnd)));

		if ($activeFrom !== null) {
			$qb->innerJoin(
				'a', CoreRequestBuilder::TABLE_STREAM, 's',
				$qb->expr()->eq('s.attributed_to_prim', 'a.id_prim')
			);
			$qb->andWhere($qb->expr()->gte('s.published_time', $this->date($qb, $activeFrom)))
				->andWhere($qb->expr()->lt('s.published_time', $this->date($qb, (int)$activeTo)));
		}

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$ids[] = (string)$row['id'];
		}
		$cursor->closeCursor();

		return $ids;
	}

	/**
	 * Rows of one table, counted by the day one of its date columns names.
	 *
	 * @return array<string, int> Y-m-d => how many
	 */
	private function countByDay(
		string $table, string $column, int $startAt, int $endAt,
		string $likeColumn = '', string $like = '',
	): array {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select($column)
			->from($table)
			->where($qb->expr()->gte($column, $this->date($qb, $startAt)))
			->andWhere($qb->expr()->lt($column, $this->date($qb, $endAt)));

		if ($likeColumn !== '') {
			$qb->andWhere($qb->expr()->like($likeColumn, $qb->createNamedParameter($like)));
		}

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$day = gmdate('Y-m-d', (int)strtotime((string)$row[$column]));
			$counts[$day] = ($counts[$day] ?? 0) + 1;
		}
		$cursor->closeCursor();

		return $counts;
	}

	/** The posts carrying one hashtag in one window. */
	private function tagQuery(int $startAt, int $endAt, string $tag): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->from(CoreRequestBuilder::TABLE_STREAM_TAGS, 't')
			->innerJoin('t', CoreRequestBuilder::TABLE_STREAM, 's', $qb->expr()->eq('s.id_prim', 't.stream_id'))
			->where($qb->expr()->eq('t.hashtag', $qb->createNamedParameter($this->assertTag($tag))))
			->andWhere($qb->expr()->gte('s.published_time', $this->date($qb, $startAt)))
			->andWhere($qb->expr()->lt('s.published_time', $this->date($qb, $endAt)));

		return $qb;
	}

	/**
	 * The commonest hosts among the ids one column of a query returns.
	 *
	 * @return array<string, int>
	 */
	private function topHosts(IQueryBuilder $qb, string $column, int $limit): array {
		$hosts = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$host = strtolower((string)parse_url((string)$row[$column], PHP_URL_HOST));
			if ($host !== '') {
				$hosts[$host] = ($hosts[$host] ?? 0) + 1;
			}
		}
		$cursor->closeCursor();

		arsort($hosts);

		return array_slice($hosts, 0, $limit, true);
	}

	/**
	 * The instance a scoped measure is about.
	 *
	 * Looser than the email-domain check, on purpose: a fediverse host may be
	 * a single label with no dot in it — every instance on an internal network
	 * is — and refusing those would leave an admin unable to ask about the
	 * server sitting next to this one.
	 *
	 * @throws InvalidResourceException
	 */
	private function assertInstance(string $instance): string {
		$instance = rtrim(strtolower(trim($instance)), '.');
		if ($instance === '' || preg_match('/^[a-z0-9.:\[\]-]+$/', $instance) !== 1) {
			throw new InvalidResourceException(
				'this measure describes one instance; name it in "instance"'
			);
		}

		return $instance;
	}

	/** @throws InvalidResourceException */
	private function assertTag(string $tag): string {
		$tag = strtolower(ltrim(trim($tag), '#'));
		if ($tag === '') {
			throw new InvalidResourceException('this measure describes one hashtag; name it in "id"');
		}

		return $tag;
	}

	/**
	 * A moment as a bound parameter.
	 *
	 * The return is whatever `createNamedParameter()` hands back — a
	 * `Parameter` on the real query builder, a string on some others — so it
	 * is left untyped rather than narrowed to the one the doubles return.
	 */
	private function date(IQueryBuilder $qb, int $timestamp) {
		return $qb->createNamedParameter(
			(new \DateTime())->setTimestamp($timestamp), IQueryBuilder::PARAM_DATE
		);
	}

	/** `%`, `_` and the escape itself are literals in a hostname. */
	private function escapeLike(string $value): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
	}
}
