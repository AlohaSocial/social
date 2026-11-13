<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCA\Social\AlohaDirectory\DirectoryLists;
use OCA\Social\AlohaDirectory\DirectoryParticipation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Aloha Directory, every five minutes: the signed lists once a day, and,
 * for a server that takes part, the outcome of a verification, the hourly
 * heartbeat, profile changes and the status.
 *
 * On a server that neither uses the curated search list nor takes part, a run
 * reads two app settings and stops.
 */
class AlohaDirectory extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ?DirectoryConfig $config = null,
		private ?DirectoryLists $lists = null,
		private ?DirectoryParticipation $participation = null,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($time);

		$this->setInterval(300);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument) {
		if ($this->config === null || $this->lists === null || $this->participation === null) {
			return;
		}

		try {
			if (($this->config->usesAlohaList() || $this->config->advertises()) && $this->lists->refreshDue()) {
				$this->lists->refresh();
			}
			if ($this->participation->phase() !== DirectoryParticipation::PHASE_NONE) {
				$this->participation->sync();
			}
		} catch (Throwable $e) {
			$this->logger?->warning('the Aloha Directory job failed', ['exception' => $e]);
		}
	}
}
