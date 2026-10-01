<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Service\FederationHealthService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether deliveries are leaving, or piling up.
 *
 * Two things in the queue do not move on their own: a row the drain has
 * given up on after every retry, and a row it should have come back for a
 * day ago and has not. The first is an instance that is gone or refusing
 * us; the second is a drain that is not draining. Both are worth an
 * administrator's eye, and until now neither had one — the Federation health
 * section shows them, but only to whoever opens the Social settings.
 */
class OutboundQueueNotStuck implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#deliveries-are-stuck';

	public function __construct(
		private IL10N $l10n,
		private FederationHealthService $federationHealthService,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'system';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: outbound queue');
	}

	#[\Override]
	public function run(): SetupResult {
		$stuck = $this->federationHealthService->stuck();

		if ($stuck['abandoned'] === 0 && $stuck['stale'] === 0) {
			return SetupResult::success(
				$this->l10n->t('Nothing in the outbound queue has been given up on or left waiting.')
			);
		}

		$parts = [];
		if ($stuck['stale'] > 0) {
			$parts[] = $this->l10n->n(
				'%n delivery has been waiting for more than a day',
				'%n deliveries have been waiting for more than a day',
				$stuck['stale']
			);
		}
		if ($stuck['abandoned'] > 0) {
			$parts[] = $this->l10n->n(
				'%n delivery was given up on after every retry',
				'%n deliveries were given up on after every retry',
				$stuck['abandoned']
			);
		}

		return SetupResult::warning(
			implode('; ', $parts) . '. '
			. $this->l10n->t('The Federation health section of the Aloha Social settings names the instances; "occ social:queue:status" prints the same, and "occ social:queue:retry" re-queues what was abandoned.'),
			self::DOC
		);
	}
}
