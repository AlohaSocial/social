<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\Repository;

use OCA\Social\Atproto\Identity\KeyManager;
use OCA\Social\Atproto\Protocol\Bytes;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Tid;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class Repository {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly KeyManager $keyManager,
	) {
	}
	public function createRecord(string $did, string $collection, string $rkey, array $value, int|string|null $localId = null): Record {
		if ($this->getRecord($did, $collection, $rkey) !== null) {
			throw new \InvalidArgumentException('Record already exists');
		}
		$record = Record::create($did, $collection, $rkey, $value, $localId);
		$this->insert('social_atproto_record', ['did' => $did, 'collection' => $collection, 'rkey' => $rkey, 'cid' => $record->cid,
			'bytes' => $record->bytes, 'local_id' => $record->localId, 'created' => $record->createdAt], ['bytes']);
		return $record;
	}
	public function deleteRecord(string $did, string $collection, string $rkey): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('social_atproto_record')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)))->andWhere($qb->expr()->eq('rkey', $qb->createNamedParameter($rkey)))->executeStatement();
	}
	public function getRecord(string $did, string $collection, string $rkey): ?Record {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('social_atproto_record')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)))->andWhere($qb->expr()->eq('rkey', $qb->createNamedParameter($rkey)));
		$row = $qb->executeQuery()->fetchAssociative();
		return $row ? $this->record($row) : null;
	}
	public function getRecords(string $did, string $collection): array {
		$records = [];
		foreach ($this->rows($did, $collection) as $row) {
			$records[$row['rkey']] = $this->record($row);
		} return $records;
	}
	private function record(array $row): Record {
		return new Record($row['did'], $row['collection'], $row['rkey'], $row['cid'], self::bytes($row['bytes']),
			isset($row['local_id']) ? (string)$row['local_id'] : null, $row['created']);
	}
	private function rows(string $did, ?string $collection = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('social_atproto_record')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		if ($collection !== null) {
			$qb->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)));
		}
		return $qb->executeQuery()->fetchAllAssociative();
	}
	public function transaction(callable $write): mixed {
		$this->db->beginTransaction();
		try {
			$result = $write();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
	public function commit(string $did, string $signingKey, ?string $prevCommitCid = null): Commit {
		return $this->transaction(function () use ($did, $signingKey) {
			$head = $this->getHead($did);
			if ($head === null) {
				throw new \InvalidArgumentException('Repository not found');
			}
			$old = [];
			$previous = null;
			if (!empty($head['commit_cid'])) {
				$previous = DagCbor::decode($this->getBlock($did, $head['commit_cid']) ?? throw new \RuntimeException('Missing commit'));
				$this->walk($did, $previous['data']->value, $old);
			}
			$records = [];
			$blocks = [];
			foreach ($this->rows($did) as $row) {
				$path = $row['collection'] . '/' . $row['rkey'];
				$records[$path] = ['cid' => $row['cid']];
				$blocks[$row['cid']] = self::bytes($row['bytes']);
			}
			$tree = MerkleSearchTree::exportCar($records);
			$blocks += $tree['blocks'];
			$rev = Tid::next($head['rev']);
			$commit = Commit::create($did, $tree['root'], $rev, null, $signingKey, $this->keyManager);
			$commitCid = $commit->getCid();
			$blocks = [$commitCid => $commit->toCbor()] + $blocks;
			foreach ($blocks as $cid => $bytes) {
				if ($this->getBlock($did, $cid) === null) {
					$this->insert('social_atproto_block', ['did' => $did, 'cid' => $cid, 'bytes' => $bytes, 'kind' => $cid === $commitCid ? 'commit' : (isset($tree['blocks'][$cid]) ? 'mst' : 'record')], ['bytes']);
				}
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('social_atproto_repo')->set('commit_cid', $qb->createNamedParameter($commitCid))->set('rev', $qb->createNamedParameter($rev))
				->set('record_count', $qb->createNamedParameter(count($records), IQueryBuilder::PARAM_INT))->set('updated', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
				->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
			$qb->andWhere($head['rev'] === null ? $qb->expr()->isNull('rev') : $qb->expr()->eq('rev', $qb->createNamedParameter($head['rev'])));
			if ($qb->executeStatement() !== 1) {
				throw new \RuntimeException('Concurrent repository write; retry required');
			}
			$diff = MerkleSearchTree::diff('', $tree['root'], $old, $records);
			$ops = array_merge($diff['added'], $diff['changed'], $diff['removed']);
			foreach ($ops as &$op) {
				$op['cid'] = isset($op['cid']) ? new Cid($op['cid']) : null;
				if (isset($op['prev'])) {
					$op['prev'] = new Cid($op['prev']);
				}
			} unset($op);
			// Inductive firehose: current MST proofs plus only the changed records.
			$eventBlocks = [$commitCid => $commit->toCbor()] + $tree['blocks'];
			foreach ($ops as $op) {
				if ($op['cid'] !== null) {
					$eventBlocks[$op['cid']->value] = $blocks[$op['cid']->value];
				}
			}
			$car = Car::encode($commitCid, $eventBlocks);
			$tooBig = strlen($car) > 512 * 1024 || count($ops) > 200;
			$body = ['repo' => $did, 'commit' => new Cid($commitCid), 'rev' => $rev, 'since' => $head['rev'],
				'prevData' => $previous === null ? new Cid(MerkleSearchTree::exportCar([])['root']) : $previous['data'],
				'rebase' => false, 'tooBig' => $tooBig, 'blocks' => new Bytes($tooBig ? '' : $car), 'ops' => $tooBig ? [] : $ops, 'blobs' => [], 'time' => gmdate('Y-m-d\TH:i:s\Z')];
			$kind = '#commit';
			if ($tooBig) {
				$kind = '#sync';
				$body = ['did' => $did, 'rev' => $rev, 'blocks' => new Bytes(Car::encode($commitCid, [$commitCid => $commit->toCbor()])), 'time' => gmdate('Y-m-d\TH:i:s\Z')];
			}
			$this->insert('social_atproto_event', ['did' => $did, 'kind' => $kind, 'bytes' => DagCbor::encode($body), 'time' => gmdate('Y-m-d H:i:s')], ['bytes']);
			return $commit;
		});
	}
	private function walk(string $did, string $cid, array &$records, int $depth = 0): void {
		if ($depth > 128) {
			throw new \RuntimeException('Invalid MST depth');
		}
		$node = DagCbor::decode($this->getBlock($did, $cid) ?? throw new \RuntimeException('Missing MST block'));
		$key = '';
		if ($node['l'] !== null) {
			$this->walk($did, $node['l']->value, $records, $depth + 1);
		}
		foreach ($node['e'] as $entry) {
			$key = substr($key, 0, $entry['p']) . $entry['k']->value;
			$records[$key] = ['cid' => $entry['v']->value];
			if ($entry['t'] !== null) {
				$this->walk($did, $entry['t']->value, $records, $depth + 1);
			}
		}
	}
	/** Validate the published head, signature and deterministic MST against stored records. */
	public function verify(string $did, string $publicKey): void {
		$head = $this->getHead($did);
		if (empty($head['commit_cid'])) {
			throw new \RuntimeException('Repository has no signed head');
		}
		$bytes = $this->getBlock($did, $head['commit_cid']) ?? throw new \RuntimeException('Missing commit');
		if (Cid::hash($bytes) !== $head['commit_cid']) {
			throw new \RuntimeException('Commit CID mismatch');
		}
		$commit = DagCbor::decode($bytes);
		if ($commit['did'] !== $did || $commit['rev'] !== $head['rev'] || $commit['version'] !== 3 || $commit['prev'] !== null) {
			throw new \RuntimeException('Invalid repository head');
		}
		$signature = $commit['sig']->value;
		unset($commit['sig']);
		if (!$this->keyManager->verify(DagCbor::encode($commit), $signature, $publicKey)) {
			throw new \RuntimeException('Invalid commit signature');
		}
		$records = [];
		$published = [];
		foreach ($this->rows($did) as $row) {
			if (Cid::hash(self::bytes($row['bytes'])) !== $row['cid']) {
				throw new \RuntimeException('Record CID mismatch');
			}
			$records[$row['collection'] . '/' . $row['rkey']] = ['cid' => $row['cid']];
		}
		$tree = MerkleSearchTree::exportCar($records);
		if ($tree['root'] !== $commit['data']->value) {
			throw new \RuntimeException('Stored records differ from committed MST');
		}
		foreach ($tree['blocks'] as $cid => $expected) {
			if ($this->getBlock($did, $cid) !== $expected) {
				throw new \RuntimeException('Missing or corrupt MST block');
			}
		}
		$this->walk($did, $tree['root'], $published);
		foreach ($published as $entry) {
			$block = $this->getBlock($did, $entry['cid']);
			if ($block === null || Cid::hash($block) !== $entry['cid']) {
				throw new \RuntimeException('Missing or corrupt record block');
			}
		}
	}

	public function getHead(string $did): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('social_atproto_repo')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	public function exportCar(string $did): string {
		$head = $this->getHead($did);
		if (empty($head['commit_cid'])) {
			throw new \InvalidArgumentException('Repository has no commit');
		}
		$root = $head['commit_cid'];
		$commit = $this->getBlock($did, $root) ?? throw new \RuntimeException('Missing commit');
		$blocks = [$root => $commit];
		$pending = [DagCbor::decode($commit)['data']->value];
		while ($pending !== []) {
			$cid = array_pop($pending);
			if (isset($blocks[$cid])) {
				continue;
			}
			$bytes = $this->getBlock($did, $cid) ?? throw new \RuntimeException('Missing repo block');
			$blocks[$cid] = $bytes;
			$node = DagCbor::decode($bytes);
			if (!isset($node['e'])) {
				continue;
			}
			if ($node['l'] !== null) {
				$pending[] = $node['l']->value;
			}
			foreach ($node['e'] as $entry) {
				$pending[] = $entry['v']->value;
				if ($entry['t'] !== null) {
					$pending[] = $entry['t']->value;
				}
			}
		}
		return Car::encode($root, $blocks);
	}
	public function getBlock(string $did, string $cid): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('bytes')->from('social_atproto_block')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))->andWhere($qb->expr()->eq('cid', $qb->createNamedParameter($cid)));
		$result = $qb->executeQuery()->fetchOne();
		return $result === false ? null : self::bytes($result);
	}
	public static function bytes(mixed $value): string {
		return is_resource($value) ? stream_get_contents($value) : (string)$value;
	}
	private function insert(string $table, array $values, array $binary = []): void {
		$qb = $this->db->getQueryBuilder();
		$params = [];
		foreach ($values as $key => $value) {
			$params[$key] = $qb->createNamedParameter($value, in_array($key, $binary, true) ? IQueryBuilder::PARAM_LOB : ($value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR));
		}
		$qb->insert($table)->values($params)->executeStatement();
	}
}
