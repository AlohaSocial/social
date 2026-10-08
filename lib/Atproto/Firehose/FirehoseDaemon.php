<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Firehose;

use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * `occ social:atproto:serve`: the long-running process that serves the
 * event stream.
 *
 * The web requests write frames to the event table; this reads them in
 * order and hands each to every subscriber that is caught up. A subscriber
 * that connected with a cursor is replayed from the row after it, out of
 * what the table still holds (the replay window), and told with an
 * `#info OutdatedCursor` when it asked for more than that; one that asked
 * for a future sequence number is refused with `FutureCursor`.
 *
 * Polling, every POLL_IDLE seconds when nothing happened and at once after
 * something did: one code path, no shared memory, and a restart loses
 * nothing because the table is the stream.
 */
class FirehoseDaemon {
	public const DEFAULT_BIND = '127.0.0.1:8787';
	private const POLL_IDLE = 0.25;
	private const BATCH = 200;

	private bool $stopping = false;

	public function __construct(
		private AtprotoConfig $config,
		private EventService $events,
		private ConfigService $configService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
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
		$server = new WebSocketServer($bind);
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
			foreach ($server->serve(self::POLL_IDLE) as $subscriber) {
				$this->welcome($server, $subscriber, $head);
			}

			$latest = $this->events->latestSeq();
			if ($latest > $head) {
				$head = $this->broadcast($server, $head, $latest);
			}
			if ($now % 15 === 0) {
				$this->report($head, count($server->subscribers()), $started);
			}
			if ($once && $latest <= $head) {
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
	private function welcome(WebSocketServer $server, Subscriber $subscriber, int $head): void {
		if ($subscriber->cursor === null) {
			$subscriber->sent = $head;

			return;
		}
		if ($subscriber->cursor > $head) {
			$server->send($subscriber, DagCbor::encode(['op' => -1]) . DagCbor::encode(['error' => 'FutureCursor', 'message' => 'Cursor in the future.']));
			$server->close($subscriber, 'future cursor');

			return;
		}
		$oldest = $this->events->oldestSeq();
		if ($oldest > 0 && $subscriber->cursor < $oldest - 1) {
			$server->send($subscriber, DagCbor::encode(['op' => 1, 't' => '#info']) . DagCbor::encode(['name' => 'OutdatedCursor', 'message' => 'Requested cursor exceeded limit. Possibly missing events']));
		}
		$subscriber->sent = $subscriber->cursor;
		$this->catchUp($server, $subscriber, $head);
	}

	/**
	 * Sends the subscriber everything between where it is and $upTo.
	 */
	private function catchUp(WebSocketServer $server, Subscriber $subscriber, int $upTo): void {
		while (!$subscriber->closed && $subscriber->sent < $upTo) {
			$batch = $this->events->after($subscriber->sent, self::BATCH);
			if ($batch === []) {
				$subscriber->sent = $upTo;

				return;
			}
			foreach ($batch as $event) {
				$server->send($subscriber, $event->frame());
				$subscriber->sent = $event->seq;
			}
		}
	}

	/**
	 * New frames to every subscriber.
	 *
	 * @return int the new head
	 */
	private function broadcast(WebSocketServer $server, int $head, int $latest): int {
		$events = $this->events->after($head, self::BATCH);
		foreach ($events as $event) {
			foreach ($server->subscribers() as $subscriber) {
				if ($subscriber->sent < $event->seq - 1) {
					// fell behind: a replay takes it to here
					$this->catchUp($server, $subscriber, $event->seq - 1);
				}
				if ($subscriber->sent === $event->seq - 1) {
					$server->send($subscriber, $event->frame());
					$subscriber->sent = $event->seq;
				}
			}
			$head = $event->seq;
		}

		return $head;
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
