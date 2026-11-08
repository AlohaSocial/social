<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/**
 * What an address must look like before every account and post id is built
 * from it.
 *
 * The address is fixed once it is stored, so the setup refuses the ones other
 * servers cannot reach or will not trust: plain http, a loopback name, a bare
 * IP address, and `/index.php` in the path. A test instance may need one of
 * those, which is why every refusal but `invalid` can be overridden.
 */
final class CloudAddressRules {
	/** Not an absolute http(s) URL with a host. Never overridable. */
	public const INVALID = 'invalid';
	public const HTTP = 'http';
	public const LOOPBACK = 'loopback';
	public const IP = 'ip';
	public const INDEX_PHP = 'index_php';

	/** The address as it is stored: trimmed, without a trailing slash. */
	public static function normalize(string $address): string {
		return rtrim(trim($address), '/');
	}

	/**
	 * Everything wrong with an address, as the constants above; empty when
	 * nothing is.
	 *
	 * @return list<string>
	 */
	public static function problems(string $address): array {
		$address = self::normalize($address);
		$parts = parse_url($address);
		$scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
		$host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
		if (!in_array($scheme, ['http', 'https'], true) || $host === ''
			|| isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
			return [self::INVALID];
		}

		$problems = [];
		if ($scheme === 'http') {
			$problems[] = self::HTTP;
		}

		$bareHost = trim($host, '[]');
		$isIp = filter_var($bareHost, FILTER_VALIDATE_IP) !== false;
		if ($bareHost === 'localhost' || str_ends_with($bareHost, '.localhost')
			|| ($isIp && (str_starts_with($bareHost, '127.') || $bareHost === '::1' || $bareHost === '0.0.0.0'))) {
			$problems[] = self::LOOPBACK;
		}
		if ($isIp) {
			$problems[] = self::IP;
		}

		if (preg_match('#/index\.php(/|$)#i', $parts['path'] ?? '') === 1) {
			$problems[] = self::INDEX_PHP;
		}

		return $problems;
	}

	/**
	 * Whether two addresses name the same place, with or without
	 * `/index.php`: Nextcloud serves the same routes at both once pretty URLs
	 * are on, so turning them on later does not move an id.
	 */
	public static function sameAddress(string $one, string $other): bool {
		return self::withoutIndexPhp($one) === self::withoutIndexPhp($other);
	}

	private static function withoutIndexPhp(string $address): string {
		$address = strtolower(self::normalize($address));

		return (string)preg_replace('#/index\.php$#', '', $address);
	}
}
