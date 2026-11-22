<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Repository;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Db\AtprotoEventRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A repository that moves here (§13.1): its records are taken byte for
 * byte, so every CID another record or another server holds stays good,
 * under one commit signed by the account's new key whose revision follows
 * the old repository's; a later write builds on it. Then what verify()
 * notices when the stored repository and its head disagree, and the
 * bounds of a write.
 */
#[AllowMockObjectsWithoutExpectations]
class RepositoryImportTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const EMPTY_TREE = 'bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm';

	private InMemoryRepoRequest $store;
	private RepositoryService $service;
	private PrivateKey $key;
	private int $frames = 0;
	private int $rollbacks = 0;

	protected function setUp(): void {
		$this->store = new InMemoryRepoRequest();
		$this->key = PrivateKey::generate(Curve::K256);
		$events = $this->createMock(AtprotoEventRequest::class);
		$events->method('append')->willReturnCallback(fn (): int => ++$this->frames);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('rollBack')->willReturnCallback(function (): void {
			$this->rollbacks++;
		});
		$this->service = new RepositoryService($this->store, new EventService($events, $time), new Lexicon(), $connection);
	}

	/**
	 * Records of any application, as another PDS holds them: not checked
	 * against a lexicon, and kept exactly as they were encoded.
	 *
	 * @return array<string, string> path => bytes
	 */
	private static function foreignRecords(): array {
		return [
			'app.bsky.feed.post/3jzfcijpj2z2a' => DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'from before', 'createdAt' => '2024-01-01T00:00:00.000Z']),
			'app.bsky.actor.profile/self' => DagCbor::encode(['$type' => 'app.bsky.actor.profile', 'displayName' => 'Alice']),
			'com.whtwnd.blog.entry/3kabcabcabc22' => DagCbor::encode(['$type' => 'com.whtwnd.blog.entry', 'content' => str_repeat('a long entry ', 500), 'extra' => ['nested' => [1, 2, 3]]]),
		];
	}

	public function testAMovedRepositoryKeepsEveryRecordByteForByte(): void {
		$records = self::foreignRecords();
		$commitCid = $this->service->import(self::DID, $this->key, $records, '3kzzzzzzzzz22');

		$head = $this->service->getHead(self::DID);
		$this->assertSame($commitCid->toString(), $head?->commitCid);
		$this->assertSame(3, $head->recordCount);
		$this->assertGreaterThan(0, strcmp($head->rev, '3kzzzzzzzzz22'), 'the revision follows the old repository\'s');
		$this->assertSame([], $this->service->verify(self::DID, $this->key->publicKey()));

		$car = Car::decode(implode('', iterator_to_array($this->service->streamCar(self::DID), false)));
		foreach ($records as $path => $bytes) {
			[$collection, $rkey] = explode('/', $path);
			$this->assertSame($bytes, $this->service->getRecord(self::DID, $collection, $rkey)?->bytes);
			$this->assertSame($bytes, $car['blocks'][Cid::forDagCbor($bytes)->toString()]);
		}
		$this->assertSame(0, $this->frames, 'nothing on the firehose until the account is activated');
	}

	public function testARevisionFromTheFutureIsNotFollowed(): void {
		$future = Tid::encode((time() + 365 * 86400) * 1000000, 0);
		$this->service->import(self::DID, $this->key, self::foreignRecords(), $future);
		$this->assertLessThan(0, strcmp((string)$this->service->getHead(self::DID)?->rev, $future), 'a year ahead would hold every later commit there');

		$this->setUp();
		$drifted = Tid::encode((time() + 60) * 1000000, 0);
		$this->service->import(self::DID, $this->key, self::foreignRecords(), $drifted);
		$this->assertGreaterThan(0, strcmp((string)$this->service->getHead(self::DID)?->rev, $drifted), 'a clock a minute fast is followed');
	}

	public function testALaterWriteBuildsOnTheImport(): void {
		$this->service->import(self::DID, $this->key, self::foreignRecords(), '3kzzzzzzzzz22');
		$imported = $this->service->getHead(self::DID);

		$this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.feed.post', ['$type' => 'app.bsky.feed.post', 'text' => 'written here', 'createdAt' => '2026-10-09T12:00:00.000Z'])]);

		$head = $this->service->getHead(self::DID);
		$this->assertSame(4, $head?->recordCount);
		$this->assertGreaterThan(0, strcmp($head->rev, (string)$imported?->rev));
		$this->assertSame([], $this->service->verify(self::DID, $this->key->publicKey()));
	}

	public function testAnEmptyImportIsTheEmptyTree(): void {
		$commitCid = $this->service->import(self::DID, $this->key, []);
		$commit = Commit::fromBytes((string)$this->store->getBlock(self::DID, $commitCid->toString()));

		$this->assertSame(self::EMPTY_TREE, $commit->data->toString());
		$this->assertTrue($commit->verify($this->key->publicKey()));
	}

	public function testARepositoryIsNotImportedOverAnother(): void {
		$this->service->import(self::DID, $this->key, []);

		$this->expectException(AtprotoException::class);
		$this->service->import(self::DID, $this->key, self::foreignRecords());
	}

	public function testAPathThatIsNotARecordPathIsRefusedAndNothingIsKept(): void {
		try {
			$this->service->import(self::DID, $this->key, ['../escape' => DagCbor::encode(['a' => 1])]);
			$this->fail('imported');
		} catch (AtprotoException $e) {
			$this->assertStringContainsString('Not a record path', $e->getMessage());
		}
		$this->assertNull($this->service->getHead(self::DID));
		$this->assertSame(1, $this->rollbacks);
	}

	/**
	 * @return iterable<string, array{0: callable(InMemoryRepoRequest, string): void, 1: string}>
	 */
	public static function damages(): iterable {
		yield 'the head commit block gone' => [static function (InMemoryRepoRequest $store, string $did): void {
			$store->deleteBlocks($did, [(string)$store->getHead($did)?->commitCid]);
		}, 'the head commit block is missing'];
		yield 'a record changed under the tree' => [static function (InMemoryRepoRequest $store, string $did): void {
			$bytes = DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'changed']);
			$store->putRecord($did, 'app.bsky.feed.post', '3jzfcijpj2z2a', Cid::forDagCbor($bytes), $bytes, '');
		}, 'the tree rebuilt from the records has another root'];
		yield 'a tree node gone' => [static function (InMemoryRepoRequest $store, string $did): void {
			$store->deleteBlocks($did, [$store->getBlockCids($did, AtprotoRepoRequest::BLOCK_MST)[0]]);
		}, 'is not stored'];
		yield 'a node that is not in the tree' => [static function (InMemoryRepoRequest $store, string $did): void {
			$stray = DagCbor::encode(['e' => [], 'l' => Cid::forRaw('stray')]);
			$store->putBlocks($did, AtprotoRepoRequest::BLOCK_MST, [Cid::forDagCbor($stray)->toString() => $stray]);
		}, 'is not in the tree'];
		yield 'a head whose revision is not the commit\'s' => [static function (InMemoryRepoRequest $store, string $did): void {
			$head = $store->getHead($did);
			$store->setHead($did, Cid::parse((string)$head?->commitCid), Tid::next(), (int)$head?->recordCount);
		}, 'the head revision differs from the commit'];
	}

	/**
	 * @param callable(InMemoryRepoRequest, string): void $damage
	 */
	#[DataProvider('damages')]
	public function testVerifyNamesWhatIsWrong(callable $damage, string $problem): void {
		$this->service->import(self::DID, $this->key, self::foreignRecords());
		$damage($this->store, self::DID);

		$problems = $this->service->verify(self::DID, $this->key->publicKey());
		$this->assertNotSame([], $problems);
		$this->assertStringContainsString($problem, implode("\n", $problems));
	}

	public function testVerifyOfNoRepository(): void {
		$this->assertSame(['no repository'], $this->service->verify(self::DID, $this->key->publicKey()));
	}

	public function testACommitChangesBetweenOneAndTheMostRecordsAtOnce(): void {
		foreach ([[], array_fill(0, RepositoryService::MAX_WRITES + 1, null)] as $writes) {
			$writes = array_map(static fn (): RepoWrite => RepoWrite::create('app.bsky.graph.follow', ['$type' => 'app.bsky.graph.follow', 'subject' => 'did:plc:z72i7hdynmk6r22z27h6tvur', 'createdAt' => '2026-10-09T12:00:00.000Z']), $writes);
			try {
				$this->service->write(self::DID, $this->key, $writes);
				$this->fail(count($writes) . ' writes were taken');
			} catch (AtprotoException) {
				$this->addToAssertionCount(1);
			}
		}
		$this->assertNull($this->service->getHead(self::DID));
	}

	public function testAnUpdateOrDeleteOfNothingIsRefused(): void {
		$this->service->import(self::DID, $this->key, self::foreignRecords());
		$before = $this->service->getHead(self::DID)?->commitCid;

		foreach ([
			RepoWrite::update('app.bsky.feed.post', '3kznmn7xqxl22', ['$type' => 'app.bsky.feed.post', 'text' => 'x', 'createdAt' => '2026-10-09T12:00:00.000Z']),
			RepoWrite::delete('app.bsky.feed.post', '3kznmn7xqxl22'),
		] as $write) {
			try {
				$this->service->write(self::DID, $this->key, [$write]);
				$this->fail($write->action . ' of nothing was taken');
			} catch (AtprotoException $e) {
				$this->assertStringContainsString('No record at', $e->getMessage());
			}
		}
		$this->assertSame($before, $this->service->getHead(self::DID)?->commitCid, 'the head did not move');
	}
}
