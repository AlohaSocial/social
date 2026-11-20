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
 * Whether the proxy in front of the client API tells Nextcloud the scheme.
 *
 * The domain-root rules proxy to plain HTTP on loopback; `mod_proxy` sends
 * `X-Forwarded-For` and `X-Forwarded-Host` only, so without
 * `RequestHeader set X-Forwarded-Proto "https"` every absolute URL is built
 * as `http`. Nothing shows this from inside, but iOS clients refuse such URLs
 * and cannot sign in. Read from what the client-API probe already saw, so it
 * says nothing until that probe has run.
 */
class ProxyForwardsTheScheme implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#mastodon-apps-cannot-connect';

	public function __construct(
		private IL10N $l10n,
		private CheckService $checkService,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'network';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: the scheme behind the proxy');
	}

	#[\Override]
	public function run(): SetupResult {
		if (!$this->checkService->clientApiSchemeIsWrong()) {
			return SetupResult::success(
				$this->l10n->t('Addresses are answered in the scheme they were asked over.')
			);
		}

		return SetupResult::warning(
			$this->l10n->t(
				'The client API answers over HTTPS with addresses that begin http://, so the proxy '
				. 'in front of it is not passing the scheme on. Every avatar, post and thumbnail a '
				. 'client is handed then points at plain HTTP on a site that is not, and an iOS '
				. 'client refuses them outright — its sign-in stops after registering, with nothing '
				. 'in any log here to show for it. Apache needs '
				. '"RequestHeader set X-Forwarded-Proto \\"https\\"" in the virtual host, and '
				. 'mod_headers enabled; the nginx rules already set the equivalent.'
			),
			self::DOC
		);
	}
}
