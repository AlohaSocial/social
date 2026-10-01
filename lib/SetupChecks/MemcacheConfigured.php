<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether there is a memcache for Social's short-lived state.
 *
 * Without `memcache.local` or `memcache.distributed`, every cache the app asks
 * Nextcloud for is one that forgets each write. Nextcloud's own check says a
 * memcache would be faster; what it cannot say is that here some of what is
 * lost is protection rather than speed, and nothing else reports it.
 */
class MemcacheConfigured implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#no-memory-cache';

	public function __construct(
		private IL10N $l10n,
		private ICacheFactory $cacheFactory,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'system';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: memory cache');
	}

	#[\Override]
	public function run(): SetupResult {
		if ($this->cacheFactory->isAvailable()) {
			return SetupResult::success(
				$this->l10n->t('A memory cache is configured, so Aloha Social keeps its counters and caches in it.')
			);
		}

		return SetupResult::warning(
			$this->l10n->t('No memory cache is configured (memcache.local or memcache.distributed). Aloha Social keeps its inbox throttle, its record of signatures already accepted and its duplicate-post protection in the database instead, which is slower, and works out the network figures, trends and suggestions again on every request. Configure APCu or Redis.'),
			self::DOC
		);
	}
}
