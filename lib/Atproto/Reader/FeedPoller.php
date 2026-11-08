<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Reads the feeds of the Bluesky authors somebody here follows (D12): the
 * watches due, each asked the public AppView for its next page, every
 * post newer than the last read stored, and the next read set by backoff —
 * soon after a page with posts, doubling up to six hours after empty ones,
 * reset by the next post. One pass is bounded twice: by how many watches
 * it visits and by the instance-wide ceiling on AppView requests. A walk
 * to older posts goes back at most BACKFILL_AGE and WALK_PAGES pages, and
 * while it is unfinished the newest page is read too. An account the
 * AppView refuses as deactivated, suspended, taken down or gone, three
 * times with no successful read between (network errors do not count),
 * is hidden here, or purged when its repository is gone (`AccountStatus`);
 * one it serves again is restored.
 */
class FeedPoller {
	private ?string $acceptLabelers = null;

	/** how soon a watch is read again after a page with posts */
	public const INTERVAL = 120;
	public const MAX_BACKOFF = 6 * 3600;
	/** watches visited per pass */
	public const BATCH = 50;
	/** posts asked for per watch and pass, continuing a backlog on the next pass */
	public const PAGE = 50;
	/** consecutive refusals naming an account's status before it is acted on */
	public const STATUS_AFTER = 3;
	/** how far back a walk reads: a new watch's history, or a long gap */
	public const BACKFILL_AGE = 30 * 86400;
	/** pages one walk reads at most before it commits what it has */
	public const WALK_PAGES = 10;

	public function __construct(
		private AtprotoConfig $config,
		private AtprotoWatchRequest $watches,
		private AppViewClient $appView,
		private PostStore $store,
		private BlueskyActorService $actors,
		private Blocklist $blocklist,
		private LabelerService $labelers,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ?AccountStatus $status = null,
	) {
	}

	/**
	 * @param int $deadline the wall clock past which no further watch is read, 0 for none
	 * @return array{watches: int, stored: int, requests: int}
	 */
	public function poll(int $limit = self::BATCH, int $deadline = 0): array {
		$result = ['watches' => 0, 'stored' => 0, 'requests' => 0];
		if (!$this->config->isEnabled()) {
			return $result;
		}
		$ceiling = $this->config->syncCeiling();
		foreach ($this->watches->getDue($this->time->getTime(), $limit) as $watch) {
			if ($result['requests'] >= $ceiling || ($deadline > 0 && $this->time->getTime() >= $deadline)) {
				break;
			}
			$result['watches']++;
			// a watch in the middle of a walk reads its newest page as well
			$result['requests'] += str_starts_with($watch->cursor, '{') ? 2 : 1;
			$result['stored'] += $this->pollWatch($watch);
		}

		return $result;
	}

	/**
	 * One watch: one page towards its last committed timestamp.
	 *
	 * @return int how many rows were stored
	 */
	public function pollWatch(Watch $watch): int {
		$now = $this->time->getTime();
		$actor = $this->actors->cached($watch->did);
		if ($this->blocklist->isBlockedDid($watch->did) || ($actor !== null && $this->blocklist->isBlockedActor($actor))) {
			$this->watches->remove($watch->did);

			return 0;
		}
		// an account its labels limit is left alone; one Bluesky switched off
		// is asked about, so it is restored when it comes back
		if ($actor !== null && BlueskyActorService::isLimited($actor) && BlueskyActorService::status($actor) === '') {
			$this->watches->synced($watch->did, $watch->cursor, $now, $now + self::MAX_BACKOFF);

			return 0;
		}
		try {
			$checkpoint = new PollCursor($watch->cursor);
			$floor = gmdate('Y-m-d\\TH:i:s.000\\Z', $now - self::BACKFILL_AGE);
			$stored = 0;
			$headNewest = '';
			$handle = '';
			if ($checkpoint->walking()) {
				// the newest posts first, so they do not wait for the walk
				[$headStored, $headNewest, $handle] = $this->readHead($watch, $checkpoint->newest);
				$stored += $headStored;
			}
			$feed = $this->appView->query('app.bsky.feed.getAuthorFeed', ['actor' => $watch->did, 'filter' => 'posts_with_replies', 'limit' => self::PAGE] + $checkpoint->parameters(), $this->labelHeaders());
			if (!is_array($feed['feed'] ?? null)) {
				throw new UnexpectedValueException('Bluesky author feed has no feed array');
			}
			if ($actor !== null && BlueskyActorService::status($actor) !== '') {
				$this->status?->restore($watch->did);
			}

			$newest = max($checkpoint->newest, $headNewest);
			// a walk goes no further back than the committed timestamp, nor
			// than BACKFILL_AGE
			$lower = max($checkpoint->since, $floor);
			$reached = false;
			foreach ($feed['feed'] as $item) {
				if (!is_array($item) || ($item['reason']['$type'] ?? '') === 'app.bsky.feed.defs#reasonPin') {
					continue;
				}
				$seen = self::timeOf($item);
				$newest = max($newest, $seen);
				if ($seen !== '' && $seen < $lower) {
					$reached = true;
					break;
				}
				if ($handle === '' && ($item['post']['author']['did'] ?? '') === $watch->did) {
					$handle = strtolower((string)($item['post']['author']['handle'] ?? ''));
				}
				$stored += $this->store->storeFeedItem($item);
			}
			$next = $checkpoint->after($newest, $feed['cursor'] ?? '', $reached, true, self::WALK_PAGES);
		} catch (AppViewNotFoundException $e) {
			$status = AccountStatus::ERRORS[$e->error] ?? '';
			if ($status === '') {
				// an account that is gone, deactivated or blocking: the watch
				// stays, tried again later, in case it comes back
				$this->watches->failed($watch->did, self::withStatusAnswers(self::statusAnswers($watch), $e->getMessage()), $now + self::MAX_BACKOFF);

				return 0;
			}
			// the AppView names the status: acted on once it has said so a
			// few times with no successful read between, with the ordinary
			// backoff between them; other failures in between do not count
			$answers = self::statusAnswers($watch) + 1;
			$this->watches->failed($watch->did, self::withStatusAnswers($answers, $e->getMessage()), $now + $this->failureBackoff($watch));
			if ($answers >= self::STATUS_AFTER) {
				$this->status?->apply($watch->did, false, $status);
			}

			return 0;
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky feed not read', ['did' => $watch->did, 'exception' => $e]);
			$this->watches->failed($watch->did, self::withStatusAnswers(self::statusAnswers($watch), $e->getMessage()), $now + $this->failureBackoff($watch));

			return 0;
		}
		if ($handle !== '' && $handle !== $watch->handle) {
			$this->watches->setHandle($watch->did, $handle);
		}
		$this->watches->synced($watch->did, $next, $now, $now + $this->nextInterval($watch, $stored > 0 || $next !== $newest));

		return $stored;
	}

	/**
	 * The newest page, read while a walk to older posts is unfinished: every
	 * post down to the walk's own newest one is stored. Only when the page
	 * reaches that far does its newest timestamp count for the walk; a page
	 * that does not leaves a gap the next walk reads.
	 *
	 * @return array{0: int, 1: string, 2: string} rows stored, the newest timestamp to commit ('' for none), the author's handle
	 */
	private function readHead(Watch $watch, string $walkNewest): array {
		$feed = $this->appView->query('app.bsky.feed.getAuthorFeed', ['actor' => $watch->did, 'filter' => 'posts_with_replies', 'limit' => self::PAGE], $this->labelHeaders());
		if (!is_array($feed['feed'] ?? null)) {
			throw new UnexpectedValueException('Bluesky author feed has no feed array');
		}
		$stored = 0;
		$newest = '';
		$handle = '';
		$overlaps = !is_string($feed['cursor'] ?? null) || $feed['cursor'] === '';
		foreach ($feed['feed'] as $item) {
			if (!is_array($item) || ($item['reason']['$type'] ?? '') === 'app.bsky.feed.defs#reasonPin') {
				continue;
			}
			$seen = self::timeOf($item);
			if ($seen !== '' && $seen <= $walkNewest) {
				$overlaps = true;
				break;
			}
			$newest = max($newest, $seen);
			if ($handle === '' && ($item['post']['author']['did'] ?? '') === $watch->did) {
				$handle = strtolower((string)($item['post']['author']['handle'] ?? ''));
			}
			$stored += $this->store->storeFeedItem($item);
		}

		return [$stored, $overlaps ? $newest : '', $handle];
	}

	/**
	 * The moment a feed item was indexed: the repost's for a repost, the
	 * post's otherwise — the order the feed is in.
	 */
	public static function timeOf(array $item): string {
		$reason = $item['reason'] ?? null;
		if (is_array($reason) && is_string($reason['indexedAt'] ?? null)) {
			return $reason['indexedAt'];
		}

		return (string)($item['post']['indexedAt'] ?? '');
	}

	/**
	 * How many refusals naming the account's status the watch has had since
	 * its last successful read, kept as a prefix of its last error.
	 */
	public static function statusAnswers(Watch $watch): int {
		return preg_match('/^\[status (\d+)\] /', $watch->lastError, $m) === 1 ? (int)$m[1] : 0;
	}

	private static function withStatusAnswers(int $answers, string $error): string {
		return $answers > 0 ? '[status ' . $answers . '] ' . $error : $error;
	}

	/** How long after a failed read the next is: doubling with the failures, up to the maximum. */
	private function failureBackoff(Watch $watch): int {
		return min(self::MAX_BACKOFF, self::INTERVAL * (1 << min(10, $watch->failures + 1)));
	}

	private function nextInterval(Watch $watch, bool $hadPosts): int {
		if ($hadPosts || $watch->lastSync === 0) {
			return self::INTERVAL;
		}
		$last = max(self::INTERVAL, $watch->nextSync - $watch->lastSync);

		return min(self::MAX_BACKOFF, $last * 2);
	}

	/**
	 * The labelers the AppView is asked to label for: Bluesky's and the ones
	 * people here subscribe to, once per pass.
	 *
	 * @return array<string, string>
	 */
	private function labelHeaders(): array {
		return ['atproto-accept-labelers' => $this->acceptLabelers ??= $this->labelers->acceptHeader()];
	}
}
