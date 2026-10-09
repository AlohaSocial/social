<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Discovery;

use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Brings the new posts with every hashtag somebody here follows to this
 * server, wherever they were written (`PostDiscoveryService::fillTag()`,
 * every network), so a followed hashtag fills the home timeline with more
 * than what happened to arrive. Run by `Cron\FillFollowedTags`.
 *
 * Nothing is written for the home timeline: a post with a followed tag is
 * there because its stored tags meet the follow when the timeline is read
 * (`StreamRequest::followedTagNids()`), with the blocks, mutes and filters
 * every home post goes through — so a stored post is in it like any other.
 *
 * Bounded so a followed tag cannot flood a timeline or the server: `TAGS`
 * tags a run, the ones read longest ago first; `LIMIT` posts a tag from each
 * network; only posts written since the tag was last read, and none older
 * than `WINDOW`, which is also all a first read goes back. A tag a page has
 * just had read (`RemoteFetchQueue::claimPostsFill()`) waits for the next
 * run. What an administrator turned off — Bluesky for the instance, the
 * servers the directory asks — each source leaves out itself.
 */
class FollowedTagsFill {
	/** how many tags one run reads */
	public const TAGS = 10;
	/** the most posts one tag stores from each network in one run */
	public const LIMIT = 20;
	/** seconds: how far back a read goes at most, and a first read at all */
	public const WINDOW = 2 * 86400;
	/** seconds a read reaches back before the last one, for posts the network had not shown yet */
	private const OVERLAP = 600;

	public function __construct(
		private FollowedTagsRequest $followedTags,
		private PostDiscoveryService $discovery,
		private RemoteFetchQueue $remoteFetchQueue,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the followed tags that are due.
	 *
	 * @return int how many posts were stored
	 */
	public function run(): int {
		$stored = 0;
		foreach ($this->followedTags->dueForFill(self::TAGS) as $due) {
			try {
				$stored += $this->fill($due['hashtag'], $due['filled']);
			} catch (Throwable $e) {
				$this->logger->info('Posts with a followed hashtag not read', ['hashtag' => $due['hashtag'], 'exception' => $e]);
			}
		}

		return $stored;
	}

	/**
	 * Reads one followed tag: the posts written since `$lastFilled` (a Unix
	 * time, 0 for never), and stamps it read.
	 *
	 * @return int how many posts were stored
	 */
	public function fill(string $tag, int $lastFilled = 0): int {
		$tag = FollowedTagsRequest::normalise($tag);
		if ($tag === '') {
			return 0;
		}
		$now = $this->time->getTime();
		$stored = 0;
		if ($this->remoteFetchQueue->claimPostsFill(PostDiscoveryService::TAG, $tag)) {
			$stored = $this->discovery->fillTag($tag, self::LIMIT, $this->since($lastFilled, $now));
		}
		$this->followedTags->markFilled($tag, $now);

		return $stored;
	}

	private function since(int $lastFilled, int $now): int {
		return max($lastFilled - self::OVERLAP, $now - self::WINDOW);
	}
}
