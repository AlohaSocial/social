<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Atproto\Identity\IdentityService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

class AtprotoEnabledCheck implements ISetupCheck {
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IdentityService $identities,
	) {
	}
	#[\Override]
	public function getCategory(): string {
		return 'system';
	}
	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: native AT Protocol prerequisites');
	}
	#[\Override]
	public function run(): SetupResult {
		if (!$this->identities->isEnabled()) {
			return SetupResult::success($this->l10n->t('AT Protocol is disabled.'));
		}
		if (!extension_loaded('gmp') || !function_exists('grapheme_strlen')) {
			return SetupResult::error($this->l10n->t('The native PDS requires PHP GMP and Intl extensions.'), Docs::ADMIN_GUIDE);
		}
		return SetupResult::warning($this->l10n->t('Native AT Protocol is enabled. Verify wildcard HTTPS handle discovery and the Firehose reverse proxy externally; installed PHP extensions do not confirm relay connectivity.'), Docs::ADMIN_GUIDE);
	}
}
