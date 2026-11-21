<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Firehose;

use Closure;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Security\Ip\IFactory;
use Psr\Log\LoggerInterface;

/**
 * `occ social:atproto:serve`: the long-running process that serves the
 * event stream.
 *
 * Web requests write frames to the event table; this polls it (every
 * POLL_IDLE seconds when idle) and sends each frame to every caught-up
 * subscriber, so a restart loses nothing. A subscriber with a cursor is
 * replayed from the table in batches between rounds, the next batch once it
 * has read most of the last (REPLAY_LOW_WATER); a cursor past the window
 * gets `#info OutdatedCursor`, a future one `FutureCursor`. One address may
 * ask for REPLAYS_PER_ADDRESS replays per REPLAY_WINDOW. Subscribers are
 * pinged every PING_INTERVAL and closed after IDLE_TIMEOUT.
 *
 * Sequence numbers are taken at insert and seen at commit, so a gap is
 * waited on for GAP_GRACE seconds before the stream moves past it (a rolled
 * back write).
 */
class FirehoseDaemon {
	public const DEFAULT_BIND = '127.0.0.1:8787';
	/** seconds a missing sequence number is waited for before the stream moves past it */
	public const GAP_GRACE = 2;
	private const POLL_IDLE = 0.25;
	private const BATCH = 200;
	/** bytes left unread in a replaying subscriber's buffer below which its next batch is sent */
	private const REPLAY_LOW_WATER = 1024 * 1024;
	/** replays one address may ask for per REPLAY_WINDOW seconds */
	private const REPLAYS_PER_ADDRESS = 6;
	private const REPLAY_WINDOW = 60;
	private const PING_INTERVAL = 30;
	private const IDLE_TIMEOUT = 120;

	private bool $stopping = false;
	/** the first sequence number of the gap being waited on, 0 when none */
	private int $missing = 0;
	private int $missingSince = 0;
	/** @var array<string, list<int>> when each address bucket last asked for a replay */
	private array $replays = [];
	private int $lastPing = 0;
	private bool $warnedUnforwarded = false;

	public function __construct(
		private AtprotoConfig $config,
		private EventService $events,
		private ConfigService $configService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ?IConfig $systemConfig = null,
		private ?IFactory $ipFactory = null,
	) {
	}

	/**
	 * Serves until stopped, until $maxSeconds pass, or — with $once — until
	 * the backlog is drained once.
	 *
	 * @param callable(string): void $say progress for the console
	 * @return int the exit code
	 */
	public function run(string $bind, int $maxSeconds, bool $once, callable $say): int {
		$server = new WebSocketServer($bind, trustedProxy: $this->trustedProxyCheck());
		$this->listenForStop();
		$started = $this->time->getTime();
		$deadline = $maxSeconds > 0 ? $started + $maxSeconds : 0;
		$head = $this->events->latestSeq();
		$say('Firehose listening on ' . $bind . ', head seq ' . $head);
		$this->report($head, 0, $started);

		while (!$this->stopping) {
			$now = $this->time->getTime();
			if ($deadline > 0 && $now >= $deadline) {
				break;
			}
			foreach ($server->serve($this->replayPending($server, $head) ? 0.0 : self::POLL_IDLE) as $subscriber) {
				$this->welcome($server, $subscriber, $head, $now);
			}

			$latest = $this->events->latestSeq();
			if ($latest > $head) {
				$head = $this->broadcast($server, $head, $now);
			}
			$this->replay($server, $head);
			if ($now - $this->lastPing >= self::PING_INTERVAL) {
				foreach ($server->subscribers() as $subscriber) {
					$server->ping($subscriber);
				}
				$server->closeIdle(self::IDLE_TIMEOUT);
				$this->lastPing = $now;
			}
			if ($now % 15 === 0) {
				$this->report($head, count($server->subscribers()), $started);
			}
			if ($once && $latest <= $head && !$this->replayPending($server, $head)) {
				break;
			}
		}

		$server->shutdown();
		$this->report($head, 0, $started, false);
		$say('Firehose stopped at seq ' . $head);

		return 0;
	}

	/**
	 * A subscriber that just upgraded: replayed from its cursor, or started
	 * live.
	 */
	private function welcome(WebSocketServer $server, Subscriber $subscriber, int $head, int $now): void {
		if (!$this->warnedUnforwarded && !isset($subscriber->headers['x-forwarded-for']) && $this->isLoopback($subscriber->address)) {
			$this->warnedUnforwarded = true;
			$this->logger->warning('Firehose connection from ' . $subscriber->address . ' without X-Forwarded-For: every client behind the web server is counted as one address. Add the header to the subscribeRepos proxy (contrib/webserver).');
		}
		if ($subscriber->cursor === null) {
			$subscriber->sent = $head;

			return;
		}
		if ($subscriber->cursor > $head) {
			$server->send($subscriber, DagCbor::encode(['op' => -1]) . DagCbor::encode(['error' => 'FutureCursor', 'message' => 'Cursor in the future.']));
			$server->close($subscriber, 'future cursor');

			return;
		}
		if ($subscriber->cursor < $head && !$this->mayReplay($subscriber->address, $now)) {
			$server->send($subscriber, DagCbor::encode(['op' => -1]) . DagCbor::encode(['error' => 'RateLimitExceeded', 'message' => 'Too many replays from this address; try again in a minute.']));
			$server->close($subscriber, 'too many replays');

			return;
		}
		$oldest = $this->events->oldestSeq();
		if ($oldest > 0 && $subscriber->cursor < $oldest - 1) {
			$server->send($subscriber, DagCbor::encode(['op' => 1, 't' => '#info']) . DagCbor::encode(['name' => 'OutdatedCursor', 'message' => 'Requested cursor exceeded limit. Possibly missing events']));
		}
		$subscriber->sent = $subscriber->cursor;
	}

	/** Whether the address may start another replay now, counting it when it may. */
	private function mayReplay(string $address, int $now): bool {
		$bucket = WebSocketServer::bucket($address);
		$recent = array_values(array_filter($this->replays[$bucket] ?? [], static fn (int $at): bool => $now - $at < self::REPLAY_WINDOW));
		foreach ($this->replays as $other => $times) {
			if ($other !== $bucket && array_filter($times, static fn (int $at): bool => $now - $at < self::REPLAY_WINDOW) === []) {
				unset($this->replays[$other]);
			}
		}
		if (count($recent) >= self::REPLAYS_PER_ADDRESS) {
			$this->replays[$bucket] = $recent;

			return false;
		}
		$recent[] = $now;
		$this->replays[$bucket] = $recent;

		return true;
	}

	/** Whether a subscriber behind the head can be sent its next batch now. */
	private function replayPending(WebSocketServer $server, int $head): bool {
		foreach ($server->subscribers() as $subscriber) {
			if ($subscriber->sent < $head && strlen($subscriber->output) < self::REPLAY_LOW_WATER) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One batch of the backlog to each subscriber that is behind the head
	 * and has read most of what it was sent.
	 */
	private function replay(WebSocketServer $server, int $head): void {
		foreach ($server->subscribers() as $subscriber) {
			if ($subscriber->sent >= $head || strlen($subscriber->output) >= self::REPLAY_LOW_WATER) {
				continue;
			}
			$batch = $this->events->after($subscriber->sent, self::BATCH);
			if ($batch === []) {
				$subscriber->sent = $head;
				continue;
			}
			foreach ($batch as $event) {
				if ($subscriber->closed || $event->seq > $head) {
					break;
				}
				$server->send($subscriber, $event->frame());
				$subscriber->sent = $event->seq;
				if (strlen($subscriber->output) >= self::REPLAY_LOW_WATER) {
					break;
				}
			}
		}
	}

	/**
	 * New frames to every subscriber, in order, stopping short of a gap
	 * that is still being waited on.
	 *
	 * @return int the new head
	 */
	private function broadcast(WebSocketServer $server, int $head, int $now): int {
		$events = $this->events->after($head, self::BATCH);
		foreach ($events as $event) {
			if ($event->seq > $head + 1 && !$this->gapMayBePassed($head + 1, $event->seq - 1, $now)) {
				break;
			}
			foreach ($server->subscribers() as $subscriber) {
				// one still being replayed gets this frame from its replay
				if ($subscriber->sent === $head) {
					$server->send($subscriber, $event->frame());
					$subscriber->sent = $event->seq;
				}
			}
			$head = $event->seq;
		}

		return $head;
	}

	/**
	 * Whether the stream may move past the sequence numbers $from to $to,
	 * which are not there: not before GAP_GRACE seconds have passed since
	 * the gap was first seen.
	 */
	private function gapMayBePassed(int $from, int $to, int $now): bool {
		if ($this->missing !== $from) {
			$this->missing = $from;
			$this->missingSince = $now;

			return false;
		}
		if ($now - $this->missingSince < self::GAP_GRACE) {
			return false;
		}
		$this->logger->info('Firehose moves past seq ' . ($to > $from ? $from . '-' . $to : (string)$from) . ', not written within ' . self::GAP_GRACE . ' s of the next');
		$this->missing = 0;

		return true;
	}

	/**
	 * What the admin page shows about the daemon, as an app value.
	 */
	private function report(int $head, int $subscribers, int $started, bool $running = true): void {
		$this->configService->setAppValue(ConfigService::ATPROTO_FIREHOSE_STATUS, (string)json_encode([
			'running' => $running,
			'pid' => getmypid(),
			'started' => $started,
			'seen' => $this->time->getTime(),
			'head' => $head,
			'subscribers' => $subscribers,
		]));
	}

	/**
	 * The last report, for the admin page and the setup check.
	 *
	 * @return array{running: bool, pid: int, started: int, seen: int, head: int, subscribers: int}|null
	 */
	public function status(): ?array {
		$status = json_decode((string)$this->configService->getAppValue(ConfigService::ATPROTO_FIREHOSE_STATUS), true);
		if (!is_array($status)) {
			return null;
		}
		// a daemon that stopped reporting is one that died
		if (($status['running'] ?? false) && $this->time->getTime() - (int)($status['seen'] ?? 0) > 60) {
			$status['running'] = false;
		}

		return [
			'running' => (bool)($status['running'] ?? false),
			'pid' => (int)($status['pid'] ?? 0),
			'started' => (int)($status['started'] ?? 0),
			'seen' => (int)($status['seen'] ?? 0),
			'head' => (int)($status['head'] ?? 0),
			'subscribers' => (int)($status['subscribers'] ?? 0),
		];
	}

	/**
	 * The proxies besides the loopback whose `X-Forwarded-For` is believed:
	 * the server's `trusted_proxies`.
	 *
	 * @return Closure(string): bool|null
	 */
	private function trustedProxyCheck(): ?Closure {
		if ($this->systemConfig === null || $this->ipFactory === null) {
			return null;
		}
		$ranges = [];
		foreach ((array)$this->systemConfig->getSystemValue('trusted_proxies', []) as $range) {
			try {
				$ranges[] = $this->ipFactory->rangeFromString((string)$range);
			} catch (\InvalidArgumentException) {
			}
		}
		if ($ranges === []) {
			return null;
		}
		$factory = $this->ipFactory;

		return static function (string $address) use ($ranges, $factory): bool {
			try {
				$ip = $factory->addressFromString($address);
			} catch (\InvalidArgumentException) {
				return false;
			}
			foreach ($ranges as $range) {
				if ($range->contains($ip)) {
					return true;
				}
			}

			return false;
		};
	}

	private function isLoopback(string $address): bool {
		return $address === '::1' || str_starts_with($address, '127.');
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
