<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Security;

use Exception;
use OCA\Social\Security\PrivateKeyCipher;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PrivateKeyCipherTest extends TestCase {
	private const PEM = "-----BEGIN PRIVATE KEY-----\nMIIEvT...\n-----END PRIVATE KEY-----\n";

	/** @var ICrypto&MockObject */
	private $crypto;
	private PrivateKeyCipher $cipher;

	protected function setUp(): void {
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(fn (string $m): string => 'sealed(' . $m . ')');
		$this->crypto->method('decrypt')->willReturnCallback(function (string $c): string {
			if (!preg_match('/^sealed\((.*)\)$/s', $c, $m)) {
				throw new Exception('HMAC does not match');
			}

			return $m[1];
		});

		$this->cipher = new PrivateKeyCipher($this->crypto, new NullLogger());
	}

	public function testAKeyRoundTrips(): void {
		$stored = $this->cipher->seal(self::PEM);

		$this->assertSame('sealed(' . self::PEM . ')', $stored, 'the stored value goes through ICrypto');
		$this->assertSame(self::PEM, $this->cipher->open($stored));
	}

	public function testAnEmptyKeyStaysEmpty(): void {
		$this->assertSame('', $this->cipher->seal(''));
		$this->assertSame('', $this->cipher->open(''));
	}

	public function testALegacyPlaintextKeyIsStillReadable(): void {
		$this->crypto->expects($this->never())->method('decrypt');

		$this->assertSame(self::PEM, $this->cipher->open(self::PEM));
	}

	public function testAnUndecryptableValueYieldsAnEmptyKey(): void {
		$this->assertSame('', $this->cipher->open('not-something-we-sealed'));
	}

	public function testIsPlainRecognisesAPem(): void {
		$this->assertTrue($this->cipher->isPlain(self::PEM));
		$this->assertFalse($this->cipher->isPlain($this->cipher->seal(self::PEM)));
		$this->assertFalse($this->cipher->isPlain(''));
	}
}
