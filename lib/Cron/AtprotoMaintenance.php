<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Reader\DeletionSweep;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoClientRequest;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Bluesky housekeeping: publishes what the listener missed, resends
 * PLC operations the directory never confirmed, prunes the firehose past
 * its replay window, drops retired keys past theirs, and removes a page of
 * the Bluesky posts read here that were deleted there.
 */
class AtprotoMaintenance extends TimedJob {
	private const INTERVAL = 5 * 60;

	public function __construct(
		ITimeFactory $time,
		private AtprotoConfig $config,
		private Publisher $publisher,
		private IdentityService $identities,
		private EventService $events,
		private InstanceKeyService $instanceKeys,
		private DeletionSweep $deletions,
		private AtprotoClientRequest $clients,
		private AtprotoOAuthRequest $oauth,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		if (!$this->config->isEnabled()) {
			return;
		}
		foreach ([
			'reconcile' => fn (): int => $this->publisher->reconcile(),
			'repair' => fn (): int => $this->identities->repair(),
			'prune events' => fn (): int => $this->events->prune(),
			'prune keys' => fn (): int => $this->instanceKeys->pruneRetired($this->time->getTime()),
			'deleted on Bluesky' => fn (): int => $this->deletions->run(),
			'expired app sessions' => fn (): int => $this->clients->pruneSessions($this->time->getTime()),
			'expired OAuth requests and sessions' => fn (): int => $this->oauth->prune($this->time->getTime()),
		] as $step => $run) {
			try {
				$run();
			} catch (Throwable $e) {
				$this->logger->warning('Bluesky maintenance step failed: ' . $step, ['exception' => $e]);
			}
		}
	}
}
