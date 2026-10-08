<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Tools\IExtendedQueryBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * A queue's retry schedule: how long a row waits after its n-th failed
 * attempt, and after how many it is given up on.
 *
 * The wait is `tries^4 / divisor + offset` seconds, and nothing for a row
 * that has never failed. The query that reads a queue and anything in PHP
 * that asks how long a row waits both take it from here, so the two cannot
 * drift apart — when they did, a row the query handed out was dropped again
 * by PHP on every pass.
 */
final class Backoff {
	/** Outbound deliveries, `social_req_queue`; see `RequestQueueService::MAX_TRIES`. */
	public const OUTBOUND_MAX_TRIES = 16;

	/** Inbound resolution, `social_stream_queue`. */
	public const INBOUND_MAX_TRIES = 10;

	public function __construct(
		private int $divisor,
		private int $offset,
		private int $maxTries,
	) {
	}

	/** Mastodon's schedule (Sidekiq's `count^4 + 15`), about two days in all. */
	public static function outbound(): self {
		return new self(1, 15, self::OUTBOUND_MAX_TRIES);
	}

	/** `tries^4 / 3`, about an hour and a half over the ten attempts. */
	public static function inbound(): self {
		return new self(3, 0, self::INBOUND_MAX_TRIES);
	}

	/** The try count at which a row is given up on. */
	public function maxTries(): int {
		return $this->maxTries;
	}

	/**
	 * How long a row waits after its n-th failure, in seconds. `tries` is the
	 * count of failed attempts so far.
	 */
	public function delay(int $tries): int {
		if ($tries < 1) {
			return 0;
		}

		return intdiv($tries ** 4, $this->divisor) + $this->offset;
	}

	/**
	 * Limits a query to the rows that are due: the schedule and the give-up
	 * threshold, in SQL.
	 *
	 * The delay grows with the fourth power of the try count, which is not
	 * something a portable query can compute from the column, so it is
	 * unrolled into one branch per try count below the threshold, `tries = n
	 * AND (last IS NULL OR last <= now - delay(n))`; the threshold falls out of
	 * the same expression. A row that has never been attempted has a NULL
	 * `last`, and `last` is a DATETIME, so the cutoffs are bound as dates.
	 *
	 * @param string $prefix the table alias and dot, or '' outside a SELECT
	 */
	public function limitToDue(IExtendedQueryBuilder $qb, string $prefix, int $now): void {
		$expr = $qb->expr();

		// built first and passed in one go: an empty orX() is deprecated and
		// will throw
		$due = [];
		for ($tries = 0; $tries < $this->maxTries; $tries++) {
			$cutoff = new DateTime('@' . ($now - $this->delay($tries)));

			$due[] = $expr->andX(
				$expr->eq($prefix . 'tries', $qb->createNamedParameter($tries, IQueryBuilder::PARAM_INT)),
				$expr->orX(
					$expr->isNull($prefix . 'last'),
					$expr->lte($prefix . 'last', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE))
				)
			);
		}

		$qb->andWhere($expr->orX(...$due));
	}
}
