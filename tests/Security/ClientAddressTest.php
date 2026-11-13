<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Security;

use OCA\Social\Security\ClientAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClientAddressTest extends TestCase {
	/** @return array<string, array{string, string}> */
	public static function addresses(): array {
		return [
			'IPv4 is one client' => ['192.0.2.7', '192.0.2.7'],
			'IPv4 with blanks' => [' 192.0.2.7 ', '192.0.2.7'],
			'IPv6 counts as its /64' => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::/64'],
			'another address of the same /64' => ['2001:0db8:0001:0002:0000:0000:0000:0001', '2001:db8:1:2::/64'],
			'IPv6 in brackets with a zone' => ['[fe80::1%eth0]', 'fe80::/64'],
			'IPv4-mapped IPv6 is the IPv4 address' => ['::ffff:192.0.2.7', '192.0.2.7'],
			'loopback' => ['::1', '::/64'],
			'not an address' => ['unknown', 'unknown'],
			'nothing' => ['', ''],
		];
	}

	#[DataProvider('addresses')]
	public function testAnAddressIsCountedByItsBucket(string $address, string $bucket): void {
		$this->assertSame($bucket, ClientAddress::bucket($address));
	}

	public function testNeighbouringPrefixesAreDifferentBuckets(): void {
		$this->assertNotSame(ClientAddress::bucket('2001:db8:1:2::1'), ClientAddress::bucket('2001:db8:1:3::1'));
		$this->assertNotSame(ClientAddress::bucket('192.0.2.7'), ClientAddress::bucket('192.0.2.8'));
	}
}
