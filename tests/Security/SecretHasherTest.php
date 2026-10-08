<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Security;

use OCA\Social\Security\SecretHasher;
use PHPUnit\Framework\TestCase;

class SecretHasherTest extends TestCase {
	private SecretHasher $hasher;

	protected function setUp(): void {
		$this->hasher = new SecretHasher();
	}

	public function testHashIsDeterministicAndPrefixed(): void {
		$this->assertSame('sha256:' . hash('sha256', 'tok'), $this->hasher->hash('tok'));
		$this->assertSame($this->hasher->hash('tok'), $this->hasher->hash('tok'));
		$this->assertSame('', $this->hasher->hash(''));
	}

	public function testMatchesAHashedValue(): void {
		$stored = $this->hasher->hash('s3cret');

		$this->assertTrue($this->hasher->matches($stored, 's3cret'));
		$this->assertFalse($this->hasher->matches($stored, 'other'));
		$this->assertFalse($this->hasher->matches($stored, ''));
	}

	/** A bare stored value is a row the migration missed, and is not a credential. */
	public function testALegacyPlaintextValueNoLongerMatches(): void {
		$this->assertFalse($this->hasher->matches('s3cret', 's3cret'));
		$this->assertFalse($this->hasher->matches('', 's3cret'));
	}

	public function testForLookupIsTheHashAlone(): void {
		$this->assertSame($this->hasher->hash('tok'), $this->hasher->forLookup('tok'));
		$this->assertNull($this->hasher->forLookup(''));
	}

	/**
	 * The stored digest must not be a credential of its own: presenting the
	 * column's own value used to match the legacy branch of the lookup, so
	 * anybody holding a database dump held working access tokens.
	 */
	public function testTheStoredHashIsNotAcceptedAsAPresentedSecret(): void {
		$stored = $this->hasher->hash('tok');

		$this->assertNull($this->hasher->forLookup($stored));
		$this->assertFalse($this->hasher->matches($stored, $stored));
	}

	public function testIsHashed(): void {
		$this->assertTrue($this->hasher->isHashed($this->hasher->hash('x')));
		$this->assertFalse($this->hasher->isHashed('x'));
		$this->assertFalse($this->hasher->isHashed(''));
	}
}
