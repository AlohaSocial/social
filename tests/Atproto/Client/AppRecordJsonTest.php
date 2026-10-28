<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\ClientXrpc;
use OCA\Social\Atproto\Client\SessionService;
use OCA\Social\Atproto\Client\WriteService;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoEventRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Tests\Atproto\Repository\InMemoryRepoRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A record a Bluesky app writes here as it is (a list, a gate, a feed
 * generator…), from the JSON of `createRecord`, `putRecord` and
 * `applyWrites` to the bytes in the repository: an empty object stays the
 * empty map, so the record's CID is the one any other implementation
 * computes from the same JSON, and a blob's `$link` becomes a CID link.
 *
 * The expected bytes are written out by hand from the DAG-CBOR rules
 * (map keys by length, then bytewise), not by the encoder under test.
 */
#[AllowMockObjectsWithoutExpectations]
class AppRecordJsonTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const LIST_JSON = '{"$type":"app.bsky.graph.list","name":"Friends","purpose":"app.bsky.graph.defs#curatelist","createdAt":"2026-10-09T10:00:00.000Z","extra":{},"tags":[]}';

	private InMemoryRepoRequest $store;
	private ClientXrpc $client;

	protected function setUp(): void {
		$this->store = new InMemoryRepoRequest();
		$events = $this->createMock(AtprotoEventRequest::class);
		$events->method('append')->willReturn(1);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$repositories = new RepositoryService($this->store, new EventService($events, $time), new Lexicon(), $this->createMock(IDBConnection::class));

		$identity = new Identity(1, 'https://social.example.com/users/alice', self::DID, 'alice.social.example.com', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setId('https://social.example.com/users/alice'));
		$writes = $this->build(WriteService::class, ['repositories' => $repositories, 'identities' => $identities, 'accounts' => $accounts]);

		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('appViewDid')->willReturn('did:web:api.bsky.app');
		$sessions = $this->createMock(SessionService::class);
		$sessions->method('authenticate')->willReturn(new ClientSession('alice', $identity, 'jti'));
		$this->client = $this->build(ClientXrpc::class, ['config' => $config, 'sessions' => $sessions, 'writes' => $writes]);
	}

	/**
	 * The list of LIST_JSON as DAG-CBOR: keys `name`, `tags`, `$type`,
	 * `extra`, `purpose`, `createdAt`; `extra` the empty map 0xa0, `tags`
	 * the empty list 0x80.
	 */
	private static function listBytes(string $name = 'Friends'): string {
		return "\xa6"
			. "\x64name" . chr(0x60 + strlen($name)) . $name
			. "\x64tags\x80"
			. "\x65\$type\x73app.bsky.graph.list"
			. "\x65extra\xa0"
			. "\x67purpose\x78\x1eapp.bsky.graph.defs#curatelist"
			. "\x69createdAt\x78\x182026-10-09T10:00:00.000Z";
	}

	public function testCreateRecordKeepsAnEmptyObjectAMapAndItsCid(): void {
		$answer = $this->call('com.atproto.repo.createRecord', '{"repo":"' . self::DID . '","collection":"app.bsky.graph.list","rkey":"3kznmn7xqxl22","record":' . self::LIST_JSON . '}');

		$stored = $this->store->getRecord(self::DID, 'app.bsky.graph.list', '3kznmn7xqxl22');
		$this->assertSame(bin2hex(self::listBytes()), bin2hex((string)$stored?->bytes));
		$this->assertSame(Cid::forDagCbor(self::listBytes())->toString(), $answer['cid']);
		$this->assertSame($answer['cid'], $stored?->cid->toString());
	}

	public function testAnOpaqueApplicationRecordKeepsItsExactJsonShape(): void {
		$recordJson = '{"$type":"com.example.notes.entry","extra":{},"tags":[]}';
		$expected = "\xa3\x64tags\x80\x65\$type\x77com.example.notes.entry\x65extra\xa0";
		$answer = $this->call('com.atproto.repo.createRecord', '{"repo":"' . self::DID . '","collection":"com.example.notes.entry","rkey":"first","record":' . $recordJson . '}');

		$stored = $this->store->getRecord(self::DID, 'com.example.notes.entry', 'first');
		$this->assertSame(bin2hex($expected), bin2hex((string)$stored?->bytes));
		$this->assertSame(Cid::forDagCbor($expected)->toString(), $answer['cid']);
		$this->assertSame('unknown', $answer['validationStatus']);
	}

	public function testNumericKeyObjectsRemainMapsAcrossEveryWriteOperation(): void {
		$record = '{"$type":"com.example.notes.entry","metadata":{"0":"first"},"list":["first"]}';
		$body = '{"repo":"' . self::DID . '","collection":"com.example.notes.entry","rkey":"numeric","record":' . $record . '}';
		$created = $this->call('com.atproto.repo.createRecord', $body);
		$updated = $this->call('com.atproto.repo.putRecord', $body);
		$this->assertSame($created['cid'], $updated['cid']);
		$batch = $this->call('com.atproto.repo.applyWrites', '{"repo":"' . self::DID . '","writes":[{"$type":"com.atproto.repo.applyWrites#create","collection":"com.example.notes.entry","rkey":"numeric-batch","value":' . $record . '}]}');
		$this->assertSame($created['cid'], $batch['results'][0]['cid']);

		foreach (['numeric', 'numeric-batch'] as $key) {
			$stored = $this->store->getRecord(self::DID, 'com.example.notes.entry', $key);
			$this->assertNotNull($stored);
			$this->assertStringContainsString("\xa1\x610\x65first", $stored->bytes);
			$decoded = $stored->value();
			$this->assertInstanceOf(\stdClass::class, $decoded['metadata']);
			$this->assertSame(['first'], $decoded['list']);
			$this->assertSame($stored->bytes, DagCbor::encode($decoded));
			$this->assertEquals(json_decode($record, true), json_decode(json_encode(DagCbor::toLexJson($decoded), JSON_THROW_ON_ERROR), true));
			$this->assertStringContainsString('"metadata":{"0":"first"}', json_encode(DagCbor::toLexJson($decoded), JSON_THROW_ON_ERROR));
		}
	}

	public function testExplicitValidationRejectsAnUnknownLexicon(): void {
		$this->expectException(\OCA\Social\Atproto\Xrpc\XrpcException::class);
		$this->call('com.atproto.repo.createRecord', '{"repo":"' . self::DID . '","collection":"com.example.notes.entry","rkey":"first","validate":true,"record":{"$type":"com.example.notes.entry"}}');
	}

	public function testDuplicateOpaqueCreationKeepsTheOriginalCidAndContent(): void {
		$body = '{"repo":"' . self::DID . '","collection":"com.example.notes.entry","rkey":"first","record":{"$type":"com.example.notes.entry","text":"original"}}';
		$created = $this->call('com.atproto.repo.createRecord', $body);
		try {
			$this->call('com.atproto.repo.createRecord', str_replace('original', 'replacement', $body));
			$this->fail('createRecord must not replace an existing record');
		} catch (\OCA\Social\Atproto\Xrpc\XrpcException) {
			$stored = $this->store->getRecord(self::DID, 'com.example.notes.entry', 'first');
			$this->assertSame($created['cid'], $stored?->cid->toString());
			$this->assertSame('original', $stored?->value()['text']);
		}
	}

	public function testStrictBatchValidationRejectsUnknownSchemasWithoutWriting(): void {
		try {
			$this->call('com.atproto.repo.applyWrites', '{"repo":"' . self::DID . '","validate":true,"writes":[{"$type":"com.atproto.repo.applyWrites#create","collection":"com.example.notes.entry","rkey":"first","value":{"$type":"com.example.notes.entry"}}]}');
			$this->fail('strict validation requires a known Lexicon');
		} catch (\OCA\Social\Atproto\Xrpc\XrpcException) {
			$this->assertNull($this->store->getRecord(self::DID, 'com.example.notes.entry', 'first'));
		}
	}

	public function testDisabledSchemaValidationStillStoresKnownRecordsAndReportsUnknown(): void {
		$record = '{"$type":"app.bsky.graph.list","extra":{}}';
		$created = $this->call('com.atproto.repo.createRecord', '{"repo":"' . self::DID . '","collection":"app.bsky.graph.list","rkey":"first","validate":false,"record":' . $record . '}');
		$this->assertSame('unknown', $created['validationStatus']);
		$updated = $this->call('com.atproto.repo.putRecord', '{"repo":"' . self::DID . '","collection":"app.bsky.graph.list","rkey":"first","validate":false,"record":' . $record . '}');
		$this->assertSame('unknown', $updated['validationStatus']);
		$batch = $this->call('com.atproto.repo.applyWrites', '{"repo":"' . self::DID . '","validate":false,"writes":[{"$type":"com.atproto.repo.applyWrites#create","collection":"app.bsky.graph.list","rkey":"second","value":' . $record . '}]}');
		$this->assertSame('unknown', $batch['results'][0]['validationStatus']);
	}

	public function testPutRecordAndApplyWritesKeepItToo(): void {
		$this->call('com.atproto.repo.createRecord', '{"repo":"' . self::DID . '","collection":"app.bsky.graph.list","rkey":"3kznmn7xqxl22","record":' . self::LIST_JSON . '}');
		$renamed = str_replace('"Friends"', '"Family"', self::LIST_JSON);

		$put = $this->call('com.atproto.repo.putRecord', '{"repo":"' . self::DID . '","collection":"app.bsky.graph.list","rkey":"3kznmn7xqxl22","record":' . $renamed . '}');
		$this->assertSame(Cid::forDagCbor(self::listBytes('Family'))->toString(), $put['cid']);

		$applied = $this->call('com.atproto.repo.applyWrites', '{"repo":"' . self::DID . '","writes":[{"$type":"com.atproto.repo.applyWrites#create","collection":"app.bsky.graph.list","rkey":"3kznmn7xqxl23","value":' . self::LIST_JSON . '}]}');
		$this->assertSame(Cid::forDagCbor(self::listBytes())->toString(), $applied['results'][0]['cid']);
		$this->assertSame(self::listBytes(), $this->store->getRecord(self::DID, 'app.bsky.graph.list', '3kznmn7xqxl23')?->bytes);
	}

	/**
	 * A list's avatar names its blob as `{"$link": cid}` in JSON; in the
	 * repository that is a CID link, which is what the lexicon checks a
	 * blob's `ref` for.
	 */
	public function testABlobLinkInAKeptRecordIsACidLink(): void {
		$cid = Cid::forRaw('a picture');
		$json = '{"$type":"app.bsky.graph.list","name":"Friends","purpose":"app.bsky.graph.defs#curatelist","createdAt":"2026-10-09T10:00:00.000Z","avatar":{"$type":"blob","ref":{"$link":"' . $cid->toString() . '"},"mimeType":"image/png","size":9}}';

		$this->call('com.atproto.repo.createRecord', '{"repo":"' . self::DID . '","collection":"app.bsky.graph.list","rkey":"3kznmn7xqxl22","record":' . $json . '}');

		$value = $this->store->getRecord(self::DID, 'app.bsky.graph.list', '3kznmn7xqxl22')?->value();
		$this->assertInstanceOf(Cid::class, $value['avatar']['ref'] ?? null);
		$this->assertTrue($cid->equals($value['avatar']['ref']));
	}

	private function call(string $method, string $json): array {
		$answer = $this->client->procedure($method, $json, ['authorization' => 'Bearer an-app-password-session'], '192.0.2.1');
		$this->assertIsArray($answer);

		return $answer;
	}

	/**
	 * The class with a test double for every constructor argument but the
	 * ones given by name, so the test does not follow each new dependency.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @param array<string, object> $given
	 * @return T
	 */
	private function build(string $class, array $given): object {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			$arguments[] = $given[$parameter->getName()] ?? $this->createMock($type instanceof \ReflectionNamedType ? $type->getName() : 'stdClass');
		}

		return new $class(...$arguments);
	}
}
