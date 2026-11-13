<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\RequestQueue;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Puts queued deliveries back in the queue, or takes them out of it.
 *
 * What `occ social:queue:retry` does, and the Retry button on the Deliveries
 * card of the admin page: both select the same rows and reset them the same
 * way.
 *
 * It reads and writes the queue tables directly rather than through the queue
 * services, in the same way `social:debug:benchmark` does — the services have
 * no "reset this row" operation, and inventing one in the delivery path would
 * put a footgun there.
 *
 * Both queue tables carry the same (id, token, status, tries) shape and the
 * same status numbering — StreamQueue::STATUS_* and RequestQueue::STATUS_*
 * are 0/1/9 in both — so one set of constants serves both.
 */
class QueueRetryService {
	/** How many rows one run touches unless told otherwise. */
	public const DEFAULT_LIMIT = 500;

	/**
	 * How many rows are read at a time while looking for one instance's.
	 *
	 * The host a delivery was for lives inside the JSON `instance` column, so
	 * an instance is matched in PHP — the same reason `FederationHealthService`
	 * groups by host in PHP. Reading the table in pages keeps that bounded: a
	 * queue with a hundred thousand rows for other servers costs page-sized
	 * reads, not one enormous one.
	 */
	private const SCAN_PAGE = 1000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * Queues every delivery to one host that has failed at least once —
	 * the ones given up on included — for a full run of retries again.
	 *
	 * @return array{queued: int, limited: bool} how many rows were queued,
	 *                                           and whether that stopped at the limit
	 */
	public function retryInstance(string $host, int $limit = self::DEFAULT_LIMIT): array {
		$host = strtolower(trim($host));
		if ($host === '') {
			throw new \InvalidArgumentException('no instance given');
		}

		$matched = $this->matching(CoreRequestBuilder::TABLE_REQUEST_QUEUE, '', 1, $limit, $host);
		$queued = ($matched === []) ? 0 : $this->retry(
			CoreRequestBuilder::TABLE_REQUEST_QUEUE, array_map('intval', array_column($matched, 'id'))
		);

		return ['queued' => $queued, 'limited' => count($matched) >= $limit];
	}

	/**
	 * The rows a run would act on.
	 *
	 * A successful row (status 9) is left alone: it is history, not a pending
	 * delivery, and re-queueing it would send the activity a second time. An
	 * abandoned one (status 8) is not — it is precisely what this is for, a
	 * delivery the drain has given up on and that nothing else will ever try
	 * again.
	 *
	 * @param string $host a hostname to restrict to, or '' for all of them
	 *
	 * @return list<array<string, mixed>>
	 */
	public function matching(string $table, string $token, int $minTries, int $limit, string $host = ''): array {
		if ($host === '') {
			return $this->page($table, $token, $minTries, $limit, 0, 'tries');
		}

		// paged by id rather than ordered by attempts: with the filter in PHP
		// the run has to be able to say where it got to, and `tries` is not
		// unique enough to resume on
		$matched = [];
		$after = 0;
		while (count($matched) < $limit) {
			$rows = $this->page($table, $token, $minTries, self::SCAN_PAGE, $after, 'id');
			if ($rows === []) {
				break;
			}

			$after = (int)$rows[count($rows) - 1]['id'];
			foreach ($rows as $row) {
				if (self::hostOf($row) !== $host) {
					continue;
				}

				$matched[] = $row;
				if (count($matched) >= $limit) {
					break;
				}
			}
		}

		return $matched;
	}

	/**
	 * The host one queued delivery was for, lower-cased, or '' when the row
	 * does not say — which is what a row written before the column held an
	 * uri, or one whose JSON cannot be read, looks like.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function hostOf(array $row): string {
		$instance = json_decode((string)($row['instance'] ?? ''), true);
		if (!is_array($instance) || !is_string($instance['uri'] ?? null)) {
			return '';
		}

		return strtolower(parse_url($instance['uri'], PHP_URL_HOST) ?: '');
	}

	/**
	 * Back to standby with the attempt count cleared, so the row gets the full
	 * run of retries again rather than being abandoned on its next failure.
	 *
	 * @param list<int> $ids
	 */
	public function retry(string $table, array $ids): int {
		$touched = 0;
		foreach (array_chunk($ids, 100) as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb->update($table)
				->set('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY, IQueryBuilder::PARAM_INT))
				->set('tries', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$touched += $qb->executeStatement();
		}

		return $touched;
	}

	/**
	 * @param list<int> $ids
	 */
	public function flush(string $table, array $ids): int {
		$touched = 0;
		foreach (array_chunk($ids, 100) as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$touched += $qb->executeStatement();
		}

		return $touched;
	}

	/**
	 * One page of candidate rows.
	 *
	 * @param string $order 'tries' for the worst first, 'id' to walk the table
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function page(string $table, string $token, int $minTries, int $limit, int $after, string $order): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'token', 'status', 'tries')
			->from($table)
			->andWhere($qb->expr()->neq(
				'status',
				$qb->createNamedParameter(RequestQueue::STATUS_SUCCESS, IQueryBuilder::PARAM_INT)
			))
			->setMaxResults($limit);

		if ($table === CoreRequestBuilder::TABLE_REQUEST_QUEUE) {
			// only the delivery queue knows what it was delivering to
			$qb->addSelect('instance');
		}

		if ($order === 'tries') {
			$qb->orderBy('tries', 'desc');
		}
		$qb->addOrderBy('id', 'asc');

		if ($after > 0) {
			$qb->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)));
		}

		if ($token !== '') {
			$qb->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		} else {
			$qb->andWhere($qb->expr()->gte(
				'tries',
				$qb->createNamedParameter($minTries, IQueryBuilder::PARAM_INT)
			));
		}

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return $rows;
	}
}
