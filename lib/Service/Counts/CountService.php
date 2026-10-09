<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Counts;

use OCA\Social\Atproto\Reader\BlueskyCountSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The likes, boosts and replies of a post that lives elsewhere, kept current
 * wherever it lives.
 *
 * A post from another server arrives with the counts it had at that moment,
 * and nothing tells this server when they move. So the posts somebody looks
 * at are asked about again: a status, a conversation or a page of a timeline
 * hands its remote posts to `seen()`, which only queues — the page does no
 * network I/O — a background read (`Cron\RefreshCounts`) of the ones whose
 * counts are older than their interval: a quarter of an hour for a post less
 * than a day old, six hours after that. Each network the post may be on is
 * a `CountSource`; what it answers is stored in the post's row
 * (`CountWriter`), so every reader sees it, and `counts_at` says when it
 * was asked. The cron's pass over posts nobody looked at
 * (`RemoteCountService`) goes through `refreshPosts()` too.
 */
class CountService {
	/** how old a post is while it is asked about often */
	public const YOUNG = 86400;
	public const YOUNG_INTERVAL = 900;
	public const OLD_INTERVAL = 21600;

	public function __construct(
		private StreamRequest $streams,
		private CountWriter $writer,
		private RemoteFetchQueue $queue,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * A page showed these posts: the remote ones whose counts are due are
	 * queued to be asked about (`RemoteFetchQueue::refreshCounts()`, which
	 * caps a call). A boost stands for the post it boosts.
	 *
	 * @param iterable<mixed> $posts
	 * @return bool whether a read was asked for
	 */
	public function seen(iterable $posts): bool {
		$now = $this->time->getTime();
		$due = [];
		foreach ($posts as $post) {
			if ($post instanceof Stream && $post->getType() === Announce::TYPE && $post->hasObject()) {
				$post = $post->getObject();
			}
			if ($post instanceof Stream && $this->isDue($post, $now)) {
				$due[$post->getId()] = self::interval($post, $now);
			}
		}

		return $due !== [] && $this->queue->refreshCounts($due);
	}

	/** How long a post's counts stand before it is asked about again, in seconds. */
	public static function interval(Stream $post, int $now): int {
		return ($now - $post->getPublishedTime() < self::YOUNG) ? self::YOUNG_INTERVAL : self::OLD_INTERVAL;
	}

	/**
	 * Whether a post is one whose counts are kept elsewhere and were last
	 * asked about longer ago than its interval. A direct message has no
	 * counts to speak of.
	 */
	public function isDue(Stream $post, int $now): bool {
		if ($post->isLocal() || $post->getId() === '' || $post->getVisibility() === Stream::TYPE_DIRECT) {
			return false;
		}
		$at = $post->getCountsAt();

		return $at === null || $now - $at >= self::interval($post, $now);
	}

	/**
	 * The background read (`Cron\RefreshCounts`) of posts that were seen:
	 * the ones still due — a page seen twice, or the cron, may have asked
	 * already.
	 *
	 * @param string[] $postIds
	 * @return array{asked: int, answered: int}
	 */
	public function refresh(array $postIds): array {
		$now = $this->time->getTime();
		$posts = [];
		foreach (array_unique($postIds) as $id) {
			try {
				$post = $this->streams->getStreamById($id);
			} catch (StreamNotFoundException) {
				continue;
			}
			if ($this->isDue($post, $now)) {
				$posts[] = $post;
			}
		}

		return $this->refreshPosts($posts);
	}

	/**
	 * Asks each post's network about it now, whatever was last asked. A post
	 * no network answers for is stamped all the same, so it is not handed
	 * over again before its interval is out.
	 *
	 * @param Stream[] $posts
	 * @return array{asked: int, answered: int}
	 */
	public function refreshPosts(array $posts): array {
		$left = [];
		foreach ($posts as $post) {
			$left[$post->getId()] = $post;
		}
		$asked = count($left);
		$answered = 0;
		foreach ($this->sources() as $source) {
			$mine = array_filter($left, static fn (Stream $post): bool => $source->supports($post));
			if ($mine === []) {
				continue;
			}
			$left = array_diff_key($left, $mine);
			try {
				$answered += $source->refresh(array_values($mine));
			} catch (Throwable $e) {
				$this->logger->info('Counts not read', ['source' => $source::class, 'exception' => $e]);
				$this->stampAll($mine);
			}
		}
		$this->stampAll($left);

		return ['asked' => $asked, 'answered' => $answered];
	}

	/**
	 * @param array<string, Stream> $posts
	 */
	private function stampAll(array $posts): void {
		foreach (array_keys($posts) as $id) {
			try {
				$this->writer->stamp((string)$id);
			} catch (Throwable $e) {
				$this->logger->info('Counts not stamped', ['post' => $id, 'exception' => $e]);
			}
		}
	}

	/**
	 * @return list<CountSource>
	 */
	private function sources(): array {
		$sources = [];
		foreach ([ActivityPubCountSource::class, BlueskyCountSource::class] as $class) {
			$source = $this->container?->get($class);
			if ($source instanceof CountSource) {
				$sources[] = $source;
			}
		}

		return $sources;
	}
}
