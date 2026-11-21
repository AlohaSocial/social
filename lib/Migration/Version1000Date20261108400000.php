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
 * Makes hashtag-interest learning opt-in on instances that stored
 * `interests_default = 1` only because the For you card writes every value on
 * save. The stored value is removed once and the default (`0`) applies;
 * readers' own choices, learned interests and a stored `0` are left alone.
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
