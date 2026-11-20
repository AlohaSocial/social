<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

/**
 * What a per-address limit counts a client's address as: an IPv4 address as
 * itself (also when written as `::ffff:a.b.c.d`), an IPv6 address as its
 * /64, since one client usually holds a whole /64.
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
