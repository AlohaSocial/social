<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\RequestQueue;

/**
 * What the outbound queue looks like right now, in terms an administrator can
 * act on: what is still being retried and what was given up on, per host.
 */
class FederationHealthService {
	/** Only the worst offenders are worth a table row. */
	public const TOP_INSTANCES = 10;

	/** Past this many failures a request is on its last legs. */
	public const AT_RISK_TRIES = 8;

	/**
	 * A standby row last tried this long ago (seconds) is stuck; the retry
	 * schedule never waits more than fourteen hours.
	 */
	public const STALE_STANDBY_SECONDS = 24 * 3600;

	public function __construct(
		private RequestQueueRequest $requestQueueRequest,
	) {
	}

	/**
	 * @return array{
	 *     waiting: int,
	 *     running: int,
	 *     failing: int,
	 *     atRisk: int,
	 *     abandoned: int,
	 *     maxTries: int,
	 *     truncated: bool,
	 *     abandonedTruncated: bool,
	 *     retentionDays: int,
	 *     instances: list<array{host: string, requests: int, tries: int, last: int}>,
	 *     givenUp: list<array{host: string, requests: int, tries: int, last: int}>
	 * }
	 */
	public function summary(): array {
		$counts = $this->requestQueueRequest->countByStatus();
		$failing = $this->requestQueueRequest->getFailing();
		// The deliveries that are already over.
		$abandoned = $this->requestQueueRequest->getAbandoned();

		return [
			'waiting' => $counts[RequestQueue::STATUS_STANDBY] ?? 0,
			'running' => $counts[RequestQueue::STATUS_RUNNING] ?? 0,
			'failing' => count($failing),
			'atRisk' => count(array_filter(
				$failing,
				fn (RequestQueue $request): bool => $request->getTries() >= self::AT_RISK_TRIES
			)),
			'abandoned' => count($abandoned),
			'maxTries' => RequestQueueService::MAX_TRIES,
			// the read is capped, so a very sick instance reports "at least"
			'truncated' => count($failing) >= 500,
			'abandonedTruncated' => count($abandoned) >= 500,
			// Abandoned rows are purged after this, so their count is a window.
			'retentionDays' => (int)round(RequestQueueService::RETENTION_SECONDS / 86400),
			// When the longest-failing delivery was last tried: a rebooting peer or a
			// lost one.
			'stuckSince' => $this->requestQueueRequest->oldestFailingAttempt(),
			'instances' => $this->byInstance($failing),
			'givenUp' => $this->byInstance($abandoned),
		];
	}

	/**
	 * What in the queue will not move on its own: abandoned rows and standby
	 * rows overdue by a day. Both zero on a healthy instance (the setup check).
	 *
	 * @return array{abandoned: int, stale: int}
	 */
	public function stuck(): array {
		$counts = $this->requestQueueRequest->countByStatus();

		return [
			'abandoned' => $counts[RequestQueue::STATUS_ABANDONED] ?? 0,
			'stale' => $this->requestQueueRequest->countStandbyOlderThan(time() - self::STALE_STANDBY_SECONDS),
		];
	}

	/**
	 * Requests gathered per host, worst first, in PHP because the address is
	 * inside a JSON column.
	 *
	 * @param RequestQueue[] $failing
	 *
	 * @return list<array{host: string, requests: int, tries: int, last: int}>
	 */
	private function byInstance(array $failing): array {
		$hosts = [];
		foreach ($failing as $request) {
			$instance = $request->getInstance();
			$host = $instance === null ? '' : $instance->getAddress();
			if ($host === '') {
				continue;
			}

			if (!isset($hosts[$host])) {
				$hosts[$host] = ['host' => $host, 'requests' => 0, 'tries' => 0, 'last' => 0];
			}

			$hosts[$host]['requests']++;
			$hosts[$host]['tries'] = max($hosts[$host]['tries'], $request->getTries());
			$hosts[$host]['last'] = max($hosts[$host]['last'], $request->getLast());
		}

		usort($hosts, function (array $one, array $other): int {
			return [$other['tries'], $other['requests']] <=> [$one['tries'], $one['requests']];
		});

		return array_slice($hosts, 0, self::TOP_INSTANCES);
	}
}
