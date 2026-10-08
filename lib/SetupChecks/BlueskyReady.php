<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\AtprotoStatusService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Throwable;

/**
 * Administration → Overview: whether this instance is a working Bluesky
 * host, when Bluesky is switched on. Off, there is nothing to report.
 */
class BlueskyReady implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#bluesky';

	public function __construct(
		private IL10N $l10n,
		private AtprotoConfig $config,
		private AtprotoStatusService $status,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'network';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: Bluesky');
	}

	#[\Override]
	public function run(): SetupResult {
		if (!$this->config->isEnabled()) {
			return SetupResult::success($this->l10n->t('Bluesky is switched off for this instance.'));
		}
		try {
			$failing = array_filter($this->status->checks(), static fn (array $check): bool => $check['state'] === 'error');
		} catch (Throwable $e) {
			return SetupResult::warning($this->l10n->t('The Bluesky checks could not be run: %1$s', [$e->getMessage()]), self::DOC);
		}
		if ($failing === []) {
			return SetupResult::success($this->l10n->t('This instance is reachable as a Bluesky host.'));
		}

		return SetupResult::error(
			$this->l10n->t('Bluesky is switched on but not working: %1$s', [implode('; ', array_map(static fn (array $check): string => $check['detail'], $failing))]),
			self::DOC,
		);
	}
}
