<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\Thread\ThreadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Reads the rest of one conversation (`ThreadService`). Queued with the post
 * id by `RemoteFetchQueue::fillThread()` when somebody opens the post, so —
 * like `SyncRemoteTimeline` — it is not registered in `info.xml`.
 */
class FillThread extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private ThreadService $threads,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$id = is_array($argument) ? (string)($argument['post'] ?? '') : '';
		if ($id !== '') {
			$this->threads->fill($id);
		}
	}
}
