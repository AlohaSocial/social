<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Atproto\Model\RepoHead;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * A repository as stored: its head, its records and its blocks.
 *
 * Records keep their DAG-CBOR bytes here and nowhere else; the block table
 * holds the tree nodes and the commits. `getRepo` and the firehose read
 * both. Every write of a commit happens inside one transaction that
 * RepositoryService opens, which is why nothing here opens its own.
 */
class AtprotoRepoRequest extends CoreRequestBuilder {
	public const BLOCK_MST = 'mst';
	public const BLOCK_COMMIT = 'commit';

	public function getHead(string $did): ?RepoHead {
		$qb = $this->getQueryBuilder();
		$qb->select('did', 'commit_cid', 'rev', 'record_count', 'blob_bytes', 'updated')
			->from(self::TABLE_ATPROTO_REPO)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? null : $this->head($row);
	}

	/**
	 * @return RepoHead[] every repository, oldest first
	 */
	public function getHeads(int $limit = 500, int $offset = 0): array {
		$qb = $this->getQueryBuilder();
		$qb->select('did', 'commit_cid', 'rev', 'record_count', 'blob_bytes', 'updated')
			->from(self::TABLE_ATPROTO_REPO)
			->orderBy('id', 'asc')
			->setMaxResults($limit)
			->setFirstResult($offset);
		$heads = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$heads[] = $this->head($row);
		}
		$cursor->closeCursor();

		return $heads;
	}

	public function countHeads(): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from(self::TABLE_ATPROTO_REPO);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['n'] ?? 0);
	}

	/**
	 * Moves the head, creating the row the first time. Last thing a commit
	 * does: until it runs the previous commit is the repository.
	 */
	public function setHead(string $did, Cid $commitCid, string $rev, int $recordCount): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_REPO)
			->set('commit_cid', $qb->createNamedParameter($commitCid->toString()))
			->set('rev', $qb->createNamedParameter($rev))
			->set('record_count', $qb->createNamedParameter($recordCount, IQueryBuilder::PARAM_INT))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		if ($qb->executeStatement() > 0) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_REPO)
			->setValue('did', $qb->createNamedParameter($did))
			->setValue('commit_cid', $qb->createNamedParameter($commitCid->toString()))
			->setValue('rev', $qb->createNamedParameter($rev))
			->setValue('record_count', $qb->createNamedParameter($recordCount, IQueryBuilder::PARAM_INT))
			->setValue('blob_bytes', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->setValue('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	public function addBlobBytes(string $did, int $bytes): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_REPO)
			->set('blob_bytes', $qb->func()->add('blob_bytes', $qb->createNamedParameter($bytes, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	/**
	 * Every record's path and CID: what the tree is built from.
	 *
	 * @return array<string, Cid> path => CID
	 */
	public function getLeaves(string $did): array {
		$qb = $this->getQueryBuilder();
		$qb->select('collection', 'rkey', 'cid')
			->from(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$leaves = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$leaves[$row['collection'] . '/' . $row['rkey']] = Cid::parse((string)$row['cid']);
		}
		$cursor->closeCursor();

		return $leaves;
	}

	public function getRecord(string $did, string $collection, string $rkey): ?StoredRecord {
		$qb = $this->getQueryBuilder();
		$qb->select('did', 'collection', 'rkey', 'cid', 'bytes', 'local_id', 'creation')
			->from(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->eq('path_prim', $qb->createNamedParameter(self::pathPrim($did, $collection, $rkey))));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? null : $this->record($row);
	}

	/**
	 * The records one Social object produced, in any repository: a post's
	 * `app.bsky.feed.post`, or the likes several actors made of it.
	 *
	 * @return StoredRecord[]
	 */
	public function getRecordsByLocalId(string $localId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('did', 'collection', 'rkey', 'cid', 'bytes', 'local_id', 'creation')
			->from(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->eq('local_id_prim', $qb->createNamedParameter(md5($localId))));
		$records = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$records[] = $this->record($row);
		}
		$cursor->closeCursor();

		return $records;
	}

	/**
	 * A collection's records, by rkey; the page the XRPC listRecords answers.
	 *
	 * @param string $cursor the rkey to continue after, '' for the start
	 * @return StoredRecord[]
	 */
	public function listRecords(string $did, string $collection, int $limit, string $cursor = '', bool $reverse = false): array {
		$qb = $this->getQueryBuilder();
		$qb->select('did', 'collection', 'rkey', 'cid', 'bytes', 'local_id', 'creation')
			->from(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)))
			->orderBy('rkey', $reverse ? 'asc' : 'desc')
			->setMaxResults($limit);
		if ($cursor !== '') {
			$qb->andWhere($reverse
				? $qb->expr()->gt('rkey', $qb->createNamedParameter($cursor))
				: $qb->expr()->lt('rkey', $qb->createNamedParameter($cursor)));
		}
		$records = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$records[] = $this->record($row);
		}
		$result->closeCursor();

		return $records;
	}

	/**
	 * Every record's bytes, for `getRepo`; streamed through the callback so
	 * a large repository is never whole in memory.
	 *
	 * @param callable(Cid, string): void $each
	 */
	public function eachRecordBytes(string $did, callable $each): void {
		$qb = $this->getQueryBuilder();
		$qb->select('cid', 'bytes')
			->from(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$each(Cid::parse((string)$row['cid']), self::bytes($row['bytes']));
		}
		$cursor->closeCursor();
	}

	public function countRecords(string $did): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))
			->from(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['n'] ?? 0);
	}

	public function putRecord(string $did, string $collection, string $rkey, Cid $cid, string $bytes, string $localId): void {
		$this->deleteRecord($did, $collection, $rkey);
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_RECORD)
			->setValue('did', $qb->createNamedParameter($did))
			->setValue('collection', $qb->createNamedParameter($collection))
			->setValue('rkey', $qb->createNamedParameter($rkey))
			->setValue('path_prim', $qb->createNamedParameter(self::pathPrim($did, $collection, $rkey)))
			->setValue('cid', $qb->createNamedParameter($cid->toString()))
			->setValue('bytes', $qb->createNamedParameter($bytes, IQueryBuilder::PARAM_LOB))
			->setValue('local_id', $qb->createNamedParameter($localId))
			->setValue('local_id_prim', $qb->createNamedParameter($localId === '' ? '' : md5($localId)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	public function deleteRecord(string $did, string $collection, string $rkey): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->eq('path_prim', $qb->createNamedParameter(self::pathPrim($did, $collection, $rkey))));
		$qb->executeStatement();
	}

	/**
	 * @param array<string, string> $blocks CID string => bytes
	 */
	public function putBlocks(string $did, string $kind, array $blocks): void {
		foreach ($blocks as $cid => $bytes) {
			$qb = $this->getQueryBuilder();
			$qb->insert(self::TABLE_ATPROTO_BLOCK)
				->setValue('did', $qb->createNamedParameter($did))
				->setValue('cid', $qb->createNamedParameter((string)$cid))
				->setValue('kind', $qb->createNamedParameter($kind))
				->setValue('bytes', $qb->createNamedParameter($bytes, IQueryBuilder::PARAM_LOB));
			$qb->executeStatement();
		}
	}

	/**
	 * @param string[] $cids
	 */
	public function deleteBlocks(string $did, array $cids): void {
		if ($cids === []) {
			return;
		}
		foreach (array_chunk($cids, 500) as $chunk) {
			$qb = $this->getQueryBuilder();
			$qb->delete(self::TABLE_ATPROTO_BLOCK)
				->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
				->andWhere($qb->expr()->in('cid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function getBlock(string $did, string $cid): ?string {
		$qb = $this->getQueryBuilder();
		$qb->select('bytes')
			->from(self::TABLE_ATPROTO_BLOCK)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('cid', $qb->createNamedParameter($cid)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? null : self::bytes($row['bytes']);
	}

	/**
	 * The tree nodes and commit of a repository, streamed.
	 *
	 * @param callable(Cid, string, string): void $each CID, kind, bytes
	 */
	public function eachBlock(string $did, callable $each, string $kind = ''): void {
		$qb = $this->getQueryBuilder();
		$qb->select('cid', 'kind', 'bytes')
			->from(self::TABLE_ATPROTO_BLOCK)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		if ($kind !== '') {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$each(Cid::parse((string)$row['cid']), (string)$row['kind'], self::bytes($row['bytes']));
		}
		$cursor->closeCursor();
	}

	/**
	 * The CIDs of a repository's blocks: what a rebuild must drop that it
	 * did not write again.
	 *
	 * @return string[]
	 */
	public function getBlockCids(string $did, string $kind = ''): array {
		$qb = $this->getQueryBuilder();
		$qb->select('cid')
			->from(self::TABLE_ATPROTO_BLOCK)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		if ($kind !== '') {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}
		$cids = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$cids[] = (string)$row['cid'];
		}
		$cursor->closeCursor();

		return $cids;
	}

	/** Everything of one repository: records, blocks and the head. */
	public function deleteRepository(string $did): void {
		foreach ([self::TABLE_ATPROTO_RECORD, self::TABLE_ATPROTO_BLOCK, self::TABLE_ATPROTO_REPO] as $table) {
			$qb = $this->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
			$qb->executeStatement();
		}
	}

	public static function pathPrim(string $did, string $collection, string $rkey): string {
		return md5($did . '/' . $collection . '/' . $rkey);
	}

	/** a BLOB column as a string: PostgreSQL hands it back as a stream */
	public static function bytes(mixed $value): string {
		if (is_resource($value)) {
			return (string)stream_get_contents($value);
		}

		return (string)$value;
	}

	private function head(array $row): RepoHead {
		return new RepoHead(
			(string)$row['did'],
			(string)$row['commit_cid'],
			(string)$row['rev'],
			(int)$row['record_count'],
			(int)$row['blob_bytes'],
			AtprotoIdentityRequest::time($row['updated']),
		);
	}

	private function record(array $row): StoredRecord {
		return new StoredRecord(
			(string)$row['did'],
			(string)$row['collection'],
			(string)$row['rkey'],
			Cid::parse((string)$row['cid']),
			self::bytes($row['bytes']),
			(string)$row['local_id'],
			AtprotoIdentityRequest::time($row['creation']),
		);
	}
}
