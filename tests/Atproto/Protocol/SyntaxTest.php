<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Protocol;

use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Tests\Atproto\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Every grammar against the interop syntax vectors: everything the valid
 * file lists is accepted, everything the invalid file lists is refused.
 */
class SyntaxTest extends TestCase {
	public function testHandles(): void {
		$this->assertEachLine('handle_syntax_valid.txt', Syntax::isHandle(...), true);
		$this->assertEachLine('handle_syntax_invalid.txt', Syntax::isHandle(...), false);
	}

	public function testDids(): void {
		$this->assertEachLine('did_syntax_valid.txt', Syntax::isDid(...), true);
		$this->assertEachLine('did_syntax_invalid.txt', Syntax::isDid(...), false);
	}

	public function testNsids(): void {
		$this->assertEachLine('nsid_syntax_valid.txt', Syntax::isNsid(...), true);
		$this->assertEachLine('nsid_syntax_invalid.txt', Syntax::isNsid(...), false);
	}

	public function testRecordKeys(): void {
		$this->assertEachLine('recordkey_syntax_valid.txt', Syntax::isRecordKey(...), true);
		$this->assertEachLine('recordkey_syntax_invalid.txt', Syntax::isRecordKey(...), false);
	}

	public function testAtUris(): void {
		$this->assertEachLine('aturi_syntax_valid.txt', Syntax::isAtUri(...), true);
		$this->assertEachLine('aturi_syntax_invalid.txt', Syntax::isAtUri(...), false);
	}

	public function testTids(): void {
		$this->assertEachLine('tid_syntax_valid.txt', Tid::isValid(...), true);
		$this->assertEachLine('tid_syntax_invalid.txt', Tid::isValid(...), false);
	}

	public function testDatetimes(): void {
		$this->assertEachLine('datetime_syntax_valid.txt', Syntax::isDatetime(...), true);
		$this->assertEachLine('datetime_syntax_invalid.txt', Syntax::isDatetime(...), false);
	}

	public function testLanguages(): void {
		$this->assertEachLine('language_syntax_valid.txt', Syntax::isLanguage(...), true);
		$this->assertEachLine('language_syntax_invalid.txt', Syntax::isLanguage(...), false);
	}

	public function testCidStrings(): void {
		// the vectors cover every CID form; this protocol accepts only the
		// one it allows, so of the valid file only the base32 CIDv1 ones pass
		foreach (Fixtures::lines('cid_syntax_invalid.txt') as $line) {
			$this->assertFalse(Cid::isValid($line), $line);
		}
		$this->assertTrue(Cid::isValid('bafyreie5cvv4h45feadgeuwhbcutmh6t2ceseocckahdoe6uat64zmz454'));
		$this->assertFalse(Cid::isValid('QmYwAPJzv5CZsnA625s3Xf2nemtYgPpHdWEz79ojWnPbdG'), 'CIDv0 is not allowed');
	}

	public function testAnAtUriIsTakenApart(): void {
		$this->assertSame(
			['authority' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'collection' => 'app.bsky.feed.post', 'rkey' => '3jqfcqzm3fo2j'],
			Syntax::parseAtUri('at://did:plc:ewvi7nxzyoun6zhxrhs64oiz/app.bsky.feed.post/3jqfcqzm3fo2j'),
		);
		$this->assertSame(['authority' => 'alice.example.com', 'collection' => '', 'rkey' => ''], Syntax::parseAtUri('at://alice.example.com'));
		$this->assertNull(Syntax::parseAtUri('https://example.com'));
	}

	public function testAHandleOnAReservedTopLevelDomainIsNotResolved(): void {
		$this->assertTrue(Syntax::isHandle('alice.local'), 'well-formed');
		$this->assertFalse(Syntax::isResolvableHandle('alice.local'));
		$this->assertFalse(Syntax::isResolvableHandle('alice.example'));
		$this->assertFalse(Syntax::isResolvableHandle('handle.invalid'));
		$this->assertTrue(Syntax::isResolvableHandle('alice.test'), 'reserved for testing, which is what the interop job does');
	}

	/**
	 * @param callable(string): bool $check
	 */
	private function assertEachLine(string $fixture, callable $check, bool $expected): void {
		$lines = Fixtures::lines($fixture);
		$this->assertNotEmpty($lines);
		foreach ($lines as $line) {
			$this->assertSame($expected, $check($line), $fixture . ': ' . $line);
		}
	}
}
