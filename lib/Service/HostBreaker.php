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
 * The state is `social_host_breaker`, shared between the cron, the async
 * worker, every `social:worker` process and both queues — which is the point:
 * one of them discovering that a host is down spares all of them. An instance
 * of this is what one drain loaded of it, plus what that drain learnt since.
 *
 * The list used to be per-pass, emptied at the start of every run, so a dead
 * peer was discovered afresh every twelve minutes, one 30-second timeout at a
 * time, for every row addressed to it. Kept in the table the discovery
 * survives the pass and the process — and a host that keeps failing is left
 * alone for longer each time, up to an hour, which is the difference between
 * a dead instance costing a few seconds a day and costing the whole budget.
 * Strikes older than `STRIKE_WINDOW` no longer count.
 */
class HostBreaker {
	/** How long a host that has just failed is left alone, in seconds. */
	public const BASE = 60;

	/** The ceiling on that. */
	public const MAX = 3600;

	/**
	 * How long a strike counts. Longer than the ceiling: a host held back for
	 * the full hour is asked again only once the hour is over, and a strike
	 * that had lapsed by then sent a dead host back to a one-minute hold,
	 * every hour, for ever.
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
	 *
	 * Asked before a request is made rather than after it times out, which is
	 * the whole saving: a row addressed to a dead instance costs a lookup in a
	 * map this drain loaded once instead of thirty seconds. The answer is a
	 * timestamp rather than a yes/no because what is addressed to the host has
	 * to be held back until then.
	 */
	public function openUntil(string $host): int {
		if (isset($this->failing[$host])) {
			return $this->failing[$host];
		}

		$until = $this->state()[$host]['open_until'] ?? 0;

		return ($until > time()) ? $until : 0;
	}

	/**
	 * Records that this host is failing, for longer each consecutive time.
	 *
	 * The backoff is what stops a permanently dead instance from being
	 * rediscovered every minute for ever; a host that answers again clears it,
	 * so a peer that was merely restarting is not held at arm's length.
	 *
	 * A peer that said how long to leave it alone (`Retry-After` on a 429 or
	 * 503) is left alone at least that long, but never longer than MAX: an
	 * answer saying "a week" must not take a peer out of federation for one.
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
	 * A host that answered: it is not failing, whatever it did before.
	 *
	 * Only a host this drain knows to have failed costs a write; a healthy
	 * one, which is nearly every request, costs nothing.
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
