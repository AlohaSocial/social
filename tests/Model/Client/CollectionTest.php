<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase {
	public function testAnIdIsAnIntegerInTheRowAndAStringInTheJson(): void {
		$json = (new Collection())->setId(42)->jsonSerialize();

		$this->assertSame('42', $json['id']);
	}

	public function testATitleIsTrimmedAndBounded(): void {
		$collection = (new Collection())->setTitle('  a holiday  ');
		$this->assertSame('a holiday', $collection->getTitle());

		$long = (new Collection())->setTitle(str_repeat('a', Collection::TITLE_MAX + 50));
		$this->assertSame(Collection::TITLE_MAX, mb_strlen($long->getTitle()));
	}

	public static function visibilityProvider(): array {
		return [
			'public' => ['public', 'public'],
			'followers' => ['followers', 'followers'],
			// a direct collection would have nobody to be direct to
			'direct' => ['direct', 'public'],
			'nonsense' => ['sideways', 'public'],
			'empty' => ['', 'public'],
		];
	}

	#[DataProvider('visibilityProvider')]
	public function testOnlyTheTwoMeaningfulVisibilitiesAreKept(string $given, string $expected): void {
		$this->assertSame($expected, (new Collection())->setVisibility($given)->getVisibility());
	}

	public function testAPublicCollectionKnowsThatItIs(): void {
		$this->assertTrue((new Collection())->setVisibility('public')->isPublic());
		$this->assertFalse((new Collection())->setVisibility('followers')->isPublic());
	}

	public function testARowBecomesACollection(): void {
		$collection = new Collection();
		$collection->importFromDatabase([
			'id' => 7,
			'actor_id' => 'https://cloud.example.org/users/alice',
			'title' => 'the garden',
			'description' => 'this summer',
			'visibility' => 'followers',
			'creation' => '2026-09-12 10:00:00',
			'updated' => '2026-09-12 11:30:00',
			'size' => 4,
		]);

		$this->assertSame(7, $collection->getId());
		$this->assertSame('https://cloud.example.org/users/alice', $collection->getOwnerId());
		$this->assertSame('the garden', $collection->getTitle());
		$this->assertSame('followers', $collection->getVisibility());
		$this->assertSame(4, $collection->getSize());
		$this->assertSame(strtotime('2026-09-12 10:00:00'), $collection->getCreation());
		$this->assertSame(strtotime('2026-09-12 11:30:00'), $collection->getUpdated());
	}

	/** A collection that has never been edited reports its creation as its update. */
	public function testAnUneditedCollectionReportsItsCreationAsItsUpdate(): void {
		$json = (new Collection())->setCreation(1_760_000_000)->jsonSerialize();

		$this->assertSame($json['created_at'], $json['updated_at']);
	}

	public function testTheOwnerIsNotPartOfWhatAClientSees(): void {
		$json = (new Collection())->setOwnerId('https://cloud.example.org/users/alice')->jsonSerialize();

		$this->assertArrayNotHasKey('owner', $json);
		$this->assertArrayNotHasKey('actor_id', $json);
		$this->assertStringNotContainsString('alice', json_encode($json));
	}

	public function testTheEntityCarriesExactlyTheKeysAClientReads(): void {
		$this->assertSame(
			['id', 'title', 'description', 'visibility', 'created_at', 'updated_at', 'size', 'account', 'posts'],
			array_keys((new Collection())->jsonSerialize())
		);
	}
}
