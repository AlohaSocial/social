<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\Place;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlaceTest extends TestCase {
	public static function countryProvider(): array {
		return [
			'two letters' => ['de', 'DE'],
			'already upper' => ['GB', 'GB'],
			'padded' => ['  fr  ', 'FR'],
			// a column holding "United Kingdom" in one row and "GB" in another
			// cannot group anything
			'a name' => ['United Kingdom', ''],
			'three letters' => ['DEU', ''],
			'digits' => ['12', ''],
			'empty' => ['', ''],
		];
	}

	#[DataProvider('countryProvider')]
	public function testOnlyAnIsoCountryCodeIsKept(string $given, string $expected): void {
		$this->assertSame($expected, (new Place())->setCountry($given)->getCountry());
	}

	public static function coordinateProvider(): array {
		return [
			'a pair' => ['52.52', '13.405', true],
			'negative' => ['-33.87', '-151.21', true],
			'zero' => ['0', '0', true],
			'the poles' => ['90', '180', true],
			'past the pole' => ['91', '13.405', false],
			'past the meridian' => ['52.52', '181', false],
			'half a pair' => ['52.52', '', false],
			'not numbers' => ['north', 'east', false],
			'injection attempt' => ['52.52', "13.4'; DROP TABLE", false],
		];
	}

	#[DataProvider('coordinateProvider')]
	public function testCoordinatesAreBothKeptOrBothDropped(string $lat, string $lon, bool $kept): void {
		$place = (new Place())->setCoordinates($lat, $lon);

		$this->assertSame($kept, $place->hasCoordinates());
		if ($kept) {
			$this->assertSame(trim($lat), $place->getLat());
			$this->assertSame(trim($lon), $place->getLon());
		} else {
			$this->assertSame('', $place->getLat(), 'half a coordinate was kept');
			$this->assertSame('', $place->getLon(), 'half a coordinate was kept');
		}
	}

	/**
	 * The coordinates are strings the whole way through: they arrive from a
	 * client and go back unchanged, and a float column would round what
	 * somebody typed.
	 */
	public function testACoordinateIsNotRoundedOnTheWayThrough(): void {
		$place = (new Place())->setCoordinates('52.5200066', '13.4049540');

		$this->assertSame('52.5200066', $place->getLat());
		$this->assertSame('13.4049540', $place->getLon());
	}

	public function testTheNameIsTrimmedAndBounded(): void {
		$this->assertSame('Berlin', (new Place())->setName('  Berlin  ')->getName());
		$this->assertSame(
			Place::NAME_MAX,
			mb_strlen((new Place())->setName(str_repeat('a', Place::NAME_MAX + 20))->getName())
		);
	}

	/** What deduplicates a place: the same name in any case is the same place. */
	public function testTheKeyIgnoresCaseSoBerlinIsNotStoredTwice(): void {
		$this->assertSame(
			(new Place())->setName('Berlin')->getNamePrim(),
			(new Place())->setName('  berlin ')->getNamePrim()
		);
		$this->assertNotSame(
			(new Place())->setName('Berlin')->getNamePrim(),
			(new Place())->setName('Bern')->getNamePrim()
		);
		$this->assertSame('', (new Place())->getNamePrim(), 'a nameless place got a key');
	}

	/** Pixelfed spells it `long`, not `lon`, and every value is a string. */
	public function testTheEntityIsPixelfedShaped(): void {
		$json = (new Place())->setId(3)->setName('Berlin')->setCountry('DE')
			->setCoordinates('52.52', '13.405')->jsonSerialize();

		$this->assertSame(['id', 'name', 'country', 'lat', 'long'], array_keys($json));
		$this->assertSame('3', $json['id']);
		$this->assertSame('13.405', $json['long']);
		foreach ($json as $key => $value) {
			$this->assertIsString($value, "$key is not a string");
		}
	}

	public function testARowBecomesAPlace(): void {
		$place = new Place();
		$place->importFromDatabase([
			'id' => 9,
			'name' => 'Lisbon',
			'country' => 'PT',
			'lat' => '38.7223',
			'lon' => '-9.1393',
		]);

		$this->assertSame(9, $place->getId());
		$this->assertSame('Lisbon', $place->getName());
		$this->assertSame('PT', $place->getCountry());
		$this->assertTrue($place->hasCoordinates());
	}
}
