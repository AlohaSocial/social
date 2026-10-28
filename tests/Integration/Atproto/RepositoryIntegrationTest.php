<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Model\Event;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoClientRequest;
use OCA\Social\Db\AtprotoEventRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The Bluesky tables against the real database: a repository written in
 * commits, its blocks stored as bytes and read back whole, the firehose
 * rows numbered in order, and the identity and blob rows round-tripping.
 */
class RepositoryIntegrationTest extends TestCase {
	private const DID = 'did:plc:integrationtest00000000';
	private const ACTOR = 'https://nextcloud.test/index.php/apps/social/@atproto-integration';
	private const OLDER_HEALTH_DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const NEWER_HEALTH_DID = 'did:plc:zv2c4g5p6q7r8s9t0u1v2w3x';

	private RepositoryService $repositories;
	private AtprotoRepoRequest $repoRequest;
	private AtprotoEventRequest $eventRequest;
	private AtprotoIdentityRequest $identityRequest;
	private AtprotoBlobRequest $blobRequest;
	private AtprotoPlcLogRequest $plcLog;
	private PrivateKey $key;

	protected function setUp(): void {
		$this->repositories = Server::get(RepositoryService::class);
		$this->repoRequest = Server::get(AtprotoRepoRequest::class);
		$this->eventRequest = Server::get(AtprotoEventRequest::class);
		$this->identityRequest = Server::get(AtprotoIdentityRequest::class);
		$this->blobRequest = Server::get(AtprotoBlobRequest::class);
		$this->plcLog = Server::get(AtprotoPlcLogRequest::class);
		$this->key = PrivateKey::generate(Curve::K256);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testARepositoryIsWrittenInCommitsAndReadBackWhole(): void {
		$before = $this->eventRequest->latestSeq();
		$first = $this->repositories->write(self::DID, $this->key, [
			RepoWrite::create('app.bsky.actor.profile', ['$type' => 'app.bsky.actor.profile', 'displayName' => 'Integration'], self::ACTOR, 'self'),
		]);
		$second = $this->repositories->write(self::DID, $this->key, [
			RepoWrite::create('app.bsky.feed.post', ['$type' => 'app.bsky.feed.post', 'text' => 'Hello Bluesky ✨', 'createdAt' => '2026-10-08T10:00:00.000Z'], self::ACTOR . '/1'),
		]);

		$head = $this->repositories->getHead(self::DID);
		$this->assertNotNull($head);
		$this->assertSame($second->commitCid->toString(), $head->commitCid);
		$this->assertSame(2, $head->recordCount);
		$this->assertSame([2, 1], [$this->repoRequest->countRecords(self::DID), $this->repoRequest->countRecords(self::DID, 'app.bsky.feed.post')], 'counted whole and by collection');
		$this->assertGreaterThan(0, strcmp($second->rev, $first->rev));
		$this->assertSame([], $this->repositories->verify(self::DID, $this->key->publicKey()), 'the stored blocks are the tree');

		$records = $this->repositories->getRecordsByLocalId(self::ACTOR . '/1');
		$this->assertCount(1, $records);
		$this->assertSame('Hello Bluesky ✨', $records[0]->value()['text'], 'bytes survive the BLOB column');

		$car = Car::decode(implode('', iterator_to_array($this->repositories->streamCar(self::DID), false)));
		$this->assertTrue($car['roots'][0]->equals($second->commitCid));
		$this->assertArrayHasKey($records[0]->cid->toString(), $car['blocks']);

		$events = $this->eventRequest->after($before);
		$this->assertCount(2, $events);
		$this->assertSame(Event::KIND_COMMIT, $events[0]->kind);
		$this->assertGreaterThan($events[0]->seq, $events[1]->seq, 'the database numbers the frames in order');
		$frame = $events[1]->frame();
		$this->assertStringStartsWith("\xa2", $frame, 'a two-key header');
		$this->assertSame($events[1]->seq, $this->eventRequest->latestSeq());

		$this->repositories->write(self::DID, $this->key, [RepoWrite::delete('app.bsky.feed.post', $records[0]->rkey)]);
		$this->assertSame(1, $this->repositories->getHead(self::DID)?->recordCount);
		$this->assertSame([], $this->repositories->verify(self::DID, $this->key->publicKey()));
		$this->assertCount(1, $this->repoRequest->getBlockCids(self::DID, AtprotoRepoRequest::BLOCK_COMMIT));
	}

	public function testNumericRecordKeyPaginationMatchesTheStoredAndStagedViews(): void {
		$collection = 'com.example.notes.entry';
		$record = ['$type' => $collection, 'text' => 'numeric key'];
		$this->repositories->write(self::DID, $this->key, [
			RepoWrite::create($collection, $record, '', '10'),
			RepoWrite::create($collection, $record, '', '2'),
		]);
		$keys = fn (string $cursor, bool $reverse): array => array_map(
			static fn ($item): string => $item->rkey,
			$this->repositories->listRecords(self::DID, $collection, 10, $cursor, $reverse),
		);
		$this->assertSame(['10'], $keys('2', false));
		$this->assertSame(['2'], $keys('10', true));
		$this->repositories->beginBatch(self::DID, $this->key);
		try {
			$this->repositories->write(self::DID, $this->key, [RepoWrite::create($collection, $record, '', '20')]);
			$this->assertSame(['2', '20'], $keys('10', true));
			$this->assertSame(['10'], $keys('2', false));
		} finally {
			$this->repositories->rollbackBatch();
		}
		$this->assertNull($this->repositories->getRecord(self::DID, $collection, '20'));
		$this->assertSame([], $this->repositories->verify(self::DID, $this->key->publicKey()));
	}

	public function testHealthProbeSelectsTheNewestPostOnlyFromAnActiveIdentity(): void {
		$this->identityRequest->create(self::ACTOR, self::DID, 'atproto-integration.nextcloud.test', 'sealed', 'did:key:zQ3sh', '', Identity::STATE_PENDING);
		$this->repositories->write(self::DID, $this->key, [
			RepoWrite::create('app.bsky.feed.post', ['$type' => 'app.bsky.feed.post', 'text' => 'older health probe', 'createdAt' => '2026-10-08T10:00:00.000Z'], self::ACTOR . '/health-older', '3khealthprobe1'),
			RepoWrite::create('app.bsky.feed.post', ['$type' => 'app.bsky.feed.post', 'text' => 'newer health probe', 'createdAt' => '2026-10-08T10:01:00.000Z'], self::ACTOR . '/health-newer', '3khealthprobe2'),
		]);

		$this->assertNull($this->repoRequest->latestActivePost(), 'a pending DID is not a public account');
		$this->assertNull($this->identityRequest->firstActive(), 'the health probe skips pending identities');
		$this->identityRequest->setState(self::DID, 'active');

		$this->assertSame(self::DID, $this->identityRequest->firstActive()?->did);
		$this->assertSame(self::DID, $this->identityRequest->latestActive()?->did);
		$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3khealthprobe2', $this->repoRequest->latestActivePost()?->uri());
	}

	public function testHealthProbeSelectsTheNewestActiveIdentity(): void {
		$this->identityRequest->create('https://social.test/users/older', self::OLDER_HEALTH_DID, 'older.social.test', 'sealed', 'did:key:zQ3sh', '', Identity::STATE_ACTIVE);
		$this->identityRequest->create('https://social.test/users/newer', self::NEWER_HEALTH_DID, 'newer.social.test', 'sealed', 'did:key:zQ3sh', '', Identity::STATE_ACTIVE);

		$this->assertSame(self::OLDER_HEALTH_DID, $this->identityRequest->firstActive()?->did);
		$this->assertSame(self::NEWER_HEALTH_DID, $this->identityRequest->latestActive()?->did);
	}

	/**
	 * The head row is read with a row lock at the start of a commit, which
	 * the database must accept in a transaction: the lock holds the second
	 * commit of a repository until the first is through, and a commit made
	 * after the locked read still chains on the head it read.
	 */
	public function testTheHeadIsLockedForTheLengthOfACommit(): void {
		$first = $this->repositories->write(self::DID, $this->key, [
			RepoWrite::create('app.bsky.actor.profile', ['$type' => 'app.bsky.actor.profile', 'displayName' => 'Integration'], self::ACTOR, 'self'),
		]);
		$connection = Server::get(\OCP\IDBConnection::class);

		$connection->beginTransaction();
		try {
			$locked = $this->repoRequest->lockHead(self::DID);
			$this->assertSame($first->commitCid->toString(), $locked?->commitCid, 'the locked read is the head');
			$this->assertNull($this->repoRequest->lockHead('did:plc:integrationtest00000001'), 'nothing to lock before the first commit');
		} finally {
			$connection->rollBack();
		}

		$second = $this->repositories->write(self::DID, $this->key, [
			RepoWrite::create('app.bsky.feed.post', ['$type' => 'app.bsky.feed.post', 'text' => 'after the lock', 'createdAt' => '2026-10-08T10:00:00.000Z'], self::ACTOR . '/2'),
		]);
		$this->assertSame($second->commitCid->toString(), $this->repositories->getHead(self::DID)?->commitCid);
		$this->assertSame([], $this->repositories->verify(self::DID, $this->key->publicKey()));
	}

	/**
	 * A post's record is looked for inside the commit's transaction, after
	 * the head is locked: a second publish of the same post, which found no
	 * record before it began, writes nothing and moves no head.
	 */
	public function testAPostIsNotWrittenTwiceForOneLocalId(): void {
		$post = ['$type' => 'app.bsky.feed.post', 'text' => 'once', 'createdAt' => '2026-10-08T10:00:00.000Z'];
		$before = $this->eventRequest->latestSeq();
		$first = $this->repositories->write(self::DID, $this->key, [RepoWrite::createOnce('app.bsky.feed.post', $post, self::ACTOR . '/4', '3kznmn7xqxl44')]);

		$second = $this->repositories->write(self::DID, $this->key, [RepoWrite::createOnce('app.bsky.feed.post', $post, self::ACTOR . '/4', '3kznmn7xqxl45')]);

		$this->assertNotNull($first);
		$this->assertNull($second);
		$this->assertCount(1, $this->repositories->getRecordsByLocalId(self::ACTOR . '/4'));
		$this->assertSame($first->commitCid->toString(), $this->repositories->getHead(self::DID)?->commitCid);
		$this->assertCount(1, $this->eventRequest->after($before), 'one frame on the firehose');
		$this->assertSame([], $this->repositories->verify(self::DID, $this->key->publicKey()));
	}

	/**
	 * `getBlocks` finds a block wherever it is kept, by (did, cid): a tree
	 * node or the commit in the block table, a record in the record table,
	 * and nothing for a CID the repository does not have.
	 */
	public function testBlocksAreFoundByCidAcrossBothTables(): void {
		$result = $this->repositories->write(self::DID, $this->key, [
			RepoWrite::create('app.bsky.feed.post', ['$type' => 'app.bsky.feed.post', 'text' => 'by cid', 'createdAt' => '2026-10-08T10:00:00.000Z'], self::ACTOR . '/3', '3kznmn7xqxl33'),
		]);
		$record = $this->repositories->getRecord(self::DID, 'app.bsky.feed.post', '3kznmn7xqxl33');
		$this->assertNotNull($record);
		$node = $this->repoRequest->getBlockCids(self::DID, AtprotoRepoRequest::BLOCK_MST)[0];
		$absent = 'bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm';

		$found = $this->repoRequest->getBlocks(self::DID, [$node, $result->commitCid->toString(), $record->cid->toString(), $absent]);

		$this->assertEqualsCanonicalizing([$node, $result->commitCid->toString(), $record->cid->toString()], array_keys($found));
		$this->assertSame($record->bytes, $found[$record->cid->toString()]);
		$this->assertSame([], $this->repoRequest->getBlocks('did:plc:integrationtest00000001', [$node]), 'another repository has none of them');
	}

	public function testAnIdentityRowRoundTrips(): void {
		$this->identityRequest->create(self::ACTOR, self::DID, 'atproto-integration.nextcloud.test', 'sealed', 'did:key:zQ3sh', '');

		$byDid = $this->identityRequest->getByDid(self::DID);
		$this->assertSame(self::ACTOR, $byDid->actorId);
		$this->assertSame('atproto-integration.nextcloud.test', $this->identityRequest->getByActorId(self::ACTOR)->handle);
		$this->assertSame(self::DID, $this->identityRequest->getByHandle('ATPROTO-Integration.nextcloud.test')->did, 'handles are looked up case-insensitively');
		$this->assertTrue($this->identityRequest->handleExists('atproto-integration.nextcloud.test'));
		$this->assertGreaterThan(0, $byDid->creation);

		$this->identityRequest->setRecoveryPublic(self::DID, 'did:key:zRecovery');
		$off = $this->identityRequest->countInState('deactivated');
		$this->identityRequest->setState(self::DID, 'deactivated');
		$again = $this->identityRequest->getByDid(self::DID);
		$this->assertSame('did:key:zRecovery', $again->recoveryPublic);
		$this->assertFalse($again->isActive());
		$this->assertSame($off + 1, $this->identityRequest->countInState('deactivated'), 'counted by its state');

		$logId = $this->plcLog->record(self::DID, 'bafyop', ['type' => 'plc_operation', 'prev' => null]);
		$this->assertSame('bafyop', $this->plcLog->latestCid(self::DID));
		$this->assertCount(1, $this->plcLog->getUnconfirmed());
		$this->plcLog->markConfirmed($logId);
		$this->assertSame([], array_filter($this->plcLog->getUnconfirmed(), static fn (array $row): bool => $row['did'] === self::DID));
		$this->assertGreaterThan(0, $this->plcLog->getByDid(self::DID)[0]['confirmed']);
	}

	public function testSigningAPersonOutOfBlueskyAppsEndsTheirSessionsOnly(): void {
		$clients = Server::get(AtprotoClientRequest::class);
		$clients->addSession('integration-alice', 'atproto-integration-alice', self::DID, 1, time() + 3600);
		$clients->addSession('integration-bob', 'atproto-integration-bob', 'did:plc:integrationbob000000000', 2, time() + 3600);

		$clients->removeSessionsOfUser('atproto-integration-alice');

		$this->assertNull($clients->getSession('integration-alice'));
		$this->assertNotNull($clients->getSession('integration-bob'));
		$clients->removeSession('integration-bob');
	}

	public function testAppPasswordOwnershipAndRefreshTokenConsumptionAreAtomic(): void {
		$clients = Server::get(AtprotoClientRequest::class);
		$suffix = bin2hex(random_bytes(8));
		$userId = 'atproto-integration-' . $suffix;
		$sessionId = 'integration-refresh-' . $suffix;
		$passwordId = $clients->addAppPassword($userId, 'integration refresh ' . $suffix, password_hash('secret', PASSWORD_DEFAULT));
		try {
			$this->assertTrue($clients->hasAppPassword($userId, $passwordId));
			$this->assertFalse($clients->hasAppPassword('atproto-integration-bob', $passwordId));
			$clients->addSession($sessionId, $userId, self::DID, $passwordId, time() + 3600);
			$this->assertTrue($clients->removeSession($sessionId), 'the first refresh consumes the session');
			$this->assertFalse($clients->removeSession($sessionId), 'a concurrent or replayed refresh cannot consume it twice');
		} finally {
			$clients->removeSession($sessionId);
			$clients->removeAppPassword($userId, $passwordId);
		}
	}

	public function testBlobsAreListedByCid(): void {
		$blob = new \OCA\Social\Atproto\Model\BlobRef(self::DID, \OCA\Social\Atproto\Protocol\Cid::forRaw('picture'), self::ACTOR . '/doc/1', 'image/jpeg', 7);
		$this->blobRequest->put($blob);
		$this->blobRequest->put($blob);

		$this->assertCount(1, $this->blobRequest->list(self::DID, 10), 'put twice is one row');
		$this->assertSame(7, $this->blobRequest->getByDocument(self::DID, self::ACTOR . '/doc/1')?->size);
		$this->assertSame('image/jpeg', $this->blobRequest->get(self::DID, $blob->cid->toString())?->mime);
	}

	private function cleanUp(): void {
		$this->repositories->delete(self::DID);
		$this->blobRequest->deleteByDid(self::DID);
		$this->identityRequest->deleteByDid(self::DID);
		$this->identityRequest->deleteByDid(self::OLDER_HEALTH_DID);
		$this->identityRequest->deleteByDid(self::NEWER_HEALTH_DID);
		try {
			$this->identityRequest->getByDid(self::DID);
			$this->fail('identity row still there');
		} catch (AtprotoIdentityNotFoundException) {
		}
		Server::get(EventService::class);
		$connection = Server::get(\OCP\IDBConnection::class);
		foreach (['social_atproto_event', 'social_atproto_plc_log'] as $table) {
			$qb = $connection->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('did', $qb->createNamedParameter(self::DID)));
			$qb->executeStatement();
		}
	}
}
