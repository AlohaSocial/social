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
 * Asks where each remote post lives how many likes, boosts and replies it has
 * now, on the cron's schedule and in its budget.
 *
 * The counts a stored post carries were read off its document the once, when
 * it arrived, and every server that publishes them publishes them as they were
 * at that moment: a status with five likes said five for as long as the row
 * lasted, and one answered here had a reply count that never moved. Nothing
 * federates a count — a `Like` travels to the origin and the origin totals it
 * on its own side — so the only way to know a number now is to ask again.
 *
 * The posts somebody looks at are asked about as they are seen
 * (`Counts\CountService::seen()`); this is the pass over the rest: the posts
 * whose last answer has fallen due, a bounded batch of them, each asked of
 * its own network (`CountService::refreshPosts()`). A post is stamped
 * whether or not the answer arrived, so a host that is gone is asked once per
 * interval rather than on every pass, and whatever was already stored stands
 * where the answer cannot be trusted.
 */
class RemoteCountService {
	/**
	 * How long one post's counts stand before they are asked again, in seconds.
	 *
	 * An hour would be tighter but does not divide the pass well: at twelve
	 * minutes a pass, two hours is ten passes for a post to fall due in, which
	 * is where the batch below stops being the limit on how much gets asked.
	 */
	public const TTL = 7200;

	/** Posts asked per pass, when the caller sets no deadline of its own. */
	public const BATCH = 50;

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
	 * @param bool $force ask every remote post now, whatever was last said
	 * @param int $limit how many posts to ask; 0 is every one that is due
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

		$posts = $this->streamRequest->getRemoteStreamsDueForCounts($due, $limit);

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
			// nothing was read at all, which is never one host being down: a
			// fetch that has stopped working, so it should say so rather than
			// leave every count quietly where it was
			$this->logger->debug(
				'[RemoteCountService] none of ' . $asked . ' posts answered with a count'
			);
		}

		return ['asked' => $asked, 'answered' => $answered];
	}
}
