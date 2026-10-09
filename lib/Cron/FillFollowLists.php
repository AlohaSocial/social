<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\FollowList\FollowListService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Reads who follows one account on another server, or whom it follows, on
 * the network it lives on (`FollowListService::fill()`). Queued by
 * `RemoteFetchQueue::fillFollowList()` when somebody looks, so — like
 * `FillInteractions` — it is not registered in `info.xml`.
 */
class FillFollowLists extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private FollowListService $followLists,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$actor = is_array($argument) ? (string)($argument['actor'] ?? '') : '';
		$direction = is_array($argument) ? (string)($argument['direction'] ?? '') : '';
		if ($actor !== '' && $direction !== '') {
			$this->followLists->fill($actor, $direction);
		}
	}
}
