<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\CloudAddressRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CloudAddressRulesTest extends TestCase {
	/** @return iterable<string, array{string, list<string>}> */
	public static function addresses(): iterable {
		yield 'public https' => ['https://cloud.example', []];
		yield 'in a subfolder' => ['https://example.org/nextcloud/', []];
		yield 'with a port' => ['https://cloud.example:8443', []];
		yield 'http' => ['http://cloud.example', ['http']];
		yield 'localhost' => ['https://localhost', ['loopback']];
		yield 'a .localhost name' => ['https://nc.localhost', ['loopback']];
		yield 'loopback IPv4' => ['https://127.0.0.1', ['loopback', 'ip']];
		yield 'loopback IPv6' => ['https://[::1]', ['loopback', 'ip']];
		yield 'a bare IP' => ['https://203.0.113.7', ['ip']];
		yield 'index.php' => ['https://cloud.example/index.php', ['index_php']];
		yield 'index.php in a subfolder' => ['https://example.org/nc/index.php/', ['index_php']];
		yield 'all of it' => ['http://127.0.0.1/index.php', ['http', 'loopback', 'ip', 'index_php']];
		yield 'no scheme' => ['cloud.example', ['invalid']];
		yield 'another scheme' => ['ftp://cloud.example', ['invalid']];
		yield 'a query' => ['https://cloud.example/?x=1', ['invalid']];
		yield 'credentials' => ['https://me@cloud.example', ['invalid']];
		yield 'nothing' => ['', ['invalid']];
	}

	/** @param list<string> $expected */
	#[DataProvider('addresses')]
	public function testWhatIsWrongWithAnAddress(string $address, array $expected): void {
		$this->assertSame($expected, CloudAddressRules::problems($address));
	}

	public function testAnAddressIsTheSameWithOrWithoutIndexPhp(): void {
		$this->assertTrue(CloudAddressRules::sameAddress('https://cloud.example/index.php', 'https://cloud.example'));
		$this->assertTrue(CloudAddressRules::sameAddress('https://Cloud.example/', 'https://cloud.example/index.php/'));
		$this->assertFalse(CloudAddressRules::sameAddress('https://old.example/index.php', 'https://new.example'));
		$this->assertFalse(CloudAddressRules::sameAddress('https://cloud.example/nc', 'https://cloud.example'));
	}

	public function testTheStoredFormHasNoTrailingSlash(): void {
		$this->assertSame('https://cloud.example/nc', CloudAddressRules::normalize(" https://cloud.example/nc/ \n"));
	}
}
