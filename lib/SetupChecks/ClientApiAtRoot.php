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
 * Whether a Mastodon client can reach this instance at all.
 *
 * The routes of the client API live under `/index.php/apps/social`, but every
 * Mastodon app is given a domain and builds `https://<domain>/api/v1/...`
 * from it; not one of them takes a path prefix. So without a web-server rule
 * that maps the root paths onto the app, adding this instance to an app fails
 * at its first request, with nothing in the app's own log to show for it.
 *
 * Nextcloud only lets a handful of apps claim root URLs, and Social is not
 * among them, so this cannot be fixed in PHP here. The check exists because
 * the alternative is an administrator who believes the app is broken: it says
 * plainly which rules are missing and where to read them.
 */
class ClientApiAtRoot implements ISetupCheck {
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
		return $this->l10n->t('Aloha Social: client API');
	}

	#[\Override]
	public function run(): SetupResult {
		if ($this->checkService->checkClientApiRoot()) {
			return SetupResult::success(
				$this->l10n->t('Mastodon apps can reach this instance.')
			);
		}

		return SetupResult::warning(
			$this->l10n->t(
				'Nothing usable answers at /api/v1/instance, so Mastodon apps cannot add an account '
				. 'here: they build their addresses from the domain alone and never use the '
				. '/index.php/apps/social prefix these routes live under. The web server has to map '
				. '/api, /oauth and /.well-known/host-meta onto the app. %1$s Everything else — the '
				. 'web interface and federation with other servers — works without this.',
				[$this->whatHappened()]
			),
			self::DOC
		);
	}

	/**
	 * What the probe saw, in one sentence.
	 *
	 * Worth the extra code because the two ways this fails are indistinguishable
	 * without it. No rule at all and a rule whose proxy target is not this
	 * Nextcloud both answer 404 at /api/v1/instance, and an administrator who
	 * has just pasted the rules and still sees this warning has no way to tell
	 * which one they are looking at.
	 */
	private function whatHappened(): string {
		$attempts = $this->checkService->clientApiDiagnosis();
		if ($attempts === []) {
			return '';
		}

		// the first attempt, not the last: the bases are tried in order of how
		// much they mean, and the later ones are fallbacks
		$first = $attempts[0];
		$base = (string)$first['base'];

		return match ((string)$first['reason']) {
			'status' => $this->l10n->t(
				'%1$s/api/v1/instance answered %2$d. If the rules are in place, check that what they '
				. 'proxy to serves this Nextcloud: a rule pointing at a port or a host that serves a '
				. 'different document root answers 404 exactly as a missing rule does. An Apache 404 '
				. 'page names the port it came from, which tells the two apart.',
				[$base, (int)$first['status']]
			),
			'not-social' => $this->l10n->t(
				'%1$s/api/v1/instance answered, but with something that is not this app — a login '
				. 'page or a catch-all index, rather than an instance document. The rules have to '
				. 'reach /index.php/apps/social and not the server root.',
				[$base]
			),
			'unreachable' => $this->l10n->t(
				'%1$s could not be reached at all from this server: the connection was refused, the '
				. 'name did not resolve, or the certificate was not accepted.',
				[$base]
			),
			default => '',
		};
	}
}
