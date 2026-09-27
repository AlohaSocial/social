<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Class MiscService
 *
 * @package OCA\Social\Service
 */
class MiscService {
	private LoggerInterface $logger;

	public function __construct(LoggerInterface $logger) {
		$this->logger = $logger;
	}

	/**
	 * @param $message
	 * @param int $level
	 */
	public function log(string $message, $level = 2) {
		$data = [
			'app' => Application::APP_ID,
			'level' => $level
		];

		$this->logger->log($level, $message, $data);
	}

	/**
	 * @return int
	 */
	public function getNcVersion(): int {
		$ver = Util::getVersion();

		return $ver[0];
	}
}
