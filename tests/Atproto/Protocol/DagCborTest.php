<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Protocol;

use InvalidArgumentException;
use OCA\Social\Atproto\Protocol\Bytes;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Tests\Atproto\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The encoding against the interop data-model vectors: the same JSON gives
 * the same bytes and the same CID, and what the protocol forbids is refused.
 */
class DagCborTest extends TestCase {
	public function testDataModelFixtures(): void {
		foreach (Fixtures::json('data-model-fixtures.json') as $vector) {
			$encoded = DagCbor::encode(DagCbor::fromLexJson($vector['json']));

			$this->assertSame($vector['cbor_base64'], rtrim(base64_encode($encoded), '='));
			$this->assertSame($vector['cid'], Cid::forDagCbor($encoded)->toString());
			// keys come back in canonical order, which assertEquals does not mind
			$this->assertEquals($vector['json'], DagCbor::toLexJson(DagCbor::decode($encoded)), 'decoding gives the JSON back');
		}
	}

	public function testNumericJsonObjectKeysKeepTheirMapIdentity(): void {
		foreach (['{"0":"first"}', '{"1":"first"}', '{"0":{},"name":[]}'] as $json) {
			$value = DagCbor::fromLexJson(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
			$this->assertInstanceOf(\stdClass::class, $value);
			$bytes = DagCbor::encode($value);
			$decoded = DagCbor::decode($bytes);
			$this->assertInstanceOf(\stdClass::class, $decoded);
			$this->assertSame($bytes, DagCbor::encode($decoded));
			$this->assertEquals(json_decode($json), DagCbor::toLexJson($decoded));
		}
	}

	public function testValidRecordsEncode(): void {
		foreach (Fixtures::json('data-model-valid.json') as $vector) {
			$value = DagCbor::fromLexJson(self::integral($vector['json']));
			$this->assertEquals($value, DagCbor::decode(DagCbor::encode($value)), $vector['note']);
		}
	}

	/**
	 * @return iterable<string, array{0: mixed}>
	 */
	public static function invalidValues(): iterable {
		yield 'a float' => [['a' => 1.5]];
		yield 'a float that looks whole' => [['a' => 123.0]];
		yield 'an object that is not a plain one' => [['a' => new \ArrayObject()]];
		yield 'a link that is not a CID' => [['lnk' => ['$link' => '.']]];
		yield 'bytes that are not base64' => [['b' => ['$bytes' => '***']]];
		yield 'text that is not UTF-8' => [["\xff"]];
	}

	#[DataProvider('invalidValues')]
	public function testWhatCannotBeEncodedIsRefused(mixed $value): void {
		$this->expectException(InvalidArgumentException::class);
		DagCbor::encode(DagCbor::fromLexJson($value));
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function invalidBytes(): iterable {
		yield 'a float' => ["\xf9\x3c\x00"];
		yield 'undefined' => ["\xf7"];
		yield 'an indefinite array' => ["\x9f\xff"];
		yield 'a non-minimal integer' => ["\x18\x05"];
		yield 'a tag other than 42' => ["\xc1\x00"];
		yield 'a CID link without the leading zero' => ["\xd8\x2a\x41\x01"];
		yield 'a non-text map key' => ["\xa1\x01\x01"];
		yield 'a repeated map key' => ["\xa2\x61a\x01\x61a\x02"];
		// keys sort by length, then by their bytes
		yield 'map keys out of order' => ["\xa2\x61b\x01\x61a\x02"];
		yield 'a longer map key before a shorter one' => ["\xa2\x62aa\x01\x61b\x02"];
		// as a fuzzed tree entry had it: `t` flipped to `4` after `p`
		yield 'a tree entry with its keys out of order' => [(string)base64_decode('omFlpGFrVmFwcC5ic2t5LmZlZWQucG9zdC8zbDJhcABhNPZhdlglAAFVEiDKl4ESyhu9yvrCMbOaI9xNp4bv+BR8TnK5gHeFr+5Iu2Fs9g==')];
		yield 'trailing bytes' => ["\x01\x01"];
		yield 'a truncated string' => ["\x63ab"];
		yield 'nothing' => [''];
	}

	#[DataProvider('invalidBytes')]
	public function testWhatIsNotDrislIsRefused(string $bytes): void {
		$this->expectException(InvalidArgumentException::class);
		DagCbor::decode($bytes);
	}

	public function testTheDepthIsBounded(): void {
		$this->expectException(InvalidArgumentException::class);
		DagCbor::decode(str_repeat("\x81", 100) . "\x01");
	}

	public function testIntegersAcrossTheirWidths(): void {
		foreach ([0, 23, 24, 255, 256, 65535, 65536, 4294967295, 4294967296, PHP_INT_MAX, -1, -24, -25, -256, -257, PHP_INT_MIN + 1] as $int) {
			$this->assertSame($int, DagCbor::decode(DagCbor::encode($int)), (string)$int);
		}
	}

	public function testMapKeysAreSortedByLengthThenBytes(): void {
		$this->assertSame(
			bin2hex(DagCbor::encode(['b' => 1, 'aa' => 2, 'a' => 3])),
			bin2hex("\xa3" . "\x61a\x03" . "\x61b\x01" . "\x62aa\x02"),
		);
	}

	public function testBytesAndLinksRoundTripThroughJson(): void {
		$cid = Cid::forRaw('blob');
		$value = ['ref' => $cid, 'raw' => new Bytes("\0\1\2")];
		$json = DagCbor::toLexJson($value);

		$this->assertSame(['ref' => ['$link' => $cid->toString()], 'raw' => ['$bytes' => 'AAEC']], $json);
		$this->assertEquals($value, DagCbor::fromLexJson(['ref' => ['$link' => $cid->toString()], 'raw' => ['$bytes' => 'AAEC==']]), 'padding is tolerated');
		$this->assertEquals($value, DagCbor::fromLexJson($json));
	}

	/**
	 * `[]` is the empty list and an empty `stdClass` the empty map, each
	 * with its own bytes, and a value read back re-encodes to the bytes it
	 * was read from, so a record's CID survives a decode.
	 */
	public function testTheEmptyMapIsNotTheEmptyList(): void {
		$this->assertSame("\x80", DagCbor::encode([]));
		$this->assertSame("\xa0", DagCbor::encode(new \stdClass()));
		$this->assertSame([], DagCbor::decode("\x80"));
		$this->assertEquals(new \stdClass(), DagCbor::decode("\xa0"));

		$bytes = "\xa3" . "\x61a\x80" . "\x61b\xa0" . "\x65\$type\x70com.example.blah";
		$value = DagCbor::decode($bytes);
		$this->assertSame($bytes, DagCbor::encode($value));
		$this->assertSame('{"a":[],"b":{},"$type":"com.example.blah"}', json_encode(DagCbor::toLexJson($value)), 'and the JSON form keeps the two apart');
		$this->assertSame(Cid::forDagCbor($bytes)->toString(), Cid::forDagCbor(DagCbor::encode($value))->toString());
	}

	/**
	 * The data-model vector "empty list and object", read as JSON objects
	 * so `{}` is not lost on the way in, encodes `b` as the empty map.
	 */
	public function testTheEmptyObjectOfTheDataModelVectorIsAMap(): void {
		$vectors = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/data-model-valid.json'), false, 512, JSON_THROW_ON_ERROR);
		$vector = array_values(array_filter($vectors, static fn (\stdClass $vector): bool => $vector->note === 'empty list and object'))[0];

		$encoded = DagCbor::encode(DagCbor::fromLexJson($vector->json));
		$this->assertStringContainsString("\x61a\x80", $encoded);
		$this->assertStringContainsString("\x61b\xa0", $encoded);
		$this->assertEquals($vector->json, json_decode((string)json_encode(DagCbor::toLexJson(DagCbor::decode($encoded)))));
	}

	/**
	 * A plain object of any size is the map of its properties, also where
	 * its keys look like list indexes.
	 */
	public function testAnObjectIsTheMapOfItsProperties(): void {
		$this->assertSame(DagCbor::encode(['a' => 1, 'bb' => [1]]), DagCbor::encode((object)['bb' => [1], 'a' => 1]));
		$this->assertSame("\xa1\x610\x01", DagCbor::encode((object)['0' => 1]));
	}

	public function testACarRoundTrips(): void {
		$record = DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'hi']);
		$recordCid = Cid::forDagCbor($record);
		$blob = 'raw bytes';
		$blobCid = Cid::forRaw($blob);
		$car = Car::encode([$recordCid], [$recordCid->toString() => $record, $blobCid->toString() => $blob]);

		$decoded = Car::decode($car);
		$this->assertCount(1, $decoded['roots']);
		$this->assertTrue($recordCid->equals($decoded['roots'][0]));
		$this->assertSame([$recordCid->toString() => $record, $blobCid->toString() => $blob], $decoded['blocks']);
	}

	public function testACarWhoseBlockDoesNotMatchItsCidIsRefused(): void {
		$record = DagCbor::encode(['a' => 1]);
		$car = Car::encode([Cid::forDagCbor($record)], [Cid::forDagCbor('other')->toString() => $record]);

		$this->expectException(InvalidArgumentException::class);
		Car::decode($car);
	}

	public function testTidsAreOrderedAndUnique(): void {
		$first = Tid::next();
		$second = Tid::next();
		$this->assertTrue(Tid::isValid($first));
		$this->assertGreaterThan(0, strcmp($second, $first));

		[$micros, $clock] = Tid::decode($first);
		$this->assertSame($first, Tid::encode($micros, $clock));
		$this->assertGreaterThan(1700000000000000, $micros);

		$later = Tid::encode($micros + 86400000000, $clock);
		$this->assertGreaterThan(0, strcmp(Tid::after($later), $later), 'after() steps past a head that is ahead of the clock');
	}

	/**
	 * The JSON fixtures hold 123.0 as a float; PHP's JSON parser does too,
	 * and the protocol says a float that is whole is still not an integer.
	 * The valid fixture's "float, but integer-like" is accepted by the
	 * reference parsers only after that conversion, which is applied here.
	 */
	private static function integral(mixed $value): mixed {
		if (is_float($value) && floor($value) === $value) {
			return (int)$value;
		}
		if (is_array($value)) {
			return array_map(self::integral(...), $value);
		}

		return $value;
	}
}
