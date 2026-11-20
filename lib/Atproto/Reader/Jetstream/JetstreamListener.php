<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader\Jetstream;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `occ social:atproto:listen` (§9.4): one WebSocket to the configured
 * Jetstream for posts, reposts, profiles and blocks of the Bluesky accounts
 * somebody here follows, asking nothing until it names them (an empty list
 * means the whole network). Events go to `JetstreamEvents`; the poller keeps
 * running, so a down listener only costs latency. The cursor is kept
 * periodically and resumed a few seconds early after a restart
 * (`JetstreamState`).
 */
class JetstreamListener {
	/** how many accounts one connection asks for, Jetstream's limit */
	public const MAX_DIDS = 10000;
	/** how often the followed accounts are read again */
	private const REFRESH = 60;
	/** how far back a reconnect starts, in microseconds */
	private const REWIND = 5000000;
	/**
	 * How long, and how many events, the cursor may run ahead of the kept one;
	 * each keep is a database write, and the rewind on reconnect covers the gap.
	 */
	public const KEEP_EVERY = 5;
	public const KEEP_EVENTS = 200;
	/**
	 * How often the status is reported; the admin page counts a listener silent
	 * for a minute as gone.
	 */
	public const REPORT_EVERY = 15;
	private const READ_TIMEOUT = 0.5;
	private const MAX_BACKOFF = 60;

	private bool $stopping = false;

	public function __construct(
		private AtprotoConfig $config,
		private JetstreamState $state,
		private AtprotoWatchRequest $watches,
		private JetstreamEvents $events,
		private JetstreamClient $client,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(string): void $say
	 * @param int $maxSeconds 0 runs until stopped
	 * @param bool $once read what is there now, and return
	 * @return int the exit code
	 */
	public function listen(callable $say, int $maxSeconds = 0, bool $once = false): int {
		$base = $this->config->jetstream();
		if (!$this->config->isEnabled() || $base === '') {
			$say('Bluesky is off, or no Jetstream is configured (atproto_jetstream)');

			return 1;
		}
		$this->listenForStop();
		$started = $this->time->getTime();
		$deadline = $maxSeconds > 0 ? $started + $maxSeconds : 0;
		$dids = [];
		$refreshed = 0;
		$cursor = $this->state->cursor();
		$kept = $cursor;
		$keptAt = $started;
		$unkept = 0;
		$lastEvent = 0;
		$backoff = 1;
		$retryAt = 0;
		$reported = 0;
		$reportedConnected = false;

		while (!$this->stopping) {
			$now = $this->time->getTime();
			if ($deadline > 0 && $now >= $deadline) {
				break;
			}
			if ($now - $refreshed >= self::REFRESH) {
				$fresh = $this->watchedDids();
				$refreshed = $now;
				if ($fresh !== $dids) {
					$dids = $fresh;
					$this->events->setWatched($dids);
					if ($this->client->isConnected()) {
						$dids === [] ? $this->client->close() : $this->client->send(self::optionsUpdate($dids));
					}
				}
			}
			if ($dids === []) {
				// at once when it last said it was connected
				if ($now - $reported >= self::REPORT_EVERY || $reportedConnected) {
					$this->report($started, $lastEvent, $cursor, false, count($dids));
					$reported = $now;
					$reportedConnected = false;
				}
				if ($once) {
					break;
				}
				usleep(1000000);
				continue;
			}
			if (!$this->client->isConnected()) {
				if ($now < $retryAt) {
					usleep(250000);
					continue;
				}
				try {
					$this->client->connect(self::url($base, $cursor > 0 ? $cursor - self::REWIND : 0));
					$this->client->send(self::optionsUpdate($dids));
					$say('Jetstream connected for ' . count($dids) . ' accounts');
					$backoff = 1;
				} catch (Throwable $e) {
					$this->logger->notice('Jetstream not reached', ['exception' => $e]);
					$say('Jetstream not reached: ' . $e->getMessage() . '; again in ' . $backoff . 's');
					$retryAt = $now + $backoff;
					$backoff = min(self::MAX_BACKOFF, $backoff * 2);
					if ($once) {
						return 1;
					}
					continue;
				}
			}

			$lost = false;
			try {
				$messages = $this->client->read(self::READ_TIMEOUT);
			} catch (Throwable $e) {
				$say('Jetstream connection lost: ' . $e->getMessage());
				$messages = [];
				$lost = true;
			}
			foreach ($messages as $message) {
				$event = json_decode($message, true);
				if (!is_array($event)) {
					continue;
				}
				$cursor = max($cursor, (int)($event['time_us'] ?? 0));
				$lastEvent = $this->time->getTime();
				$unkept++;
				$this->events->handle($event);
			}
			$this->events->due();

			$now = $this->time->getTime();
			if ($cursor !== $kept && ($lost || $unkept >= self::KEEP_EVENTS || $now - $keptAt >= self::KEEP_EVERY)) {
				$this->state->keepCursor($cursor);
				$kept = $cursor;
				$keptAt = $now;
				$unkept = 0;
			}
			if ($now - $reported >= self::REPORT_EVERY) {
				$reportedConnected = $this->client->isConnected();
				$this->report($started, $lastEvent, $cursor, $reportedConnected, count($dids));
				$reported = $now;
			}
			if ($once && $messages === [] && !$this->events->hasPending()) {
				break;
			}
		}

		$this->client->close();
		if ($cursor !== $kept) {
			$this->state->keepCursor($cursor);
		}
		$this->report($started, $lastEvent, $cursor, false, count($dids), false);
		$say('Jetstream listener stopped');

		return 0;
	}

	/**
	 * The address to connect to: the configured Jetstream's `/subscribe`,
	 * asking for the collections this app reads, nothing until the accounts
	 * are named, and from the cursor when there is one.
	 */
	public static function url(string $base, int $cursor = 0): string {
		$parts = parse_url(rtrim($base, '/'));
		$path = $parts['path'] ?? '';
		$url = rtrim($base, '/') . ($path === '' || $path === '/' ? '/subscribe' : '');
		$query = array_map(static fn (string $collection): string => 'wantedCollections=' . rawurlencode($collection), JetstreamEvents::COLLECTIONS);
		$query[] = 'requireHello=true';
		if ($cursor > 0) {
			$query[] = 'cursor=' . $cursor;
		}

		return $url . (str_contains($url, '?') ? '&' : '?') . implode('&', $query);
	}

	/**
	 * @param string[] $dids
	 */
	public static function optionsUpdate(array $dids): string {
		return (string)json_encode(['type' => 'options_update', 'payload' => [
			'wantedCollections' => JetstreamEvents::COLLECTIONS,
			'wantedDids' => array_values($dids),
			'maxMessageSizeBytes' => 0,
		]], JSON_UNESCAPED_SLASHES);
	}

	/**
	 * The last report, for the admin page: a listener that stopped
	 * reporting is one that died.
	 *
	 * @return array{running: bool, connected: bool, started: int, seen: int, last_event: int, cursor: int, accounts: int}|null
	 */
	public function status(): ?array {
		$status = $this->state->status();
		if ($status === null) {
			return null;
		}
		$running = ($status['running'] ?? false) && $this->time->getTime() - (int)($status['seen'] ?? 0) <= 60;

		return [
			'running' => $running,
			'connected' => $running && (bool)($status['connected'] ?? false),
			'started' => (int)($status['started'] ?? 0),
			'seen' => (int)($status['seen'] ?? 0),
			'last_event' => (int)($status['last_event'] ?? 0),
			'cursor' => (int)($status['cursor'] ?? 0),
			'accounts' => (int)($status['accounts'] ?? 0),
		];
	}

	/**
	 * @return list<string> the followed accounts, at most one connection's worth
	 */
	private function watchedDids(): array {
		$dids = array_map(static fn ($watch): string => $watch->did, $this->watches->getAll(limit: self::MAX_DIDS));
		sort($dids);

		return $dids;
	}

	private function report(int $started, int $lastEvent, int $cursor, bool $connected, int $accounts, bool $running = true): void {
		$this->state->report([
			'running' => $running,
			'connected' => $connected,
			'started' => $started,
			'seen' => $this->time->getTime(),
			'last_event' => $lastEvent,
			'cursor' => $cursor,
			'accounts' => $accounts,
		]);
	}

	private function listenForStop(): void {
		if (!function_exists('pcntl_async_signals')) {
			return;
		}
		pcntl_async_signals(true);
		$stop = function (): void {
			$this->stopping = true;
		};
		pcntl_signal(SIGTERM, $stop);
		pcntl_signal(SIGINT, $stop);
	}
}
