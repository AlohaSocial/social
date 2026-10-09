<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\Discovery\FollowedTagsFill;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Every quarter of an hour, the new posts with the hashtags people here
 * follow, read from beyond this server (`FollowedTagsFill`).
 */
class FillFollowedTags extends TimedJob {
	private const INTERVAL = 900;

	public function __construct(
		ITimeFactory $time,
		private FollowedTagsFill $fill,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		$this->fill->run();
	}
}
