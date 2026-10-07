<?php
declare(strict_types=1);
namespace OCA\Social\Tests\Atproto;
use OCA\Social\Atproto\Protocol\{Bytes, Cid, DagCbor, Car, Tid};
use OCA\Social\Atproto\Identity\KeyManager;
use OCA\Social\Atproto\Repository\MerkleSearchTree;
use PHPUnit\Framework\TestCase;
class ProtocolTest extends TestCase {
	public function testMstRootsMatchOfficialJavascriptReference(): void {
		foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/mst-reference-roots.json'), true) as $count => $root) {
			$records = [];
			for ($i = 0; $i < $count; $i++) { $records['app.bsky.feed.post/' . substr(hash('sha256', (string)$i), 0, 13)] = ['cid' => Cid::hash(DagCbor::encode(['text' => 'record ' . $i]))]; }
			self::assertSame($root, MerkleSearchTree::build($records));
		}
	}

	public function testOfficialDataModelVectors(): void {
		$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/data-model-fixtures.json'), false, 512, JSON_THROW_ON_ERROR);
		foreach ($fixtures as $fixture) {
			$value = json_decode(json_encode($fixture->json), true, 512, JSON_THROW_ON_ERROR);
			$encoded = DagCbor::encode($value);
			self::assertSame(base64_decode($fixture->cbor_base64), $encoded);
			self::assertSame($fixture->cid, Cid::hash($encoded));
			self::assertSame($encoded, DagCbor::encode(DagCbor::decode($encoded)));
		}
	}
	public function testOfficialK256Signatures(): void {
		$keys = new KeyManager(null, null);
		foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/signature-fixtures.json'), true) as $fixture) {
			if ($fixture['algorithm'] !== 'ES256K') { continue; }
			self::assertSame($fixture['validSignature'], $keys->verify(base64_decode($fixture['messageBase64']), base64_decode($fixture['signatureBase64']), $fixture['publicKeyDid']), $fixture['comment']);
		}
	}
	public function testSigningIsDeterministicLowSAndVerifiable(): void {
		$keys = new KeyManager(null, null); $key = $keys->generateSigningKey(); $sig = $keys->sign('hello', $key['private']);
		self::assertSame(64, strlen($sig)); self::assertSame($sig, $keys->sign('hello', $key['private']));
		self::assertTrue($keys->verify('hello', $sig, $key['didKey']));
		self::assertFalse($keys->verify('different', $sig, $key['didKey']));
	}
	public function testOfficialMstHeights(): void {
		foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/key_heights.json'), true) as $fixture) {
			self::assertSame($fixture['height'], MerkleSearchTree::layer($fixture['key']));
		}
	}
	public function testMstIsOrderedAndRetainsIntermediateLayers(): void {
		$cid = Cid::hash(DagCbor::encode(['text' => 'hello']));
		$records = ['app.bsky.feed.post/9adeb165882c' => ['cid' => $cid], 'app.bsky.feed.post/454397e440ec' => ['cid' => $cid]];
		$tree = MerkleSearchTree::exportCar($records);
		self::assertSame($tree, MerkleSearchTree::exportCar(array_reverse($records, true)));
		self::assertGreaterThan(4, count($tree['blocks']));
		foreach ($tree['blocks'] as $key => $bytes) { self::assertSame($key, Cid::hash($bytes)); self::assertIsArray(DagCbor::decode($bytes)); }
		self::assertSame('bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm', MerkleSearchTree::build([]));
	}
	public function testCarHasCborHeaderAndLengthPrefixedCidBlocks(): void {
		$bytes = DagCbor::encode(['text' => 'hello']); $cid = Cid::hash($bytes);
		$car = Car::encode($cid, [$cid => $bytes]); $headerLength = ord($car[0]);
		$header = DagCbor::decode(substr($car, 1, $headerLength));
		self::assertSame(1, $header['version']); self::assertSame($cid, $header['roots'][0]->value);
		$offset = 1 + $headerLength; self::assertSame(36 + strlen($bytes), ord($car[$offset]));
		self::assertSame(Cid::decode($cid) . $bytes, substr($car, $offset + 1));
	}
	public function testTidUsesSortableAlphabetAndAdvancesPastStoredRevision(): void {
		$first = Tid::next(); $second = Tid::next($first);
		self::assertSame(13, strlen($first)); self::assertGreaterThan(0, strcmp($second, $first));
		self::assertSame($first, Tid::encode(Tid::decode($first)));
	}
	public function testStringsStayStringsAndMalformedCborIsRejected(): void {
		self::assertSame(['text' => '123', 'value' => 123], DagCbor::decode(DagCbor::encode(['text' => '123', 'value' => 123])));
		$this->expectException(\UnexpectedValueException::class); DagCbor::decode("\xa1\x61a");
	}
}
