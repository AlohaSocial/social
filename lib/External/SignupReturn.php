<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\External;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ConfigService;
use OCP\IURLGenerator;

/**
 * Where a registration that an app started goes once the account exists:
 * back to this server's own OAuth authorization request, so the app's sign-in
 * finishes without the person logging in a second time.
 *
 * Nothing else is accepted. The address is checked whenever it is used, and
 * what comes out is the authorize route of this app as a path with the
 * original query, the shape core's login form takes as `redirect_url`.
 */
class SignupReturn {
	/** Longer than any authorization request an app sends, short enough to store. */
	public const MAX_LENGTH = 2048;

	private const AUTHORIZE_ROUTE = 'social.OAuth.authorize';

	/** What a query may hold: RFC 3986 query characters, without `@`, which core's redirect refuses. */
	private const QUERY = '/^(?:[A-Za-z0-9\-._~!$&\'()*+,;=:\/?]|%[0-9A-Fa-f]{2})+$/';

	public function __construct(
		private IURLGenerator $urlGenerator,
		private ConfigService $configService,
	) {
	}

	/**
	 * The return address in the one shape it is used in, or '' when the
	 * candidate is not an authorization request to this server.
	 */
	public function normalize(string $candidate): string {
		if ($candidate === '' || strlen($candidate) > self::MAX_LENGTH
			|| preg_match('/[\x00-\x20\x7f\\\\#@"<>`{}|^]/', $candidate) === 1) {
			return '';
		}

		$parts = parse_url($candidate);
		if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
			return '';
		}

		if (isset($parts['scheme']) || isset($parts['host'])) {
			if (!isset($parts['scheme'], $parts['host']) || !str_starts_with($candidate, $parts['scheme'] . '://')
				|| !in_array($this->origin($parts), $this->origins(), true)) {
				return '';
			}
		} elseif (!str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
			return '';
		}

		$path = $parts['path'] ?? '';
		$query = $parts['query'] ?? '';
		if (!in_array($path, $this->authorizePaths(), true)
			|| preg_match(self::QUERY, $query) !== 1 || !$this->namesAClient($query)) {
			return '';
		}

		return $this->urlGenerator->linkToRoute(self::AUTHORIZE_ROUTE) . '?' . $query;
	}

	/**
	 * Where the login page sends somebody back to, for an address
	 * `normalize()` accepted: the login form itself when there is none.
	 */
	public function loginUrl(string $returnTo): string {
		$returnTo = $this->normalize($returnTo);

		return $this->urlGenerator->linkToRoute('core.login.showLoginForm', ($returnTo === '') ? [] : ['redirect_url' => $returnTo]);
	}

	/** The same, absolute, for an email. */
	public function absoluteLoginUrl(string $returnTo): string {
		return $this->urlGenerator->getAbsoluteURL($this->loginUrl($returnTo));
	}

	private function namesAClient(string $query): bool {
		parse_str($query, $fields);

		return is_string($fields['client_id'] ?? null) && $fields['client_id'] !== '';
	}

	/**
	 * The paths the authorize route answers on: through `index.php`, with
	 * pretty URLs, and at the domain root where the web server forwards
	 * `/oauth/` to this app (contrib/webserver).
	 *
	 * @return list<string>
	 */
	private function authorizePaths(): array {
		$route = $this->urlGenerator->linkToRoute(self::AUTHORIZE_ROUTE);
		$webroot = rtrim($this->urlGenerator->getWebroot(), '/');

		return array_values(array_unique([
			$route,
			$webroot . '/index.php/apps/social/oauth/authorize',
			$webroot . '/apps/social/oauth/authorize',
			'/oauth/authorize',
		]));
	}

	/**
	 * The origins this server is reached at: the one Nextcloud generates
	 * links for, and the ones this app advertises its OAuth metadata under.
	 *
	 * @return list<string>
	 */
	private function origins(): array {
		$origins = [];
		foreach ([fn (): string => $this->urlGenerator->getAbsoluteURL('/'),
			fn (): string => (string)$this->configService->getCloudUrl(true),
			fn (): string => $this->configService->getSocialUrl()] as $url) {
			try {
				$parts = parse_url($url());
			} catch (SocialAppConfigException) {
				continue;
			}
			if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
				$origins[] = $this->origin($parts);
			}
		}

		return array_values(array_unique($origins));
	}

	/** @param array{scheme?: string, host?: string, port?: int, ...} $parts */
	private function origin(array $parts): string {
		$scheme = strtolower($parts['scheme'] ?? '');
		$port = $parts['port'] ?? ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0));

		return $scheme . '://' . strtolower($parts['host'] ?? '') . ':' . $port;
	}
}
