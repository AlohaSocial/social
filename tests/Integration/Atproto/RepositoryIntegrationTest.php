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
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Db\AtprotoBlobRequest;
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
		$this->assertGreaterThan(0, strcmp($second->rev, $first->rev));
		$this->assertSame([], $this->repositories->verify(self::DID, $this->key->publicKey()), 'the stored blocks are the tree');

		$records = $this->repositories->getRecordsByLocalId(self::ACTOR . '/1');
		$this->assertCount(1, $records);
		$this->assertSame('Hello Bluesky ✨', $records[0]->value()['text'], 'bytes survive the BLOB column');

		$car = Car::decode($this->repositories->exportCar(self::DID));
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

	public function testAnIdentityRowRoundTrips(): void {
		$this->identityRequest->create(self::ACTOR, self::DID, 'atproto-integration.nextcloud.test', 'sealed', 'did:key:zQ3sh', '');

		$byDid = $this->identityRequest->getByDid(self::DID);
		$this->assertSame(self::ACTOR, $byDid->actorId);
		$this->assertSame('atproto-integration.nextcloud.test', $this->identityRequest->getByActorId(self::ACTOR)->handle);
		$this->assertSame(self::DID, $this->identityRequest->getByHandle('ATPROTO-Integration.nextcloud.test')->did, 'handles are looked up case-insensitively');
		$this->assertTrue($this->identityRequest->handleExists('atproto-integration.nextcloud.test'));
		$this->assertGreaterThan(0, $byDid->creation);

		$this->identityRequest->setRecoveryPublic(self::DID, 'did:key:zRecovery');
		$this->identityRequest->setState(self::DID, 'deactivated');
		$again = $this->identityRequest->getByDid(self::DID);
		$this->assertSame('did:key:zRecovery', $again->recoveryPublic);
		$this->assertFalse($again->isActive());

		$logId = $this->plcLog->record(self::DID, 'bafyop', ['type' => 'plc_operation', 'prev' => null]);
		$this->assertSame('bafyop', $this->plcLog->latestCid(self::DID));
		$this->assertCount(1, $this->plcLog->getUnconfirmed());
		$this->plcLog->markConfirmed($logId);
		$this->assertSame([], array_filter($this->plcLog->getUnconfirmed(), static fn (array $row): bool => $row['did'] === self::DID));
		$this->assertGreaterThan(0, $this->plcLog->getByDid(self::DID)[0]['confirmed']);
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
