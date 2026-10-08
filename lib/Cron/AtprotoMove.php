<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Move\MoveAwayService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Carries a move of a Bluesky account away on (§13.2): the repository, the
 * blobs, the preferences, the DID, the activation — whatever is left of it.
 */
class AtprotoMove extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private MoveAwayService $moves,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$moveId = is_array($argument) ? (int)($argument['move'] ?? 0) : 0;
		if ($moveId > 0) {
			$this->moves->run($moveId);
		}
	}
}
