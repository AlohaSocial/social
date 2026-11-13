<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AlohaDirectory;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ExternalSignupsRequest;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ExternalUserService;
use OCA\Social\Service\FederationHealthService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ServerVersion;

/**
 * The hourly heartbeat of concept 6.3: whether this server takes newcomers,
 * how many free accounts it has, how long the approval queue is, and whether
 * it federates. The directory publishes only buckets of these numbers.
 *
 * The signup state is the one `/api/v2/instance` reports too; the directory
 * compares the two.
 */
class HeartbeatBuilder {
	public function __construct(
		private DirectoryConfig $config,
		private ExternalUserService $externalUserService,
		private ExternalSignupsRequest $signupsRequest,
		private InstanceStatsRequest $instanceStatsRequest,
		private FederationHealthService $federationHealth,
		private AtprotoConfig $atprotoConfig,
		private IAppConfig $appConfig,
		private ServerVersion $serverVersion,
		private ITimeFactory $time,
		private ConfigService $configService,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build(string $domain, string $profileEtag): array {
		$free = $this->externalUserService->freeAccounts();
		$cap = $this->config->advertisedAccounts();
		if ($cap !== null) {
			$free = min($free, $cap);
		}

		$heartbeat = [
			'domain' => $domain,
			'software' => [
				'name' => Application::SOFTWARE_NAME,
				'version' => $this->appConfig->getValueString(Application::APP_ID, 'installed_version', '0.0.0'),
				'nextcloud' => $this->serverVersion->getVersionString(),
			],
			'signup' => [
				'open' => $this->externalUserService->acceptsPublicRegistrations(),
				'mode' => $this->externalUserService->isEnabled() ? $this->externalUserService->mode() : 'closed',
				'available_accounts' => $free,
				'approval_backlog' => $this->signupsRequest->countAwaitingApproval(),
			],
			'health' => [
				// deliveries the queue should have come back to a day ago and has not
				'federation_ok' => $this->federationHealth->stuck()['stale'] === 0,
			],
			'sent_at' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime()),
		];
		// an administrator who keeps the counts private does not send them either
		if (!$this->configService->getAppValueBool(ConfigService::SOCIAL_HIDE_INSTANCE_COUNTS)) {
			$heartbeat['usage'] = ['users_total' => $this->instanceStatsRequest->countLocalAccounts()];
		}
		if ($profileEtag !== '') {
			$heartbeat['profile_etag'] = $profileEtag;
		}
		if ($this->atprotoConfig->isEnabled()) {
			$heartbeat['atproto'] = ['enabled' => true];
		}

		return $heartbeat;
	}
}
