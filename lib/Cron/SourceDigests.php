<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\SourceDigestBackfill;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Every five minutes, up to 10,000 stored posts given the `source_digest`
 * their reads use (`SourceDigestBackfill`); once the walk is finished a run
 * reads two app config values and stops.
 */
class SourceDigests extends TimedJob {
	private const INTERVAL = 300;
	private const PAGES = 20;

	public function __construct(
		ITimeFactory $time,
		private SourceDigestBackfill $backfill,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		$this->backfill->run(self::PAGES);
	}
}
