<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Traits;

use OCA\Social\Tools\Traits\TStringTools;
use PHPUnit\Framework\TestCase;

class TStringToolsTest extends TestCase {
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/** Exposes the protected trait methods. */
	private object $tools;

	protected function setUp(): void {
		$this->tools = new class {
			use TStringTools;

			public function __call(string $name, array $args) {
				return $this->$name(...$args);
			}
		};
	}

	public function testTokenDefaultsToFifteenAlphanumericCharacters(): void {
		$token = $this->tools->token();

		$this->assertSame(15, strlen($token));
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{15}$/', $token);
	}

	public function testTokenHonoursTheLengthAndIsRandom(): void {
		$first = $this->tools->token(40);

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $first);
		$this->assertNotSame($first, $this->tools->token(40));
		$this->assertSame('', $this->tools->token(0));
	}

	public function testUuidIsAVersionFourUuid(): void {
		$uuid = $this->tools->uuid();

		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $uuid);
		$this->assertNotSame($uuid, $this->tools->uuid());
	}

	public function testUuidsAreDrawnFromTheSystemRandomSource(): void {
		// a uuid is a document id and the filename behind /media/{uuid}, so it
		// has to be unguessable, not merely unique: mt_rand() is seeded state
		// that a handful of observed values recovers
		$seen = [];
		for ($i = 0; $i < 200; $i++) {
			$seen[] = $this->tools->uuid();
		}

		$this->assertCount(200, array_unique($seen));

		// the same seed twice must not reproduce the sequence
		mt_srand(1);
		$first = $this->tools->uuid();
		mt_srand(1);
		$this->assertNotSame($first, $this->tools->uuid());
	}

	public function testUuidCarriesTheVersionAndVariantBits(): void {
		for ($i = 0; $i < 20; $i++) {
			$uuid = $this->tools->uuid();
			$this->assertSame('4', $uuid[14], 'version nibble');
			$this->assertContains($uuid[19], ['8', '9', 'a', 'b'], 'variant nibble');
		}
	}

	public function testShortUuidsDropTheDashesLongerOnesKeepThem(): void {
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $this->tools->uuid(8));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $this->tools->uuid(16));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab]$/', $this->tools->uuid(20));
	}

	public function testTokenCanEmitEveryCharsetCharacter(): void {
		// The charset ends in '0'; an off-by-one in the random_int upper bound
		// made that character unreachable. 5000 draws miss a reachable
		// character with probability (61/62)^5000 ≈ 10^-36.
		$token = $this->tools->token(5000);

		$this->assertStringContainsString('0', $token);
	}
}
