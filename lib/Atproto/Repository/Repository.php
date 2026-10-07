<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Repository;

use CBOR\Decoder;
use CBOR\Encoder;
use CBOR\StringStream;
use OCA\Social\Atproto\Identity\KeyManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

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
		$encoded = (new Encoder())->encode($value);
		$hash = hash('sha256', $encoded, true);
		$multicodec = \OCA\Social\Atproto\Repository\MerkleSearchTree::encodeVarint(0x71);
		$multihash = hex2bin('1220') . $hash;
		$cidBytes = $multicodec . $multihash;
		$cid = 'b' . \OCA\Social\Atproto\Repository\MerkleSearchTree::base32Encode($cidBytes);
		
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
		return self::decodeCbor($this->bytes);
	}

	public static function decodeCbor(string $bytes): array {
		$decoded = (new Decoder())->decode(StringStream::create($bytes))->normalize();
		if (!is_array($decoded)) {
			throw new \UnexpectedValueException('Expected a CBOR map');
		}

		return self::restoreIntegerValues($decoded);
	}

	private static function restoreIntegerValues(array $value): array {
		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$value[$key] = self::restoreIntegerValues($item);
			} elseif (is_string($item) && preg_match('/^-?(?:0|[1-9][0-9]*)$/', $item) === 1) {
				$integer = filter_var($item, FILTER_VALIDATE_INT);
				if ($integer !== false) {
					$value[$key] = $integer;
				}
			}
		}

		return $value;
	}
	
	public function getAtUri(): string {
		return "at://{$this->did}/{$this->collection}/{$this->rkey}";
	}
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
		
		$encoded = (new Encoder())->encode($unsignedCommit);
		
		$keyManager = new KeyManager($this->config, $this->logger);
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
		
		$encoded = (new Encoder())->encode($commit);
		$hash = hash('sha256', $encoded, true);
		$multicodec = \OCA\Social\Atproto\Repository\MerkleSearchTree::encodeVarint(0x71);
		$multihash = hex2bin('1220') . $hash;
		$cidBytes = $multicodec . $multihash;
		return 'b' . \OCA\Social\Atproto\Repository\MerkleSearchTree::base32Encode($cidBytes);
	}
	
	public function toCbor(): string {
		return (new Encoder())->encode([
			'version' => self::VERSION,
			'did' => $this->did,
			'data' => $this->dataCid,
			'rev' => $this->rev,
			'prev' => $this->prevCid,
			'sig' => $this->sig
		]);
	}
}

class Repository {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly KeyManager $keyManager,
		private readonly \OCP\IConfig $config
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
				'value' => Record::decodeCbor($result['bytes'])
			];
		}
		
		// Build MST and get root CID + blocks
		$mstResult = MerkleSearchTree::exportCar($records);
		$dataCid = $mstResult['root'];
		$mstBlocks = $mstResult['blocks'];
		
		// Generate TID for rev
		$rev = $this->generateTid();
		
		// Create commit
		$commit = Commit::create($did, $dataCid, $rev, $prevCommitCid, $signingKey);
		$commitCid = $commit->getCid();
		$commitCbor = $commit->toCbor();
		
		// Store commit block
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atproto_block')
			->values([
				'cid' => $qb->createNamedParameter($commitCid),
				'did' => $qb->createNamedParameter($did),
				'bytes' => $qb->createNamedParameter($commitCbor),
				'kind' => $qb->createNamedParameter('commit')
			])
			->executeStatement();
		
		// Store MST blocks
		foreach ($mstBlocks as $cid => $blockBytes) {
			$qb->insert('social_atproto_block')
				->values([
					'cid' => $qb->createNamedParameter($cid),
					'did' => $qb->createNamedParameter($did),
					'bytes' => $qb->createNamedParameter($blockBytes),
					'kind' => $qb->createNamedParameter('mst')
				])
				->onConflict(['did', 'cid'])
				->doNothing()
				->executeStatement();
		}
		
		// Update repo head
		$qb = $this->db->getQueryBuilder();
		$qb->update('social_atproto_repo')
			->set('commit_cid', $qb->createNamedParameter($commitCid))
			->set('rev', $qb->createNamedParameter($rev))
			->set('record_count', $qb->createNamedParameter(count($records), \PDO::PARAM_INT))
			->set('updated', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->executeStatement();
		
		// Create firehose event with proper CAR blocks
		$this->createFirehoseEvent($did, $commit, $records, $mstBlocks, $commitCbor);
		
		return $commit;
	}
	
	public function getHead(string $did): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_repo')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	
	/**
	 * Export repository as CAR file
	 */
	public function exportCar(string $did): string {
		// Get all records
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_record')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		
		$results = $qb->executeQuery()->fetchAllAssociative();
		
		$records = [];
		foreach ($results as $result) {
			$key = $result['collection'] . '/' . $result['rkey'];
			$records[$key] = [
				'cid' => $result['cid'],
				'value' => Record::decodeCbor($result['bytes'])
			];
		}
		
		// Build MST and get blocks
		$mstResult = MerkleSearchTree::exportCar($records);
		$blocks = $mstResult['blocks'];
		
		// Add commit block
		$head = $this->getHead($did);
		if ($head && $head['commit_cid']) {
			$commitBlock = $this->getBlock($did, $head['commit_cid']);
			if ($commitBlock) {
				$blocks[$head['commit_cid']] = $commitBlock;
			}
		}
		
		// Build CAR file
		return $this->buildCar($mstResult['root'], $blocks);
	}
	
	private function buildCar(string $rootCid, array $blocks): string {
		// CARv1 header: 
		// - version (varint): 1
		// - roots (array of CIDs): [rootCid]
		// - blocks: concatenated CBOR blocks
		
		$header = '';
		$header .= \OCA\Social\Atproto\Repository\MerkleSearchTree::encodeVarint(1); // version
		$header .= \OCA\Social\Atproto\Repository\MerkleSearchTree::encodeVarint(1); // roots array length
		$header .= $this->encodeCid($rootCid);
		
		// Blocks
		$blockData = '';
		foreach ($blocks as $cid => $block) {
			$blockData .= $this->encodeCid($cid);
			$blockData .= \OCA\Social\Atproto\Repository\MerkleSearchTree::encodeVarint(strlen($block));
			$blockData .= $block;
		}
		
		return $header . $blockData;
	}
	
	private function encodeCid(string $cid): string {
		// Decode base32 CID back to bytes
		$cid = substr($cid, 1); // Remove 'b' prefix
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$bits = '';
		foreach (str_split($cid) as $char) {
			$idx = strpos($alphabet, $char);
			if ($idx !== false) {
				$bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
			}
		}
		// Convert bits to bytes
		$bytes = '';
		for ($i = 0; $i < strlen($bits); $i += 8) {
			$chunk = substr($bits, $i, 8);
			if (strlen($chunk) === 8) {
				$bytes .= chr(bindec($chunk));
			}
		}
		return $bytes;
	}
	
	private function generateTid(): string {
		$microtime = (int)(microtime(true) * 1000000);
		$bytes = '';
		for ($i = 5; $i >= 0; $i--) {
			$bytes .= chr(($microtime >> ($i * 8)) & 0xFF);
		}
		$bytes = str_pad($bytes, 8, "\0", STR_PAD_LEFT);
		return substr(\OCA\Social\Atproto\Repository\MerkleSearchTree::base32Encode($bytes), 0, 13);
	}
	
	private function storeCommitBlocks(string $did, string $dataCid, Commit $commit): void {
		// Now handled in commit() method
	}
	
	private function createFirehoseEvent(string $did, Commit $commit, array $records, array $mstBlocks, string $commitCbor): void {
		$ops = [];
		foreach ($records as $path => $record) {
			$ops[] = [
				'action' => 'create',
				'path' => $path,
				'cid' => $record['cid']
			];
		}
		
		// Build CAR for this commit
		$carBlocks = $mstBlocks;
		$carBlocks[$commit->getCid()] = $commitCbor;
		
		$carData = $this->buildCar($commit->dataCid, $carBlocks);
		
		$event = [
			'did' => $did,
			'kind' => '#commit',
			'bytes' => json_encode([
				'seq' => 0, // Will be set by event service
				'did' => $did,
				'rev' => $commit->rev,
				'commit' => $commit->getCid(),
				'ops' => $ops,
				'blocks' => base64_encode($carData), // CAR as base64 for JSON storage
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
	
	private function getBlock(string $did, string $cid): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('bytes')
			->from('social_atproto_block')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('cid', $qb->createNamedParameter($cid)));
		
		return $qb->executeQuery()->fetchOne() ?: null;
	}
}