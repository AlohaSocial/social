<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Repository;

use OCA\Social\Atproto\Identity\KeyManager;
use OCP\IDBConnection;
use OCP\ILogger;
use SpomkyLabs\Cbor\CborEncoder;
use SpomkyLabs\Cbor\CborDecoder;

class Record {
	public function __construct(
		public readonly string $did,
		public readonly string $collection,
		public readonly string $rkey,
		public readonly string $cid,
		public readonly string $bytes, // DAG-CBOR encoded
		public readonly ?int $localId = null,
		public readonly string $createdAt = ''
	) {}
	
	public static function create(string $did, string $collection, string $rkey, array $value, ?int $localId = null): self {
		$encoded = CborEncoder::encode($value);
		$hash = hash('sha256', $encoded, true);
		$multicodec = hex2bin('0171');
		$multihash = hex2bin('1220') . $hash;
		$cidBytes = $multicodec . $multihash;
		$cid = 'b' . base_encode($cidBytes, 32);
		
		return new self(
			did: $did,
			collection: $collection,
			rkey: $rkey,
			cid: $cid,
			bytes: $encoded,
			localId: $localId,
			createdAt: (new \DateTime())->format('Y-m-d H:i:s')
		);
	}
	
	public function getValue(): array {
		return CborDecoder::decode($this->bytes);
	}
	
	public function getAtUri(): string {
		return "at://{$this->did}/{$this->collection}/{$this->rkey}";
	}
}

function base_encode(string $data, int $base): string {
	$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
	$bits = '';
	foreach (str_split($data) as $char) {
		$bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
	}
	$bits = str_pad($bits, (int)ceil(strlen($bits) / 5) * 5, '0', STR_PAD_RIGHT);
	$result = '';
	for ($i = 0; $i < strlen($bits); $i += 5) {
		$chunk = substr($bits, $i, 5);
		$result .= $alphabet[bindec($chunk)];
	}
	return $result;
}

class Commit {
	public const VERSION = 3;
	
	public function __construct(
		public readonly string $did,
		public readonly string $dataCid, // Root MST CID
		public readonly string $rev, // TID
		public readonly ?string $prevCid,
		public readonly string $sig // 64-byte low-S secp256k1 signature
	) {}
	
	public static function create(string $did, string $dataCid, string $rev, ?string $prevCid, string $signingKey): self {
		$unsignedCommit = [
			'version' => self::VERSION,
			'did' => $did,
			'data' => $dataCid,
			'rev' => $rev,
			'prev' => $prevCid
		];
		
		$encoded = CborEncoder::encode($unsignedCommit);
		
		$keyManager = new KeyManager(null, null); // Would be injected
		$sig = $keyManager->sign($encoded, $signingKey);
		
		return new self(
			did: $did,
			dataCid: $dataCid,
			rev: $rev,
			prevCid: $prevCid,
			sig: $sig
		);
	}
	
	public function getCid(): string {
		$commit = [
			'version' => self::VERSION,
			'did' => $this->did,
			'data' => $this->dataCid,
			'rev' => $this->rev,
			'prev' => $this->prevCid,
			'sig' => $this->sig
		];
		
		$encoded = CborEncoder::encode($commit);
		$hash = hash('sha256', $encoded, true);
		$multicodec = hex2bin('0171');
		$multihash = hex2bin('1220') . $hash;
		$cidBytes = $multicodec . $multihash;
		return 'b' . base_encode($cidBytes, 32);
	}
}

class Repository {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILogger $logger,
		private readonly KeyManager $keyManager
	) {}
	
	public function createRecord(string $did, string $collection, string $rkey, array $value, ?int $localId = null): Record {
		$record = Record::create($did, $collection, $rkey, $value, $localId);
		
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atproto_record')
			->values([
				'did' => $qb->createNamedParameter($did),
				'collection' => $qb->createNamedParameter($collection),
				'rkey' => $qb->createNamedParameter($rkey),
				'cid' => $qb->createNamedParameter($record->cid),
				'bytes' => $qb->createNamedParameter($record->bytes),
				'local_id' => $localId !== null ? $qb->createNamedParameter($localId, \PDO::PARAM_INT) : null,
				'created' => $qb->createNamedParameter($record->createdAt)
			])
			->executeStatement();
		
		return $record;
	}
	
	public function deleteRecord(string $did, string $collection, string $rkey): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('social_atproto_record')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)))
			->andWhere($qb->expr()->eq('rkey', $qb->createNamedParameter($rkey)))
			->executeStatement();
	}
	
	public function getRecord(string $did, string $collection, string $rkey): ?Record {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_record')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)))
			->andWhere($qb->expr()->eq('rkey', $qb->createNamedParameter($rkey)));
		
		$result = $qb->executeQuery()->fetchAssociative();
		if (!$result) {
			return null;
		}
		
		return new Record(
			did: $result['did'],
			collection: $result['collection'],
			rkey: $result['rkey'],
			cid: $result['cid'],
			bytes: $result['bytes'],
			localId: $result['local_id'] ?? null,
			createdAt: $result['created']
		);
	}
	
	public function getRecords(string $did, string $collection): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_record')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)));
		
		$results = $qb->executeQuery()->fetchAllAssociative();
		$records = [];
		foreach ($results as $result) {
			$records[$result['rkey']] = new Record(
				did: $result['did'],
				collection: $result['collection'],
				rkey: $result['rkey'],
				cid: $result['cid'],
				bytes: $result['bytes'],
				localId: $result['local_id'] ?? null,
				createdAt: $result['created']
			);
		}
		return $records;
	}
	
	public function commit(string $did, string $signingKey, ?string $prevCommitCid = null): Commit {
		// Get all records for this DID
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_record')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		
		$results = $qb->executeQuery()->fetchAllAssociative();
		
		// Build records array for MST
		$records = [];
		foreach ($results as $result) {
			$key = $result['collection'] . '/' . $result['rkey'];
			$records[$key] = [
				'cid' => $result['cid'],
				'value' => CborDecoder::decode($result['bytes'])
			];
		}
		
		// Build MST and get root CID
		$dataCid = MerkleSearchTree::build($records);
		
		// Generate TID for rev
		$rev = $this->generateTid();
		
		// Create commit
		$commit = Commit::create($did, $dataCid, $rev, $prevCommitCid, $signingKey);
		$commitCid = $commit->getCid();
		
		// Store commit and MST blocks
		$this->storeCommitBlocks($did, $dataCid, $commit);
		
		// Update repo head
		$qb = $this->db->getQueryBuilder();
		$qb->update('social_atproto_repo')
			->set('commit_cid', $qb->createNamedParameter($commitCid))
			->set('rev', $qb->createNamedParameter($rev))
			->set('record_count', $qb->createNamedParameter(count($records), \PDO::PARAM_INT))
			->set('updated', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->executeStatement();
		
		// Create firehose event
		$this->createFirehoseEvent($did, $commit, $records);
		
		return $commit;
	}
	
	public function getHead(string $did): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_repo')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	
	private function generateTid(): string {
		// Generate timestamp ID (TID) - 13 chars base32
		$microtime = (int)(microtime(true) * 1000000);
		$bytes = '';
		for ($i = 5; $i >= 0; $i--) {
			$bytes .= chr(($microtime >> ($i * 8)) & 0xFF);
		}
		// Pad to 8 bytes for base32
		$bytes = str_pad($bytes, 8, "\0", STR_PAD_LEFT);
		return substr(base_encode($bytes, 32), 0, 13);
	}
	
	private function storeCommitBlocks(string $did, string $dataCid, Commit $commit): void {
		// Store MST nodes as blocks
		// This is simplified - real implementation would traverse the MST
		$qb = $this->db->getQueryBuilder();
		
		// Store commit block
		$commitEncoded = CborEncoder::encode([
			'version' => Commit::VERSION,
			'did' => $did,
			'data' => $dataCid,
			'rev' => $commit->rev,
			'prev' => $commit->prevCid,
			'sig' => $commit->sig
		]);
		
		$qb->insert('social_atproto_block')
			->values([
				'cid' => $qb->createNamedParameter($commit->getCid()),
				'did' => $qb->createNamedParameter($did),
				'bytes' => $qb->createNamedParameter($commitEncoded),
				'kind' => $qb->createNamedParameter('commit')
			])
			->executeStatement();
	}
	
	private function createFirehoseEvent(string $did, Commit $commit, array $records): void {
		$ops = [];
		// Simplified - would compute actual diff from previous commit
		foreach ($records as $path => $record) {
			$ops[] = [
				'action' => 'create',
				'path' => $path,
				'cid' => $record['cid']
			];
		}
		
		$event = [
			'did' => $did,
			'kind' => '#commit',
			'bytes' => json_encode([
				'seq' => 0, // Will be set by event service
				'did' => $did,
				'rev' => $commit->rev,
				'commit' => $commit->getCid(),
				'ops' => $ops,
				'blocks' => [], // CAR file would go here
				'time' => (new \DateTime())->format('Y-m-d H:i:s')
			]),
			'time' => (new \DateTime())->format('Y-m-d H:i:s')
		];
		
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atproto_event')
			->values([
				'did' => $qb->createNamedParameter($did),
				'kind' => $qb->createNamedParameter('#commit'),
				'bytes' => $qb->createNamedParameter($event['bytes']),
				'time' => $qb->createNamedParameter($event['time'])
			])
			->executeStatement();
	}
}