<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateInterval;
use DateTime;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\Counts\CountService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Refreshes the like, boost and reply counts of remote posts by asking where
 * each post lives, on the cron's schedule and budget. Counts are not
 * federated, so a stored count is only as fresh as the last time it was asked.
 *
 * Posts being looked at are asked as they are seen
 * (`Counts\CountService::seen()`); this pass covers the rest of the last
 * week's remote posts that are due (`CountService::refreshPosts()`), and this
 * instance's own posts on Bluesky, whose likes there may be missed
 * (`Atproto\Reader\BlueskyPublishedCountSource`). A post is stamped whether
 * or not it answered, so a dead host is asked once per interval.
 */
class RemoteCountService {
	/**
	 * How long one post's counts stand before they are asked again, in
	 * seconds: two hours, ten twelve-minute passes, so the batch size is
	 * what limits each pass.
	 */
	public const TTL = 7200;

	/**
	 * Posts asked per pass, when the caller sets no deadline of its own: this
	 * many remote posts, and as many of this instance's own posts that are on
	 * Bluesky too.
	 */
	public const BATCH = 50;

	/**
	 * How long a post is asked about by this pass, in seconds: a remote post,
	 * and one of this instance's own posts on Bluesky. Most of what a post
	 * collects comes in its first days; after that it is asked about when
	 * somebody looks at it.
	 */
	public const PUBLISHED_WINDOW = 7 * 86400;

	/** Posts handed over at once; the deadline is checked between them. */
	public const PARALLEL = 20;

	public function __construct(
		private StreamRequest $streamRequest,
		private CountService $countService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param bool $force ask every post now, whatever was last said
	 * @param int $limit how many posts of each kind to ask; 0 is every one that is due
	 * @param ?int $deadline wall clock past which this run stops asking
	 *
	 * @return array{asked: int, answered: int} the posts whose schedule was
	 *                                          written, and of those, the ones that answered with a count
	 */
	public function refresh(bool $force = false, int $limit = self::BATCH, ?int $deadline = null): array {
		$due = new DateTime('now');
		if (!$force) {
			$due->sub(new DateInterval('PT' . intdiv(self::TTL, 60) . 'M'));
		}

		$since = new DateTime('now');
		$since->sub(new DateInterval('PT' . intdiv(self::PUBLISHED_WINDOW, 3600) . 'H'));
		$posts = [
			// a forced run is the backfill, which asks about every remote post
			...$this->streamRequest->getRemoteStreamsDueForCounts($due, $force ? null : $since, $limit),
			...$this->streamRequest->getPublishedStreamsDueForCounts($due, $since, $limit),
		];

		$asked = 0;
		$answered = 0;
		foreach (array_chunk($posts, self::PARALLEL) as $chunk) {
			if ($deadline !== null && $this->time->getTime() >= $deadline) {
				// the rest are still due and the next pass starts with them
				break;
			}

			$result = $this->countService->refreshPosts($chunk);
			$asked += $result['asked'];
			$answered += $result['answered'];
		}

		if ($asked > 0 && $answered === 0) {
			// nothing was read at all, which is never one host being down: the
			// fetch itself is broken, so it is reported
			$this->logger->debug(
				'[RemoteCountService] none of ' . $asked . ' posts answered with a count'
			);
		}

		return ['asked' => $asked, 'answered' => $answered];
	}
}
