<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader\Jetstream;

use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a Jetstream event about a watched Bluesky account does here. A post
 * or a repost is a sign the account's feed has something new: it is read
 * as the poller reads it (`FeedPoller::pollWatch()`), a moment later, when
 * the AppView has it — and again a little later if it did not yet, until
 * the poller is left to it. A deleted post is deleted here at once; a
 * changed profile or handle is read again.
 */
class JetstreamEvents {
	public const POST = 'app.bsky.feed.post';
	public const REPOST = 'app.bsky.feed.repost';
	public const PROFILE = 'app.bsky.actor.profile';
	public const COLLECTIONS = [self::POST, self::REPOST, self::PROFILE];
	/** seconds after an event, and after each read that did not find it yet */
	public const DELAYS = [2, 5, 20];

	/** @var array<string, true> the DIDs whose events are acted on */
	private array $watched = [];
	/** @var array<string, array{due: int, attempts: int, expect: list<array{0: string, 1: string}>}> by DID: what each event announced, as the kind and the id */
	private array $pending = [];

	public function __construct(
		private AtprotoWatchRequest $watches,
		private FeedPoller $poller,
		private PostStore $store,
		private StreamRequest $streams,
		private BlueskyActorService $actors,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string[] $dids
	 */
	public function setWatched(array $dids): void {
		$this->watched = array_fill_keys($dids, true);
	}

	public function hasPending(): bool {
		return $this->pending !== [];
	}

	/**
	 * One event as Jetstream sends it.
	 */
	public function handle(array $event): void {
		$did = (string)($event['did'] ?? '');
		if (!isset($this->watched[$did])) {
			return;
		}
		try {
			match ((string)($event['kind'] ?? '')) {
				'commit' => $this->commit($did, is_array($event['commit'] ?? null) ? $event['commit'] : []),
				'identity' => $this->identity($did, (string)($event['identity']['handle'] ?? '')),
				default => null,
			};
		} catch (Throwable $e) {
			$this->logger->info('Jetstream event not handled', ['did' => $did, 'exception' => $e]);
		}
	}

	/**
	 * The feeds whose time has come read, each again later while what the
	 * event announced is still not here.
	 *
	 * @return int how many rows were stored
	 */
	public function due(): int {
		$now = $this->time->getTime();
		$stored = 0;
		foreach ($this->pending as $did => $pending) {
			if ($pending['due'] > $now) {
				continue;
			}
			unset($this->pending[$did]);
			$watch = $this->watches->getByDid($did);
			if ($watch === null) {
				continue;
			}
			try {
				$stored += $this->poller->pollWatch($watch);
			} catch (Throwable $e) {
				$this->logger->info('Jetstream: feed not read', ['did' => $did, 'exception' => $e]);
			}
			$missing = array_values(array_filter($pending['expect'], fn (array $expected): bool => !$this->arrived($did, $expected)));
			if ($missing === []) {
				continue;
			}
			$attempts = max(1, $pending['attempts'] + 1);
			if ($attempts >= count(self::DELAYS)) {
				// the poller's next pass reads it, whatever backoff said
				$this->watches->wake($did, $now);
				continue;
			}
			$this->pending[$did] = ['due' => $now + self::DELAYS[$attempts], 'attempts' => $attempts, 'expect' => $missing];
		}

		return $stored;
	}

	private function commit(string $did, array $commit): void {
		$collection = (string)($commit['collection'] ?? '');
		$operation = (string)($commit['operation'] ?? '');
		$rkey = (string)($commit['rkey'] ?? '');
		if ($rkey === '') {
			return;
		}
		if ($collection === self::POST && $operation === 'create') {
			$this->expect($did, ['post', BlueskyIds::postId($did, $rkey)]);
		} elseif ($collection === self::POST && $operation === 'delete') {
			$this->store->delete(BlueskyIds::postId($did, $rkey));
		} elseif ($collection === self::REPOST && $operation === 'create') {
			$subject = BlueskyIds::postIdOfUri((string)($commit['record']['subject']['uri'] ?? ''));
			if ($subject !== '') {
				$this->expect($did, ['repost', $subject]);
			}
		} elseif ($collection === self::PROFILE && $operation !== 'delete') {
			$this->actors->resolve($did, true);
		}
	}

	private function identity(string $did, string $handle): void {
		$this->actors->resolve($did, true);
		if ($handle !== '') {
			$this->watches->setHandle($did, strtolower($handle));
		}
	}

	/**
	 * @param array{0: string, 1: string} $expected
	 */
	private function expect(string $did, array $expected): void {
		$pending = $this->pending[$did] ?? ['due' => $this->time->getTime() + self::DELAYS[0], 'attempts' => 0, 'expect' => []];
		$pending['expect'][] = $expected;
		$this->pending[$did] = $pending;
	}

	/**
	 * Whether what an event of `$did` announced is here: the post, or the
	 * boost the repost is.
	 *
	 * @param array{0: string, 1: string} $expected
	 */
	private function arrived(string $did, array $expected): bool {
		[$kind, $id] = $expected;
		if ($kind === 'post') {
			return $this->store->isKnown($id);
		}
		try {
			$this->streams->getAnnounceBy($id, BlueskyIds::actorId($did));

			return true;
		} catch (StreamNotFoundException) {
			return false;
		}
	}
}
