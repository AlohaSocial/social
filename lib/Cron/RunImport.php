<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\ImportQueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Runs one import an account asked for.
 *
 * Queued by `ImportQueueService::queue()` with the row's id, so — like the
 * other on-demand jobs — it is not in `info.xml`: it means nothing without
 * the row. Everything about the run, including how a failure is recorded,
 * is the service's; this only hands it the id.
 */
class RunImport extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private ImportQueueService $importQueueService,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument): void {
		$id = is_array($argument) ? (int)($argument['import'] ?? 0) : 0;
		if ($id <= 0) {
			return;
		}

		$this->importQueueService->run($id);
	}
}
