<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\Counts\CountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Asks the origin servers for the current counts of posts somebody just saw
 * (`CountService::refresh()`). Queued by `RemoteFetchQueue::refreshCounts()`,
 * so not in `info.xml`.
 */
class RefreshCounts extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private CountService $counts,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$posts = is_array($argument) ? ($argument['posts'] ?? null) : null;
		$force = is_array($argument) && ($argument['force'] ?? false) === true;
		$ids = [];
		foreach (is_array($posts) ? $posts : [] as $id) {
			if (is_string($id) && $id !== '') {
				$ids[] = $id;
			}
		}
		if ($ids !== []) {
			$this->counts->refresh($ids, $force);
		}
	}
}
