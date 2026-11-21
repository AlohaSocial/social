<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\HostBreakerRequest;

/**
 * The circuit breaker on remote hosts, as one drain sees it.
 *
 * The state is `social_host_breaker`, shared by the cron, the async worker,
 * every `social:worker` process and both queues, so one of them finding a
 * host down spares all of them. An instance of this is what one drain loaded,
 * plus what it learnt since. A host that keeps failing is held back longer
 * each time, up to an hour; strikes older than `STRIKE_WINDOW` no longer count.
 */
class HostBreaker {
	/** How long a host that has just failed is left alone, in seconds. */
	public const BASE = 60;

	/** The ceiling on that. */
	public const MAX = 3600;

	/**
	 * How long a strike counts. Longer than the ceiling, so a host held for
	 * the full hour still has its strikes when it is asked again.
	 */
	public const STRIKE_WINDOW = 4 * self::MAX;

	/** @var array<string, int> the hosts this drain has found to be failing itself, and until when */
	private array $failing = [];

	/**
	 * The table's rows as this drain found them, loaded on first use and again
	 * after every `reset()`.
	 *
	 * @var array<string, array{strikes: int, open_until: int, last_failure: int}>|null
	 */
	private ?array $state = null;

	public function __construct(
		private HostBreakerRequest $hostBreakerRequest,
	) {
	}

	/** Forgets what this drain knew, so the next question reads the table again. */
	public function reset(): void {
		$this->failing = [];
		$this->state = null;
	}

	/**
	 * When this host is worth asking again, or 0 when it is worth asking now.
	 * Asked before a request is made, from a map loaded once; a timestamp
	 * because what is addressed to the host is held back until then.
	 */
	public function openUntil(string $host): int {
		if (isset($this->failing[$host])) {
			return $this->failing[$host];
		}

		$until = $this->state()[$host]['open_until'] ?? 0;

		return ($until > time()) ? $until : 0;
	}

	/**
	 * Records that this host is failing, for longer each consecutive time; a
	 * host that answers again clears it. A peer's `Retry-After` (on a 429 or
	 * 503) is honoured, but never beyond MAX.
	 *
	 * @param int $atLeast the seconds the peer asked for, 0 where it did not
	 */
	public function open(string $host, int $atLeast = 0): void {
		$now = time();
		$state = $this->state();
		$strikes = (($state[$host]['last_failure'] ?? 0) > $now - self::STRIKE_WINDOW)
			? $state[$host]['strikes'] + 1
			: 1;
		$for = min(self::MAX, max(self::BASE * (int)(2 ** min(6, $strikes - 1)), $atLeast));
		$this->failing[$host] = $now + $for;

		$this->state[$host] = ['strikes' => $strikes, 'open_until' => $now + $for, 'last_failure' => $now];
		try {
			$this->hostBreakerRequest->open($host, $strikes, $now + $for, $now);
		} catch (\Throwable $e) {
			// the per-drain list above is the fallback
		}
	}

	/**
	 * A host that answered: it is not failing, whatever it did before. Only a
	 * host this drain knows to have failed costs a write.
	 */
	public function close(string $host): void {
		if (!isset($this->state()[$host])) {
			return;
		}

		unset($this->state[$host]);
		try {
			$this->hostBreakerRequest->close($host);
		} catch (\Throwable $e) {
		}
	}

	/**
	 * Forgets the hosts that have not failed for longer than a strike counts.
	 * A cheap DELETE on an index, for the cron to run each pass.
	 */
	public function forgetRecovered(): void {
		try {
			$this->hostBreakerRequest->forgetBefore(time() - self::STRIKE_WINDOW);
		} catch (\Throwable $e) {
		}
	}

	/** @return array<string, array{strikes: int, open_until: int, last_failure: int}> */
	private function state(): array {
		if ($this->state === null) {
			try {
				$this->state = $this->hostBreakerRequest->failingSince(time() - self::STRIKE_WINDOW);
			} catch (\Throwable $e) {
				// the table not there yet (an upgrade not run) or the database
				// having a moment: the per-drain list is the fallback
				$this->state = [];
			}
		}

		return $this->state;
	}
}
