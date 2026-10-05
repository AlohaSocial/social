<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\Atproto\AtprotoEgress;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/** Retries a failed native mirror independently of the local post lifecycle. */
class AtprotoMirrorJob extends QueuedJob {
	private const MAX_ATTEMPTS = 8;

	public function __construct(
		ITimeFactory $time,
		private AtprotoEgress $egress,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/** @param mixed $argument */
	#[\Override]
	protected function run($argument): void {
		if (!is_array($argument) || !is_array($argument['post'] ?? null)) {
			return;
		}
		$operation = (string)($argument['operation'] ?? '');
		if (!in_array($operation, ['publish', 'update', 'delete'], true)) {
			return;
		}
		$post = new Stream();
		$post->importFromLocal($argument['post']);
		try {
			match ($operation) {
				'publish' => $this->egress->publish($post),
				'update' => $this->egress->update($post),
				'delete' => $this->egress->delete($post),
			};
		} catch (Throwable $e) {
			$attempt = (int)($argument['attempt'] ?? 0) + 1;
			if ($attempt < self::MAX_ATTEMPTS) {
				$this->jobList->add(self::class, [
					'operation' => $operation,
					'post' => $argument['post'],
					'attempt' => $attempt,
				]);
				$this->logger->warning('queued another AT-Proto mirror attempt', [
					'operation' => $operation, 'attempt' => $attempt, 'exception' => $e,
				]);
				return;
			}
			$this->logger->error('AT-Proto mirror retries exhausted', [
				'operation' => $operation, 'exception' => $e,
			]);
		}
	}
}
