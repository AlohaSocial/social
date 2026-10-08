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
use OCA\Social\Atproto\Model\Event;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Db\AtprotoEventRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * A repository over commits: the head moves, the tree is what the records
 * say, the commit is signed, the dropped nodes go, the CAR is whole, and
 * the firehose frame carries what a relay needs to follow along.
 */
#[AllowMockObjectsWithoutExpectations]
class RepositoryServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private InMemoryRepoRequest $store;
	private PrivateKey $key;
	private RepositoryService $service;
	/** @var array<int, array{did: string, kind: string, body: array}> */
	private array $frames = [];

	protected function setUp(): void {
		$this->store = new InMemoryRepoRequest();
		$this->key = PrivateKey::generate(Curve::K256);
		$events = $this->createMock(AtprotoEventRequest::class);
		$events->method('append')->willReturnCallback(function (string $did, string $kind, string $body): int {
			$this->frames[] = ['did' => $did, 'kind' => $kind, 'body' => DagCbor::decode($body)];

			return count($this->frames);
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$connection = $this->createMock(IDBConnection::class);
		$this->service = new RepositoryService($this->store, new EventService($events, $time), new Lexicon(), $connection);
	}

	public function testTheFirstCommitMakesTheRepository(): void {
		$result = $this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.feed.post', self::post('one'), 'https://s.test/1', '3kznmn7xqxl22')]);

		$head = $this->store->getHead(self::DID);
		$this->assertNotNull($head);
		$this->assertSame($result->commitCid->toString(), $head->commitCid);
		$this->assertSame($result->rev, $head->rev);
		$this->assertSame(1, $head->recordCount);
		$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22', $result->uriOf('app.bsky.feed.post/3kznmn7xqxl22'));

		$commit = $this->service->headCommit(self::DID);
		$this->assertNotNull($commit);
		$this->assertTrue($commit->verify($this->key->publicKey()));
		$this->assertTrue($commit->data->equals((new Mst($this->store->getLeaves(self::DID)))->build()->root));
		$this->assertSame([], $this->service->verify(self::DID, $this->key->publicKey()));

		$frame = $this->frames[0];
		$this->assertSame(Event::KIND_COMMIT, $frame['kind']);
		$this->assertSame(self::DID, $frame['body']['repo']);
		$this->assertNull($frame['body']['since'], 'nothing came before');
		$this->assertArrayNotHasKey('prevData', $frame['body']);
		$this->assertEquals([['action' => 'create', 'path' => 'app.bsky.feed.post/3kznmn7xqxl22', 'cid' => $result->cidOf('app.bsky.feed.post/3kznmn7xqxl22')]], $frame['body']['ops']);
		$car = Car::decode($frame['body']['blocks']->value);
		$this->assertTrue($car['roots'][0]->equals($result->commitCid), 'the commit is the CAR root');
		$this->assertArrayHasKey($result->cidOf('app.bsky.feed.post/3kznmn7xqxl22')->toString(), $car['blocks'], 'the record travels with the frame');
		$this->assertTrue(array_key_first($car['blocks']) === $result->commitCid->toString(), 'the commit block comes first');
	}

	public function testALaterCommitFollowsFromTheLast(): void {
		$first = $this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.feed.post', self::post('one'), '', '3kznmn7xqxl22')]);
		$second = $this->service->write(self::DID, $this->key, [
			RepoWrite::create('app.bsky.feed.post', self::post('two'), '', '3kznmn7xqxl23'),
			RepoWrite::update('app.bsky.feed.post', '3kznmn7xqxl22', self::post('one, edited'), ''),
		]);

		$this->assertGreaterThan(0, strcmp($second->rev, $first->rev));
		$frame = $this->frames[1]['body'];
		$this->assertSame($first->rev, $frame['since']);
		$this->assertTrue($frame['prevData']->equals(Commit::fromBytes((string)$this->store->getBlock(self::DID, $first->commitCid->toString()) ?: $this->commitBytesOf($first))->data));
		$this->assertSame('update', $frame['ops'][1]['action']);
		$this->assertTrue($frame['ops'][1]['prev']->equals($first->cidOf('app.bsky.feed.post/3kznmn7xqxl22')), 'an update names the record it replaced');
		$this->assertSame([], $this->service->verify(self::DID, $this->key->publicKey()));
		$this->assertCount(1, $this->store->getBlockCids(self::DID, AtprotoRepoRequest::BLOCK_COMMIT), 'only the head commit is kept');
	}

	public function testADeleteDropsTheRecordAndTheNodesItWasIn(): void {
		$this->service->write(self::DID, $this->key, [
			RepoWrite::create('app.bsky.feed.post', self::post('one'), '', '3kznmn7xqxl22'),
			RepoWrite::create('app.bsky.feed.post', self::post('two'), '', '3kznmn7xqxl23'),
		]);
		$this->service->write(self::DID, $this->key, [RepoWrite::delete('app.bsky.feed.post', '3kznmn7xqxl23')]);

		$this->assertNull($this->service->getRecord(self::DID, 'app.bsky.feed.post', '3kznmn7xqxl23'));
		$this->assertSame(1, $this->store->getHead(self::DID)?->recordCount);
		$this->assertSame([], $this->service->verify(self::DID, $this->key->publicKey()));
		$tree = (new Mst($this->store->getLeaves(self::DID)))->build();
		$this->assertEqualsCanonicalizing(array_keys($tree->blocks), $this->store->getBlockCids(self::DID, AtprotoRepoRequest::BLOCK_MST), 'exactly the live tree is stored');
		$this->assertSame('delete', $this->frames[1]['body']['ops'][0]['action']);
		$this->assertNull($this->frames[1]['body']['ops'][0]['cid']);
	}

	public function testTheCarIsTheWholeRepository(): void {
		$result = $this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.actor.profile', ['$type' => 'app.bsky.actor.profile', 'displayName' => 'Alice'], '', 'self')]);

		$car = Car::decode($this->service->exportCar(self::DID));

		$this->assertTrue($car['roots'][0]->equals($result->commitCid));
		$tree = (new Mst($this->store->getLeaves(self::DID)))->build();
		$expected = [$result->commitCid->toString(), ...array_keys($tree->blocks), $result->cidOf('app.bsky.actor.profile/self')->toString()];
		$this->assertEqualsCanonicalizing($expected, array_keys($car['blocks']));
	}

	public function testARecordThatDoesNotFitItsLexiconIsNeverSigned(): void {
		$this->expectException(AtprotoException::class);
		$this->expectExceptionMessage('does not fit its lexicon');
		$this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.feed.post', ['$type' => 'app.bsky.feed.post', 'text' => str_repeat('x', 301), 'createdAt' => '2026-10-08T10:00:00.000Z'], '', '3kznmn7xqxl22')]);
		$this->assertNull($this->store->getHead(self::DID));
	}

	public function testACreateOverAnExistingRecordIsRefused(): void {
		$this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.feed.post', self::post('one'), '', '3kznmn7xqxl22')]);
		$this->expectException(AtprotoException::class);
		$this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.feed.post', self::post('again'), '', '3kznmn7xqxl22')]);
	}

	public function testVerifyNoticesATamperedHead(): void {
		$this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.feed.post', self::post('one'), '', '3kznmn7xqxl22')]);
		$other = PrivateKey::generate(Curve::K256);

		$this->assertContains('the head commit is not signed by the signing key', $this->service->verify(self::DID, $other->publicKey()));
	}

	private static function post(string $text): array {
		return ['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => '2026-10-08T10:00:00.000Z'];
	}

	private function commitBytesOf(\OCA\Social\Atproto\Repository\CommitResult $result): string {
		foreach ($this->frames as $frame) {
			if ($frame['body']['commit']->equals($result->commitCid)) {
				return Car::decode($frame['body']['blocks']->value)['blocks'][$result->commitCid->toString()];
			}
		}

		return '';
	}
}
