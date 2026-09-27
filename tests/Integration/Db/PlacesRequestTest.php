<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\PlacesRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Place;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Where posts were taken: one row per name and country, however it was
 * capitalised, and found again by the start of its name.
 */
class PlacesRequestTest extends TestCase {
	private PlacesRequest $places;

	protected function setUp(): void {
		parent::setUp();
		$this->places = Server::get(PlacesRequest::class);
	}

	private function placeName(): string {
		// places are never deleted, so each run names its own
		return 'Itestville ' . bin2hex(random_bytes(4));
	}

	public function testAPlaceIsCreatedOnceAndFoundAgain(): void {
		$name = $this->placeName();
		$created = $this->places->findOrCreate((new Place())->setName($name)->setCountry('DE')->setCoordinates('52.52', '13.40'));
		$again = $this->places->findOrCreate((new Place())->setName($name)->setCountry('DE'));

		$this->assertGreaterThan(0, $created->getId());
		$this->assertSame($created->getId(), $again->getId());
		$this->assertSame('52.52', $this->places->getById($created->getId())->getLat());
	}

	public function testTheSameNameInAnotherCountryIsAnotherPlace(): void {
		$name = $this->placeName();
		$here = $this->places->findOrCreate((new Place())->setName($name)->setCountry('DE'));
		$there = $this->places->findOrCreate((new Place())->setName($name)->setCountry('FR'));

		$this->assertNotSame($here->getId(), $there->getId());
		$this->assertSame($there->getId(), $this->places->getByNameAndCountry($name, 'FR')->getId());
	}

	public function testManyAreReadByIdAndUnknownOnesAreLeftOut(): void {
		$one = $this->places->findOrCreate((new Place())->setName($this->placeName())->setCountry('DE'));
		$two = $this->places->findOrCreate((new Place())->setName($this->placeName())->setCountry('DE'));

		$read = $this->places->getByIds([$one->getId(), $two->getId(), 0, -1]);

		$this->assertEqualsCanonicalizing([$one->getId(), $two->getId()], array_keys($read));
		$this->assertSame([], $this->places->getByIds([]));
	}

	public function testSearchFindsAPlaceByTheStartOfItsNameInAnyCase(): void {
		$name = $this->placeName();
		$this->places->findOrCreate((new Place())->setName($name)->setCountry('DE'));

		$found = $this->places->search(strtolower(substr($name, 0, 14)));

		$this->assertContains($name, array_map(static fn (Place $p): string => $p->getName(), $found));
		$this->assertSame([], $this->places->search('   '));
	}

	public function testAnUnknownPlaceIsNotFound(): void {
		$this->expectException(ItemNotFoundException::class);
		$this->places->getById(PHP_INT_MAX);
	}
}
