<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamState;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Service\DurableCache;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * How Bluesky is doing on this server, in figures, for the administrator.
 *
 * Every figure is an indexed count over local tables and app values, never
 * the network, cached for a minute. Blocks across repositories are left out:
 * no index can count them.
 */
class AtprotoFiguresService {
	public const CACHE_NAMESPACE = Application::APP_ID . '/bluesky-figures';
	public const CACHE_SECONDS = 60;
	private const DAY = 86400;

	public function __construct(
		private AtprotoConfig $config,
		private JetstreamState $jetstream,
		private IdentityService $identities,
		private AtprotoRepoRequest $repoRequest,
		private AtprotoWatchRequest $watches,
		private EventService $events,
		private FirehoseDaemon $daemon,
		private DurableCache $cache,
		private ITimeFactory $time,
	) {
	}

	/**
	 * The figures, or only `enabled: false` while Bluesky is switched off.
	 *
	 * @return array<string, mixed>
	 */
	public function figures(): array {
		if (!$this->config->isEnabled()) {
			return ['enabled' => false];
		}
		$cached = $this->cache->get(self::CACHE_NAMESPACE, 'figures');
		if (is_array($cached)) {
			return $cached;
		}
		$figures = $this->compute();
		$this->cache->set(self::CACHE_NAMESPACE, 'figures', $figures, self::CACHE_SECONDS);

		return $figures;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function compute(): array {
		$now = $this->time->getTime();
		$sums = $this->repoRequest->sumHeads();
		$daemon = $this->daemon->status();
		$running = ($daemon['running'] ?? false) === true;

		return [
			'enabled' => true,
			'computed' => $now,
			'accounts' => [
				'identities' => $this->identities->count(),
				// the people who switched their presence on Bluesky off
				'off' => $this->identities->countDeactivated(),
			],
			'posts' => [
				'day' => $this->repoRequest->countRecordsWrittenSince(RecordMapper::POST, $now - self::DAY),
				'week' => $this->repoRequest->countRecordsWrittenSince(RecordMapper::POST, $now - 7 * self::DAY),
			],
			'repositories' => [
				'count' => $this->repoRequest->countHeads(),
				'records' => $sums['records'],
				'blob_bytes' => $sums['blob_bytes'],
			],
			'reader' => [
				'watched' => $this->watches->count(),
				'jetstream' => $this->config->jetstream() !== '',
				'jetstream_lag' => $this->jetstreamLag($now),
			],
			'firehose' => [
				'running' => $running,
				// only the daemon holds its connections; it reports how many
				// every few seconds, and a daemon that stopped reporting has none
				'subscribers' => $running ? (int)$daemon['subscribers'] : null,
				'head_seq' => $this->events->latestSeq(),
				'head_time' => $this->events->latestTime(),
				'events_kept' => $this->events->countInWindow(),
			],
		];
	}

	/**
	 * How far behind now the listener's Jetstream cursor is, in seconds; null
	 * without a Jetstream endpoint or a cursor. The cursor is the last event
	 * heard, so a quiet hour reads as an hour behind.
	 */
	private function jetstreamLag(int $now): ?int {
		if ($this->config->jetstream() === '') {
			return null;
		}
		$cursor = $this->jetstream->cursor();
		if ($cursor <= 0) {
			return null;
		}

		return max(0, $now - intdiv($cursor, 1000000));
	}
}
