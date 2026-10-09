<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\Discovery\PostDiscoveryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Reads the posts with a hashtag, or matching a search, from the networks
 * beyond this server (`PostDiscoveryService`). Queued by
 * `RemoteFetchQueue::fillPosts()` when somebody looks, so — like
 * `FillThread` — it is not registered in `info.xml`.
 */
class FillPosts extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private PostDiscoveryService $discovery,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$kind = is_array($argument) ? (string)($argument['kind'] ?? '') : '';
		$term = is_array($argument) ? (string)($argument['term'] ?? '') : '';
		if ($kind !== '' && $term !== '') {
			$this->discovery->fill($kind, $term, is_array($argument) ? (string)($argument['viewer'] ?? '') : '');
		}
	}
}
