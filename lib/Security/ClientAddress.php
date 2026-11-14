<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

/**
 * What a per-address limit counts a client's address as.
 *
 * An IPv4 address is one client. An IPv6 client is normally given a whole
 * /64 and can pick any of its 2^64 addresses, so counting single IPv6
 * addresses would hand everybody an endless supply of fresh buckets: an IPv6
 * address counts as its /64. An IPv4 address written as IPv6 (`::ffff:a.b.c.d`)
 * counts as the IPv4 address it is.
 */
final class ClientAddress {
	/**
	 * The bucket of an address, in a canonical form: `192.0.2.7`, or
	 * `2001:db8:1:2::/64`. Something that is not an IP address is returned
	 * trimmed, as it was.
	 */
	public static function bucket(string $address): string {
		$address = trim($address);
		$literal = trim($address, '[]');
		$zone = strpos($literal, '%');
		if ($zone !== false) {
			$literal = substr($literal, 0, $zone);
		}

		$packed = filter_var($literal, FILTER_VALIDATE_IP) === false ? false : @inet_pton($literal);
		if ($packed === false) {
			return $address;
		}
		if (strlen($packed) === 4) {
			return (string)inet_ntop($packed);
		}
		if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
			return (string)inet_ntop(substr($packed, 12));
		}

		return (string)inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
	}
}
