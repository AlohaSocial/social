<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Service\ConfigService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether the peers that are strict about it will talk to this instance at all.
 *
 * Federation does not fail all at once. A Nextcloud on `http://` or on a
 * private address federates happily with a permissive peer and is refused,
 * silently and at the first gate, by a strict one — so an admin sees *some*
 * of the fediverse work and has no way to tell why the rest does not.
 *
 * The strictest in common use is Pixelfed, which is also the network a photo
 * server most wants to reach. Its `Helpers::isValidUri()` refuses any scheme
 * but `https`, and `lookupPublicIps()` resolves the host through DNS —
 * ignoring `/etc/hosts` — and refuses it if any address is private. Both are
 * consulted before a signature is even checked, from its inbox, its delivery
 * and its actor fetch, with nothing to configure. Mastodon is laxer about the
 * address but still refuses plain HTTP in practice.
 *
 * Reported rather than enforced: a development instance on a LAN is a
 * perfectly good thing to be, and this says what it costs rather than
 * pretending it is broken.
 */
class ReachableByStrictPeers implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#the-setup-checks';

	public function __construct(
		private IL10N $l10n,
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'network';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: reachable by other servers');
	}

	#[\Override]
	public function run(): SetupResult {
		$url = (string)$this->configService->getAppValue(ConfigService::SOCIAL_URL);
		if ($url === '') {
			return SetupResult::info(
				$this->l10n->t('Aloha Social has not been set up yet.'), self::DOC
			);
		}

		$host = (string)parse_url($url, PHP_URL_HOST);
		$secure = str_starts_with(strtolower($url), 'https://');
		$private = $host !== '' && $this->isPrivate($host);

		if ($secure && !$private) {
			return SetupResult::success(
				$this->l10n->t('This server is at %1$s, which every other fediverse server will accept.', [$host])
			);
		}

		$problems = [];
		if (!$secure) {
			$problems[] = $this->l10n->t('it is served over plain HTTP');
		}
		if ($private) {
			$problems[] = $this->l10n->t('%1$s resolves to a private address, or to nothing a public resolver knows', [$host]);
		}

		return SetupResult::warning(
			$this->l10n->t(
				'Accounts here federate under %1$s, and %2$s. The stricter servers — Pixelfed among them — refuse a peer that is not HTTPS on a publicly resolvable name, before they check anything else, so posts to and from them are dropped without a word while other servers keep working. This is expected on a development or intranet instance, and has to be fixed before this one federates with everybody.',
				[$url, implode($this->l10n->t(', and '), $problems)]
			),
			self::DOC
		);
	}

	/**
	 * Whether a name resolves only to addresses the internet does not route.
	 *
	 * A name nothing resolves counts as private: what matters is whether a
	 * peer looking it up gets something it will accept, and "no answer" is not.
	 */
	private function isPrivate(string $host): bool {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return filter_var(
				$host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			) === false;
		}

		$records = @dns_get_record($host . '.', DNS_A | DNS_AAAA);
		if (!is_array($records) || $records === []) {
			return true;
		}

		foreach ($records as $record) {
			$ip = $record['ip'] ?? $record['ipv6'] ?? null;
			if (!is_string($ip) || $ip === '') {
				continue;
			}

			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
				return false;
			}
		}

		return true;
	}
}
