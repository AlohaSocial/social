<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AlohaDirectory;

use DateTimeImmutable;
use OCA\Social\AlohaDirectory\ListVerificationException;
use OCA\Social\AlohaDirectory\ListVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures are signed with the Aloha Directory's own code, so these hold
 * the verifier to what the directory publishes.
 */
class ListVerifierTest extends TestCase {
	private ListVerifier $verifier;
	private DateTimeImmutable $now;
	/** @var array<string, string> */
	private array $rootKeys;

	protected function setUp(): void {
		$meta = json_decode((string)file_get_contents(__DIR__ . '/fixtures/meta.json'), true);
		$this->rootKeys = ['test-root' => ListVerifier::publicKey($meta['root_public'])];
		$this->verifier = new ListVerifier();
		$this->now = new DateTimeImmutable('2026-11-14T00:00:00Z');
	}

	private static function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');
	}

	public function testTheKeySetAndAListVerify(): void {
		$keys = $this->verifier->verifyKeySet(self::fixture('keys'), $this->rootKeys, null, $this->now);
		$this->assertSame(['test-list'], array_keys($keys['listKeys']));

		$servers = $this->verifier->verifyList(self::fixture('servers'), $keys['listKeys'], 'servers', null, $this->now);
		$this->assertSame('nordlicht.example.org', $servers['payload']['entries'][0]['domain']);
		$this->assertFalse($servers['expired']);
		$this->assertFalse($servers['tooOld']);
	}

	public function testAKeySetSignedByAnotherRootIsRefused(): void {
		$this->expectException(ListVerificationException::class);
		$this->verifier->verifyKeySet(self::fixture('keys'), ['other' => str_repeat("\1", 32)], null, $this->now);
	}

	public function testAListIsNotSignedByTheRootKey(): void {
		$this->expectException(ListVerificationException::class);
		$this->verifier->verifyList(self::fixture('servers'), $this->rootKeys, 'servers', null, $this->now);
	}

	public function testAnOlderSerialIsAReplay(): void {
		$keys = $this->verifier->verifyKeySet(self::fixture('keys'), $this->rootKeys, null, $this->now)['listKeys'];

		$this->expectExceptionMessage('not higher');
		$this->verifier->verifyList(self::fixture('servers-older'), $keys, 'servers', 1794586000, $this->now);
	}

	public function testOneListCannotPassForTheOther(): void {
		$keys = $this->verifier->verifyKeySet(self::fixture('keys'), $this->rootKeys, null, $this->now)['listKeys'];

		$this->expectExceptionMessage('Expected the list "search"');
		$this->verifier->verifyList(self::fixture('servers'), $keys, 'search', null, $this->now);
	}

	public function testAChangedPayloadIsRefused(): void {
		$keys = $this->verifier->verifyKeySet(self::fixture('keys'), $this->rootKeys, null, $this->now)['listKeys'];
		$envelope = json_decode(self::fixture('servers'), true);
		$payload = json_decode((string)base64_decode(strtr($envelope['payload'], '-_', '+/')), true);
		$payload['entries'][0]['domain'] = 'evil.example';
		$envelope['payload'] = rtrim(strtr(base64_encode((string)json_encode($payload)), '+/', '-_'), '=');

		$this->expectExceptionMessage('no valid signature');
		$this->verifier->verifyList((string)json_encode($envelope), $keys, 'servers', null, $this->now);
	}

	public function testAnExpiredListIsStillAcceptedButMarked(): void {
		$keys = $this->verifier->verifyKeySet(self::fixture('keys'), $this->rootKeys, null, $this->now)['listKeys'];
		$later = $this->verifier->verifyList(self::fixture('servers'), $keys, 'servers', null, new DateTimeImmutable('2027-03-01T00:00:00Z'));

		$this->assertTrue($later['expired']);
		$this->assertTrue($later['tooOld']);
	}

	public function testGarbageIsRefused(): void {
		$this->expectException(ListVerificationException::class);
		$this->verifier->verifyKeySet('not json', $this->rootKeys, null, $this->now);
	}
}
