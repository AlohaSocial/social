<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\ConfigService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Hashtag-interest learning becomes opt-in on instances that already stored
 * the old default.
 *
 * The For you card writes every one of its values on save, so an instance
 * whose administrator saved it for any reason holds `interests_default = 1`
 * although nobody chose it. That stored value is removed once, and the
 * default (`0`) applies. Readers who chose for themselves keep their choice,
 * and learned interests are not touched. A stored `0` is left alone, so
 * running this again changes nothing.
 */
class Version1000Date20261108400000 extends SimpleMigrationStep {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param Closure(): \OCP\DB\ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$key = ConfigService::SOCIAL_INTERESTS_DEFAULT;
		if ($this->appConfig->getValueString(Application::APP_ID, $key, '0') !== '0') {
			$this->appConfig->deleteKey(Application::APP_ID, $key);
		}
	}
}
