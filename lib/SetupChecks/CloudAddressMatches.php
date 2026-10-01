<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Service\CheckService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether the address Social builds every id from is still the one the server
 * says it is reachable at.
 *
 * The app copies `overwrite.cli.url` once, on its first run, and never reads
 * it again. A server renamed afterwards keeps federating under the old name:
 * WebFinger answers for a host nobody asks about, and every new post carries
 * an id that resolves nowhere. Reported and not corrected — the stored
 * address is inside every id already written.
 *
 * The ids themselves are minted from `social_url`, which on an instance set
 * up before it was derived from `cloud_url` came from the first request's
 * host and scheme, so that is compared too.
 */
class CloudAddressMatches implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#the-address-aloha-social-is-set-up-for';

	public function __construct(
		private IL10N $l10n,
		private CheckService $checkService,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'config';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: server address');
	}

	#[\Override]
	public function run(): SetupResult {
		$addresses = $this->checkService->cloudAddresses();

		if ($addresses['configured'] === '') {
			return SetupResult::info(
				$this->l10n->t('Aloha Social has not been set up yet. It takes the address of this server the first time somebody opens it.'),
				self::DOC
			);
		}

		if ($addresses['expected'] === '') {
			return SetupResult::warning(
				$this->l10n->t('overwrite.cli.url is not set, so there is nothing to compare the address Aloha Social is set up for (%1$s) against. Set it: cron and occ build links from it.', [$addresses['configured']]),
				self::DOC
			);
		}

		if ($this->checkService->checkCloudAddress() && !$this->checkService->checkSocialUrl()) {
			return SetupResult::error(
				$this->l10n->t(
					'Aloha Social is set up for %1$s, but builds every account and post id from %2$s: the app was first opened through another address, and that is the one other servers are given. If nothing has federated yet, delete the social_url app value ("occ config:app:delete social social_url") and it is derived from %1$s the next time the app is opened. Otherwise "occ social:reset --uri=%1$s" moves it — which deletes everything Aloha Social holds.',
					[$addresses['configured'], $this->checkService->configuredSocialUrl()]
				),
				self::DOC
			);
		}

		if ($this->checkService->checkCloudAddress()) {
			return SetupResult::success(
				$this->l10n->t('Aloha Social is set up for %1$s, which is the address this server reports.', [$addresses['configured']])
			);
		}

		return SetupResult::error(
			$this->l10n->t(
				'Aloha Social builds every account and post id from %1$s, but this server reports %2$s. Accounts here cannot be found under the address the server advertises. Point overwrite.cli.url back at the first, or accept the rename with "occ social:reset --uri=%2$s" — which deletes everything Aloha Social holds.',
				[$addresses['configured'], $addresses['expected']]
			),
			self::DOC
		);
	}
}
