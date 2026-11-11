<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\SanitizerUpgrade;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Every five minutes, up to 10,000 stored posts and as many cached bios
 * brought up to the current sanitizer (`SanitizerUpgrade`); once both walks
 * are finished a run reads one app config value per table and stops.
 */
class Resanitize extends TimedJob {
	private const INTERVAL = 300;
	private const PAGES = 20;

	public function __construct(
		ITimeFactory $time,
		private SanitizerUpgrade $upgrade,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		$this->upgrade->run(self::PAGES);
	}
}
