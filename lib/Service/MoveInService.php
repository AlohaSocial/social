<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moving here from another server, starting from nothing but the old handle.
 *
 * Moving in used to be six steps across two servers and five files: an
 * alias to paste as an actor URL, four CSVs to download there and upload
 * here, an archive for the posts. The old server already publishes most of
 * what those files held — the `following` collection and the public
 * `outbox` of the account — and this app already fetches remote documents
 * with the origin checks a fetch needs. This reads them.
 *
 * `inspect()` says what the old account is and what can be read off it, so
 * the person sees who they are about to copy before anything happens.
 * `prepare()` sets the alias and returns what the run needs to know;
 * `run()` is the background half, under `ImportQueueService`: it follows
 * everyone the old account follows and writes its public posts here as the
 * new account's own, through the very same `FollowService` and
 * `PostImportService` paths the file imports use, so nothing is federated
 * that those did not federate.
 *
 * What it cannot do is move the followers: that is the old server's `Move`,
 * and the page says which menu to find it in once this has run. A server
 * that hides its collections (Mastodon's "hide your social graph", Pixelfed
 * always) answers with a count and no page; the CSV and the archive remain
 * for those, and `inspect()` says so rather than letting a run come back
 * empty.
 */
class MoveInService {
	/** The most accounts one run follows; a bigger list is a different kind of account. */
	public const MAX_FOLLOWS = 5000;
	/** The most posts one run brings over. */
	public const MAX_POSTS = 5000;
	/** The most pages one collection walk fetches, whatever they claim to hold. */
	public const MAX_PAGES = 500;
	/** How many failed follows the report names; the count is kept whole. */
	public const REPORT_FAILURES = 50;

	public function __construct(
		private CacheActorService $cacheActorService,
		private CurlService $curlService,
		private FollowService $followService,
		private PostImportService $postImportService,
		private MigrationService $migrationService,
		private MoveFinishService $moveFinishService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The old account, and what its server lets this one read off it.
	 *
	 * @return array{id: string, acct: string, name: string, url: string, avatar: string,
	 *               following: array{total: int, readable: bool},
	 *               posts: array{total: int, readable: bool}, finishable: bool}
	 * @throws InvalidResourceException when the handle names nobody
	 */
	public function inspect(string $handle): array {
		$actor = $this->migrationService->resolveActor($handle);

		return [
			'id' => $actor->getId(),
			'acct' => $actor->getAccount(),
			'name' => $actor->getName() !== '' ? $actor->getName() : $actor->getPreferredUsername(),
			'url' => $actor->getUrl() !== '' ? $actor->getUrl() : $actor->getId(),
			'avatar' => $actor->getAvatar(),
			'following' => $this->describe($actor->getFollowing()),
			'posts' => $this->describe($actor->getOutbox()),
			// the old server is this app too, so the last step can be done from here
			'finishable' => $this->moveFinishService->canFinish($actor),
		];
	}

	/**
	 * Names the old account as this one's alias and says what the run is for.
	 *
	 * The alias first and synchronously: it is what the old server will ask
	 * for before it accepts a `Move` towards this account, and it federates
	 * nothing, so there is no reason to make the person wait for it.
	 *
	 * @return array<string, mixed> the options the queued run reads
	 * @throws InvalidResourceException when the handle names nobody, or names this account
	 */
	public function prepare(string $userId, string $handle, bool $follows, bool $posts, bool $fetchMedia): array {
		$actor = $this->migrationService->resolveActor($handle);
		$this->migrationService->addAlias($userId, $actor->getId());

		return [
			'source' => $actor->getId(),
			'acct' => $actor->getAccount(),
			'follows' => $follows,
			'posts' => $posts,
			'fetch_media' => $fetchMedia,
			'finishable' => $this->moveFinishService->canFinish($actor),
		];
	}

	/**
	 * The background half: follows who the old account follows, brings its
	 * public posts over, and says what came of each.
	 *
	 * @param array<string, mixed> $options what prepare() returned
	 * @param callable(int, int): void $progress told how many steps are done, of how many
	 *
	 * @return array<string, mixed> the report the row keeps
	 */
	public function run(Person $actor, array $options, callable $progress): array {
		$source = $this->cacheActorService->getFromId((string)($options['source'] ?? ''), true);
		$report = [
			'source' => $source->getAccount(),
			'followed' => 0, 'skipped' => 0, 'failed' => 0, 'failures' => [],
			'imported' => 0, 'already' => 0, 'posts_skipped' => 0, 'posts_failed' => 0, 'post_failures' => [], 'media' => 0,
			'following_readable' => false, 'posts_readable' => false,
		];

		$follows = !empty($options['follows']) ? $this->walk($source->getFollowing(), self::MAX_FOLLOWS) : [];
		$items = !empty($options['posts']) ? $this->walk($source->getOutbox(), self::MAX_POSTS) : [];
		$report['following_readable'] = $follows !== [];
		$report['posts_readable'] = $items !== [];

		$total = count($follows) + count($items);
		$done = 0;
		$progress($done, $total);

		foreach ($follows as $entry) {
			$id = self::idOf($entry);
			try {
				if ($id === '' || $id === $actor->getId()) {
					$report['skipped']++;
				} elseif ($this->followService->followActor($actor, $this->cacheActorService->getFromId($id, true))) {
					$report['followed']++;
				} else {
					// already followed from here
					$report['skipped']++;
				}
			} catch (FollowSameAccountException $e) {
				$report['skipped']++;
			} catch (Throwable $e) {
				$report['failed']++;
				if (count($report['failures']) < self::REPORT_FAILURES) {
					$report['failures'][$id] = $e->getMessage();
				}
				$this->logger->notice('[MoveInService] cannot follow an account the old one follows', [
					'actor' => $actor->getId(), 'target' => $id, 'exception' => $e,
				]);
			}
			$progress(++$done, $total);
		}

		if ($items !== []) {
			$base = $done;
			$tally = $this->postImportService->importItems(
				$actor, $items, (bool)($options['fetch_media'] ?? true),
				static function (int $handled) use ($progress, $base, $total): void {
					$progress($base + $handled, $total);
				}
			);
			$report['imported'] = $tally['imported'];
			$report['already'] = $tally['already'];
			$report['posts_skipped'] = $tally['skipped'];
			$report['posts_failed'] = $tally['failed'];
			$report['post_failures'] = $tally['failures'];
			$report['media'] = $tally['media'];
		}

		$progress($total, $total);

		return $report;
	}

	/**
	 * @return array{total: int, readable: bool}
	 */
	private function describe(string $url): array {
		if ($url === '') {
			return ['total' => 0, 'readable' => false];
		}

		try {
			$collection = $this->curlService->retrieveObject($url);
		} catch (Throwable $e) {
			return ['total' => 0, 'readable' => false];
		}

		$total = (int)($collection['totalItems'] ?? 0);
		$items = $collection['orderedItems'] ?? $collection['items'] ?? [];
		$first = $collection['first'] ?? null;

		// a collection with items, or with a page to turn to, can be read;
		// one that answers a count alone is hidden on purpose
		$readable = (is_array($items) && $items !== []) || is_string($first) || is_array($first);

		return ['total' => $total, 'readable' => $readable];
	}

	/**
	 * Every entry of a collection, page after page, up to $max of them.
	 *
	 * Follows `first` and then `next` the way every ActivityPub server
	 * publishes them; a `first` that is a page already is read as one. Stops
	 * at `$max` entries or `MAX_PAGES` pages, whichever comes first, because
	 * a server that answers `next` for ever is a thing that exists.
	 *
	 * @return array<int, mixed> the entries as listed: ids, or objects
	 */
	private function walk(string $url, int $max): array {
		if ($url === '') {
			return [];
		}

		$entries = [];
		try {
			$page = $this->curlService->retrieveObject($url);
			$pages = 0;
			while ($pages < self::MAX_PAGES) {
				$pages++;
				$items = $page['orderedItems'] ?? $page['items'] ?? [];
				foreach (is_array($items) ? $items : [] as $item) {
					$entries[] = $item;
					if (count($entries) >= $max) {
						return $entries;
					}
				}

				$next = $page['next'] ?? ($items === [] ? ($page['first'] ?? null) : null);
				if (is_array($next)) {
					$page = $next;
					continue;
				}
				if (!is_string($next) || $next === '') {
					break;
				}
				$page = $this->curlService->retrieveObject($next);
			}
		} catch (Throwable $e) {
			// what was read before the server stopped answering is kept: half
			// of a following list is better than none, and the report says so
			$this->logger->notice('[MoveInService] a collection could not be read to its end', [
				'url' => $url, 'read' => count($entries), 'exception' => $e,
			]);
		}

		return $entries;
	}

	/** An entry is an id, or an object that has one. */
	private static function idOf(mixed $entry): string {
		$id = match (true) {
			is_string($entry) => $entry,
			is_array($entry) => (string)($entry['id'] ?? ''),
			default => '',
		};

		return str_starts_with($id, 'http') ? $id : '';
	}
}
