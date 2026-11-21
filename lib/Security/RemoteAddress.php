<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

/**
 * Decides whether an outbound request is allowed to reach a given host.
 *
 * Unless an administrator opted in (`allow_local_remote_servers`), a host
 * that is, or resolves to, anything but a globally reachable unicast address
 * (private, loopback, link-local, shared, reserved, documentation,
 * multicast, or IPv6 wrapping one of those) is refused, including cloud
 * metadata endpoints such as `169.254.169.254`. Pure apart from DNS lookups.
 */
final class RemoteAddress {
	/**
	 * IPv4 blocks of IANA's special-purpose address registry that are not
	 * globally reachable, plus multicast and the reserved 240/4 (which holds
	 * 255.255.255.255).
	 */
	private const IPV4_NON_PUBLIC = [
		'0.0.0.0/8',       // "this network"
		'10.0.0.0/8',      // private
		'100.64.0.0/10',   // shared address space (carrier-grade NAT)
		'127.0.0.0/8',     // loopback
		'169.254.0.0/16',  // link-local, cloud metadata
		'172.16.0.0/12',   // private
		'192.0.0.0/24',    // IETF protocol assignments
		'192.0.2.0/24',    // documentation
		'192.88.99.0/24',  // deprecated 6to4 relay anycast
		'192.168.0.0/16',  // private
		'198.18.0.0/15',   // benchmarking
		'198.51.100.0/24', // documentation
		'203.0.113.0/24',  // documentation
		'224.0.0.0/4',     // multicast
		'240.0.0.0/4',     // reserved, limited broadcast
	];

	/**
	 * Special-purpose IPv6 blocks inside 2000::/3 that are not globally
	 * reachable. Everything outside 2000::/3 — unspecified, loopback, unique
	 * local, link-local, site-local, multicast, discard-only, IPv4-compatible —
	 * is not global unicast at all and is refused as such.
	 */
	private const IPV6_NON_PUBLIC = [
		'2001::/23',      // IETF protocol assignments, Teredo
		'2001:db8::/32',  // documentation
		'3fff::/20',      // documentation
	];

	/**
	 * Whether a literal IP address is anything but a globally reachable
	 * unicast address: private, loopback, link-local, shared, reserved,
	 * documentation, multicast and the like.
	 */
	public static function isLocalIp(string $ip): bool {
		if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
			return false;
		}

		$packed = inet_pton($ip);
		if ($packed === false) {
			return true;
		}

		if (strlen($packed) === 4) {
			return self::inAny($packed, self::IPV4_NON_PUBLIC);
		}

		// An IPv6 address wrapping an IPv4 one is classified by the wrapped address:
		// IPv4-mapped (::ffff:a.b.c.d), SIIT (::ffff:0:a.b.c.d), NAT64
		// (64:ff9b::/96) and 6to4 (2002:aabb:ccdd::/48).
		$hex = bin2hex($packed);
		if (str_starts_with($hex, '00000000000000000000ffff')
			|| str_starts_with($hex, '0000000000000000ffff0000')
			|| str_starts_with($hex, '0064ff9b0000000000000000')) {
			return self::isLocalIp((string)inet_ntop(substr($packed, 12)));
		}
		if (str_starts_with($hex, '2002')) {
			return self::isLocalIp((string)inet_ntop(substr($packed, 2, 4)));
		}

		return !self::inCidr($packed, '2000::/3') || self::inAny($packed, self::IPV6_NON_PUBLIC);
	}

	/** @param list<string> $cidrs */
	private static function inAny(string $packed, array $cidrs): bool {
		foreach ($cidrs as $cidr) {
			if (self::inCidr($packed, $cidr)) {
				return true;
			}
		}

		return false;
	}

	/** Whether a packed address lies in a block of the same family. */
	private static function inCidr(string $packed, string $cidr): bool {
		[$network, $bits] = explode('/', $cidr);
		$prefix = (string)inet_pton($network);
		if (strlen($prefix) !== strlen($packed)) {
			return false;
		}

		$bits = (int)$bits;
		$whole = intdiv($bits, 8);
		if (substr($packed, 0, $whole) !== substr($prefix, 0, $whole)) {
			return false;
		}

		$rest = $bits % 8;
		if ($rest === 0) {
			return true;
		}

		$mask = (0xff << (8 - $rest)) & 0xff;

		return (ord($packed[$whole]) & $mask) === (ord($prefix[$whole]) & $mask);
	}

	/**
	 * Whether a host, a literal IP or a name, is or resolves to a local address;
	 * a name is local if any of its addresses is.
	 */
	public static function isLocalHost(string $host): bool {
		return self::reachableAddresses($host) === [];
	}

	/**
	 * The addresses a request to a host may be sent to: a literal public address,
	 * or every address a name resolves to when all are public. Empty when any is
	 * local or the name does not resolve (fail closed). Requests are pinned to
	 * this set (`CurlService::pinReachable()`), so a second lookup cannot differ.
	 *
	 * @return list<string>
	 */
	public static function reachableAddresses(string $host): array {
		$host = trim($host, " \t\n\r\0\x0B[]");
		if ($host === '') {
			return [];
		}

		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return self::isLocalIp($host) ? [] : [$host];
		}

		$addresses = self::resolve($host);
		foreach ($addresses as $ip) {
			if (self::isLocalIp($ip)) {
				return [];
			}
		}

		return $addresses;
	}

	/** How long a resolved address set is remembered, in seconds. */
	private const MEMO_TTL = 60;

	/**
	 * Per-process memo of resolved addresses, as a delivery run resolves the same
	 * few hosts many times; entries expire after MEMO_TTL so a long-running
	 * worker follows a moved host.
	 *
	 * @var array<string, array{0: int, 1: list<string>}> host => [expires, addresses]
	 */
	private static array $resolved = [];

	/** @var null|callable(string): list<string> the DNS lookups, replaced in tests */
	private static $resolver = null;

	/** Forgets the memo, for tests and long-running workers. */
	public static function forgetResolved(): void {
		self::$resolved = [];
	}

	/**
	 * Replaces the DNS lookups with $resolver, null restores them; the memo
	 * is forgotten either way. For tests.
	 *
	 * @param null|callable(string): list<string> $resolver
	 */
	public static function resolveWith(?callable $resolver): void {
		self::$resolver = $resolver;
		self::$resolved = [];
	}

	/**
	 * @return list<string> every A and AAAA address the host resolves to
	 */
	private static function resolve(string $host): array {
		$now = time();
		if (isset(self::$resolved[$host]) && self::$resolved[$host][0] > $now) {
			return self::$resolved[$host][1];
		}

		$ips = self::$resolver !== null ? (self::$resolver)($host) : self::lookup($host);
		self::$resolved[$host] = [$now + self::MEMO_TTL, $ips];

		return $ips;
	}

	/**
	 * @return list<string>
	 */
	private static function lookup(string $host): array {
		$ips = [];

		$v4 = gethostbynamel($host);
		if (is_array($v4)) {
			$ips = $v4;
		}

		$v6 = @dns_get_record($host, DNS_AAAA);
		if (is_array($v6)) {
			foreach ($v6 as $record) {
				if (isset($record['ipv6'])) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return $ips;
	}
}
