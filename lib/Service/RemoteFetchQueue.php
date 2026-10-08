<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Cron\ResolveActor;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The remote fetches a page would like done and must not wait for.
 *
 * Rendering a page used to fetch what was missing from it over HTTP, one
 * actor at a time and at the federation timeout each, so one slow server
 * held a reader's whole timeline. A page now shows what is known here and
 * hands the rest to this, which queues a background job per missing piece;
 * the next look at the page has it.
 *
 * A job is queued once per argument (`IJobList::has()`), so a busy page asked
 * for a hundred times before cron runs is still one job per actor, and one
 * call queues at most `MAX_PER_CALL`, so a page cannot fill the job list.
 */
class RemoteFetchQueue {
	/** The most jobs one call queues; more than one page of anything names. */
	public const MAX_PER_CALL = 20;

	public function __construct(
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Queues a fetch of each actor not cached here.
	 *
	 * @param string[] $ids actor ids; what is not an http(s) URL is ignored
	 */
	public function resolveActors(array $ids): void {
		$wanted = [];
		foreach ($ids as $id) {
			$anchor = strpos($id, '#');
			if ($anchor !== false) {
				$id = substr($id, 0, $anchor);
			}

			if (preg_match('#^https?://#i', $id) === 1) {
				$wanted[$id] = $id;
			}
		}

		foreach (array_slice(array_values($wanted), 0, self::MAX_PER_CALL) as $id) {
			$this->queue(ResolveActor::class, ['id' => $id]);
		}
	}

	/**
	 * @param class-string $job
	 * @param array<string, string> $argument
	 */
	private function queue(string $job, array $argument): void {
		try {
			if (!$this->jobList->has($job, $argument)) {
				$this->jobList->add($job, $argument);
			}
		} catch (Throwable $e) {
			// the page is served either way; what is lost is the fetch, which
			// the next look at the page asks for again
			$this->logger->info('[RemoteFetchQueue] could not queue ' . $job, [
				'argument' => $argument, 'exception' => $e,
			]);
		}
	}
}
