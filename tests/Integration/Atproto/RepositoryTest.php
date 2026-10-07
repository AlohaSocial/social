<?php
declare(strict_types=1);
namespace OCA\Social\Tests\Integration\Atproto;
use OCA\Social\Atproto\Identity\KeyManager;
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Atproto\Protocol\{Cid, DagCbor};
use OCP\{Server, IDBConnection};
use PHPUnit\Framework\TestCase;
/** Real database transactions, BLOB round trips, signed heads and rollback; no PLC/network writes. */
class RepositoryTest extends TestCase {
	private string $did;
	private IDBConnection $db;
	private Repository $repo;
	private array $key;
	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class); $this->repo = Server::get(Repository::class);
		$this->key = Server::get(KeyManager::class)->generateSigningKey();
		$this->did = 'did:plc:' . substr(Cid::base32(random_bytes(32)), 0, 24);
		$qb = $this->db->getQueryBuilder(); $qb->insert('social_atproto_repo')->values(['did' => $qb->createNamedParameter($this->did), 'updated' => $qb->createNamedParameter(gmdate('Y-m-d H:i:s'))])->executeStatement();
	}
	protected function tearDown(): void {
		foreach (['event', 'block', 'record', 'repo'] as $table) {
			$qb = $this->db->getQueryBuilder(); $qb->delete('social_atproto_' . $table)->where($qb->expr()->eq('did', $qb->createNamedParameter($this->did)))->executeStatement();
		}
	}
	public function testCreateDeleteAndBinaryEventsVerifyAgainstPersistedHead(): void {
		$collection = 'app.bsky.feed.post'; $rkey = '3abcdefgh2345';
		$record = $this->repo->transaction(function () use ($collection, $rkey) {
			$record = $this->repo->createRecord($this->did, $collection, $rkey, ['$type' => $collection, 'text' => 'Grüße 😀', 'createdAt' => '2026-10-07T12:00:00Z'], '9223372036854775808');
			$this->repo->commit($this->did, $this->key['private']); return $record;
		});
		self::assertSame($record->bytes, $this->repo->getRecord($this->did, $collection, $rkey)->bytes);
		$this->repo->verify($this->did, $this->key['didKey']); $first = $this->repo->getHead($this->did);
		$qb = $this->db->getQueryBuilder(); $qb->select('bytes')->from('social_atproto_event')->where($qb->expr()->eq('did', $qb->createNamedParameter($this->did)));
		$event = DagCbor::decode(Repository::bytes($qb->executeQuery()->fetchOne()));
		self::assertSame('create', $event['ops'][0]['action']); self::assertSame($record->cid, $event['ops'][0]['cid']->value);
		self::assertSame($first['commit_cid'], $event['commit']->value); self::assertNotEmpty($this->repo->exportCar($this->did));
		$this->repo->transaction(function () use ($collection, $rkey) { $this->repo->deleteRecord($this->did, $collection, $rkey); $this->repo->commit($this->did, $this->key['private']); });
		self::assertNull($this->repo->getRecord($this->did, $collection, $rkey)); $this->repo->verify($this->did, $this->key['didKey']);
		self::assertGreaterThan($first['rev'], $this->repo->getHead($this->did)['rev']);
	}
	public function testFailedPublicationRollsBackRecordHeadAndEventTogether(): void {
		$this->repo->commit($this->did, $this->key['private']); $head = $this->repo->getHead($this->did);
		try {
			$this->repo->transaction(function () { $this->repo->createRecord($this->did, 'app.bsky.feed.post', 'rollback', ['$type' => 'app.bsky.feed.post', 'text' => 'Must not survive']); $this->repo->commit($this->did, $this->key['private']); throw new \RuntimeException('abort publication'); });
			self::fail('The transaction must propagate the failure');
		} catch (\RuntimeException $e) { self::assertSame('abort publication', $e->getMessage()); }
		self::assertSame($head, $this->repo->getHead($this->did)); self::assertNull($this->repo->getRecord($this->did, 'app.bsky.feed.post', 'rollback')); $this->repo->verify($this->did, $this->key['didKey']);
	}
}
