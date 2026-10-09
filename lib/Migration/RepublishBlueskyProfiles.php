<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Service\ConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Has every Bluesky profile written once more, off the upgrade: the profiles
 * published so far named the account by its username rather than by the
 * name it federates. Queued, one profile per account that is on Bluesky;
 * the marker keeps a later upgrade from queueing them again.
 */
class RepublishBlueskyProfiles implements IRepairStep {
	private const MARKER = 'migration_bluesky_profiles_named';
	private const PAGE = 1000;

	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private IJobList $jobList,
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Write the Bluesky profiles again with the name each account federates';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if ($this->configService->getAppValueInt(self::MARKER) === 1) {
			return;
		}
		$queued = 0;
		if ($this->config->isEnabled()) {
			$offset = 0;
			do {
				$identities = $this->identities->getAll(self::PAGE, $offset);
				foreach ($identities as $identity) {
					if ($identity->isActive()) {
						$this->jobList->add(AtprotoPublish::class, ['action' => 'profile', 'id' => $identity->actorId]);
						$queued++;
					}
				}
				$offset += self::PAGE;
			} while (count($identities) === self::PAGE);
		}
		$this->configService->setAppValue(self::MARKER, '1');
		if ($queued > 0) {
			$output->info(sprintf('queued %d Bluesky profile(s) to be written again', $queued));
		}
	}
}
