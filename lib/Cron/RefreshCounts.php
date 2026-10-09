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
 * Asks what the counts of some posts somebody just saw are now, where they
 * live (`CountService::refresh()`). Queued by `RemoteFetchQueue::refreshCounts()`
 * when a page shows them, so — like `FillInteractions` — it is not registered
 * in `info.xml`.
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
		$ids = [];
		foreach (is_array($posts) ? $posts : [] as $id) {
			if (is_string($id) && $id !== '') {
				$ids[] = $id;
			}
		}
		if ($ids !== []) {
			$this->counts->refresh($ids);
		}
	}
}
