<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use Generator;
use OCA\Social\Atproto\Model\RepoHead;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\Details;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

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
	/** rows read at once when a repository is walked whole */
	private const PAGE = 500;

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
	 * The head, read with a row lock that lasts until the transaction ends:
	 * a second commit of the same repository waits here and then reads the
	 * head this one leaves. There is no row to lock before the first commit;
	 * two first commits race to the unique index on `did`, and one fails.
	 * SQLite has no row locks and refuses the clause; its one writer at a
	 * time serialises the commits by itself.
	 */
	public function lockHead(string $did): ?RepoHead {
		$qb = $this->getQueryBuilder();
		$qb->select('did', 'commit_cid', 'rev', 'record_count', 'blob_bytes', 'updated')
			->from(self::TABLE_ATPROTO_REPO)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		if ($this->dbConnection->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
			$qb->forUpdate();
		}
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

	/** The newest public post on an identity that still belongs to this PDS. */
	public function latestActivePost(): ?StoredRecord {
		$qb = $this->getQueryBuilder();
		$qb->select('r.did', 'r.collection', 'r.rkey', 'r.cid', 'r.bytes', 'r.local_id', 'r.creation')
			->from(self::TABLE_ATPROTO_RECORD, 'r')
			->innerJoin('r', self::TABLE_ATPROTO_IDENTITY, 'i', $qb->expr()->eq('i.did', 'r.did'))
			->where($qb->expr()->eq('r.collection', $qb->createNamedParameter('app.bsky.feed.post')))
			->andWhere($qb->expr()->eq('i.state', $qb->createNamedParameter('active')))
			->orderBy('r.creation', 'desc')
			->addOrderBy('r.id', 'desc')
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? null : $this->record($row);
	}

	/**
	 * Moves the head, creating the row the first time. Last thing a commit
	 * does: until it runs the previous commit is the repository.
	 *
	 * The row made by the first commit starts with the bytes of the blobs
	 * registered before it: `addBlobBytes()` has no row to add them to until
	 * then.
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
			->setValue('blob_bytes', $qb->createNamedParameter($this->registeredBlobBytes($did), IQueryBuilder::PARAM_INT))
			->setValue('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	/** The bytes of every blob registered for the account. */
	private function registeredBlobBytes(string $did): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('size'), 'bytes')
			->from(self::TABLE_ATPROTO_BLOB)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['bytes'] ?? 0);
	}

	/**
	 * Counts newly registered blob bytes against the repository. Before the
	 * first commit there is no row and nothing changes; that commit counts
	 * every blob registered by then (`setHead()`).
	 */
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
	 * A collection's records written here since a moment, across every
	 * repository, newest first and below a record id when one is given:
	 * what the reconcile pass checks against the posts, a page at a time.
	 * Records a moving repository brought stand for no object here and are
	 * left out. The window is cut on the id, as countRecordsWrittenSince()
	 * explains.
	 *
	 * @return array<int, StoredRecord> by record id
	 */
	public function getRecordsSince(string $collection, int $since, int $limit, int $beforeId = 0): array {
		$from = $this->firstRecordIdSince($since);
		if ($from === 0) {
			return [];
		}
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'did', 'collection', 'rkey', 'cid', 'bytes', 'local_id', 'creation')
			->from(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->gte('id', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)))
			->andWhere($qb->expr()->neq('local_id_prim', $qb->createNamedParameter('')))
			->orderBy('id', 'desc')
			->setMaxResults(max(1, $limit));
		if ($beforeId > 0) {
			$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
		}
		$records = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$records[(int)$row['id']] = $this->record($row);
		}
		$result->closeCursor();

		return $records;
	}

	/**
	 * How much of what is held here of a post Bluesky counts too, by
	 * counter: what came from Bluesky — a like or repost by a bsky.app
	 * account, a reply or quote that is a bsky.app post — and what a local
	 * account wrote there as well, a like, boost, reply or quote that has a
	 * record in its repository here. The rest — from the Fediverse, or by a
	 * local account that is not on Bluesky — the AppView never saw.
	 *
	 * @return array{likes: int, boosts: int, replies: int, quotes: int}
	 */
	public function countHeldOnBluesky(string $postId): array {
		$held = [Details::LIKES => 0, Details::BOOSTS => 0, Details::REPLIES => 0, Details::QUOTES => 0];
		if (!str_starts_with($postId, 'http')) {
			return $held;
		}

		$prim = md5($postId);
		$fromBluesky = $this->dbConnection->escapeLikeParameter(BlueskyIds::actorId('')) . '%';
		$counted = [
			Details::LIKES => [self::TABLE_ACTIONS, 'object_id_prim', 'actor_id', Like::TYPE, RecordMapper::LIKE],
			Details::BOOSTS => [self::TABLE_ACTIONS, 'object_id_prim', 'actor_id', Announce::TYPE, RecordMapper::REPOST],
			Details::REPLIES => [self::TABLE_STREAM, 'in_reply_to_prim', 'id', '', RecordMapper::POST],
			Details::QUOTES => [self::TABLE_STREAM, 'quote_prim', 'id', '', RecordMapper::POST],
		];
		$selects = [];
		$params = [];
		foreach ($counted as $counter => [$table, $on, $origin, $type, $collection]) {
			$sql = 'SELECT COUNT(*) FROM `*PREFIX*' . $table . '` `h` WHERE `h`.`' . $on . '` = ?';
			$params[] = $prim;
			if ($type !== '') {
				$sql .= ' AND `h`.`type` = ?';
				$params[] = $type;
			}
			$sql .= ' AND (`h`.`' . $origin . '` LIKE ? OR EXISTS (SELECT 1 FROM `*PREFIX*' . self::TABLE_ATPROTO_RECORD . '` `r`'
				. ' WHERE `r`.`local_id_prim` = `h`.`id_prim` AND `r`.`collection` = ?))';
			$params[] = $fromBluesky;
			$params[] = $collection;
			$selects[] = '(' . $sql . ') AS `' . $counter . '`';
		}

		// `*PREFIX*` and the backticks are what the connection rewrites for
		// the platform
		$result = $this->dbConnection->executeQuery('SELECT ' . implode(', ', $selects), $params);
		$row = $result->fetch();
		$result->closeCursor();
		foreach (array_keys($held) as $counter) {
			$held[$counter] = max(0, (int)(is_array($row) ? ($row[$counter] ?? 0) : 0));
		}

		return $held;
	}

	/**
	 * Every record's bytes, for `getRepo`; read a page at a time and handed
	 * out one by one, so a large repository is never whole in memory — not
	 * even in the driver's result buffer.
	 *
	 * @return Generator<int, array{0: Cid, 1: string, 2: string}> the CID, the bytes and the record's path
	 */
	public function recordBytes(string $did): Generator {
		$after = 0;
		do {
			$qb = $this->getQueryBuilder();
			$qb->select('id', 'cid', 'bytes', 'collection', 'rkey')
				->from(self::TABLE_ATPROTO_RECORD)
				->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'asc')
				->setMaxResults(self::PAGE);
			$cursor = $qb->executeQuery();
			$rows = 0;
			while ($row = $cursor->fetch()) {
				$rows++;
				$after = (int)$row['id'];
				yield [Cid::parse((string)$row['cid']), self::bytes($row['bytes']), $row['collection'] . '/' . $row['rkey']];
			}
			$cursor->closeCursor();
		} while ($rows === self::PAGE);
	}

	/**
	 * @param callable(Cid, string, string): void $each the CID, the bytes and the record's path
	 */
	public function eachRecordBytes(string $did, callable $each): void {
		foreach ($this->recordBytes($did) as [$cid, $bytes, $path]) {
			$each($cid, $bytes, $path);
		}
	}

	/**
	 * How many records a repository holds, of one collection or of all.
	 */
	public function countRecords(string $did, string $collection = ''): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))
			->from(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		if ($collection !== '') {
			$qb->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)));
		}
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['n'] ?? 0);
	}

	/**
	 * How many of these Social objects a repository holds a record of in one
	 * collection: of a list of posts, the ones that went out on Bluesky.
	 *
	 * @param list<string> $localIds
	 */
	public function countRecordsOf(string $did, string $collection, array $localIds): int {
		$prims = array_values(array_unique(array_map('md5', array_filter($localIds, static fn (string $id): bool => $id !== ''))));
		$count = 0;
		foreach (array_chunk($prims, 500) as $chunk) {
			$qb = $this->getQueryBuilder();
			$qb->select($qb->func()->count('id', 'n'))
				->from(self::TABLE_ATPROTO_RECORD)
				->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
				->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)))
				->andWhere($qb->expr()->in('local_id_prim', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$cursor = $qb->executeQuery();
			$row = $cursor->fetch();
			$cursor->closeCursor();
			$count += (int)($row['n'] ?? 0);
		}

		return $count;
	}

	/**
	 * The records and blob bytes of every repository together, summed from
	 * the heads: one row per local account, not one per record.
	 *
	 * @return array{records: int, blob_bytes: int}
	 */
	public function sumHeads(): array {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('record_count'), 'records')
			->selectAlias($qb->func()->sum('blob_bytes'), 'blob_bytes')
			->from(self::TABLE_ATPROTO_REPO);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return ['records' => (int)($row['records'] ?? 0), 'blob_bytes' => (int)($row['blob_bytes'] ?? 0)];
	}

	/**
	 * How many records of a collection that were written here, rather than
	 * brought by a moving repository, are younger than `$since`.
	 *
	 * The table has no index on `creation`, so the count is not filtered by
	 * it: every record is inserted with the time of its insert, which makes
	 * the ids rise with `creation`, and the first id of the window is found
	 * by a binary search over the primary key. The count then reads only the
	 * rows of the window, by primary key.
	 */
	public function countRecordsWrittenSince(string $collection, int $since): int {
		$from = $this->firstRecordIdSince($since);
		if ($from === 0) {
			return 0;
		}
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))
			->from(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->gte('id', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)))
			->andWhere($qb->expr()->neq('local_id_prim', $qb->createNamedParameter('')));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['n'] ?? 0);
	}

	/**
	 * The lowest record id created at or after `$since`, 0 when there is
	 * none; a few dozen primary-key lookups however large the table is.
	 */
	public function firstRecordIdSince(int $since): int {
		$low = 1;
		$high = $this->lastRecordId() + 1;
		while ($low < $high) {
			$middle = intdiv($low + $high, 2);
			$found = $this->recordAtOrAfter($middle);
			if ($found === null || $found['creation'] >= $since) {
				$high = $middle;
			} else {
				$low = $found['id'] + 1;
			}
		}
		$found = $this->recordAtOrAfter($low);

		return ($found !== null && $found['creation'] >= $since) ? $found['id'] : 0;
	}

	/** the highest record id, 0 for an empty table */
	public function lastRecordId(): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from(self::TABLE_ATPROTO_RECORD);
		$cursor = $qb->executeQuery();
		$id = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $id;
	}

	/**
	 * The first record from an id on, by primary key.
	 *
	 * @return array{id: int, creation: int}|null
	 */
	public function recordAtOrAfter(int $id): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'creation')
			->from(self::TABLE_ATPROTO_RECORD)
			->where($qb->expr()->gte('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'asc')
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? null : ['id' => (int)$row['id'], 'creation' => AtprotoIdentityRequest::time($row['creation'])];
	}

	/**
	 * Names the Social object a record stands for, as a record that moved
	 * here learns it when its follow is made here.
	 */
	public function setLocalId(string $did, string $collection, string $rkey, string $localId): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_RECORD)
			->set('local_id', $qb->createNamedParameter($localId))
			->set('local_id_prim', $qb->createNamedParameter($localId === '' ? '' : md5($localId)))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection)))
			->andWhere($qb->expr()->eq('rkey', $qb->createNamedParameter($rkey)));
		$qb->executeStatement();
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
	 * The blocks among $cids that the repository has, tree nodes and
	 * commits here and records in their table: what `getBlocks` serves.
	 *
	 * @param string[] $cids
	 * @return array<string, string> CID string => bytes, for the ones found
	 */
	public function getBlocks(string $did, array $cids): array {
		$found = [];
		foreach (array_chunk($cids, 500) as $chunk) {
			foreach ([self::TABLE_ATPROTO_BLOCK, self::TABLE_ATPROTO_RECORD] as $table) {
				$qb = $this->getQueryBuilder();
				$qb->select('cid', 'bytes')
					->from($table)
					->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
					->andWhere($qb->expr()->in('cid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
				$cursor = $qb->executeQuery();
				while ($row = $cursor->fetch()) {
					$found[(string)$row['cid']] = self::bytes($row['bytes']);
				}
				$cursor->closeCursor();
			}
		}

		return $found;
	}

	/**
	 * The tree nodes and commit of a repository, a page at a time, as
	 * `recordBytes` reads the records.
	 *
	 * @return Generator<int, array{0: Cid, 1: string, 2: string}> CID, kind, bytes
	 */
	public function blocks(string $did, string $kind = ''): Generator {
		$after = 0;
		do {
			$qb = $this->getQueryBuilder();
			$qb->select('id', 'cid', 'kind', 'bytes')
				->from(self::TABLE_ATPROTO_BLOCK)
				->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'asc')
				->setMaxResults(self::PAGE);
			if ($kind !== '') {
				$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
			}
			$cursor = $qb->executeQuery();
			$rows = 0;
			while ($row = $cursor->fetch()) {
				$rows++;
				$after = (int)$row['id'];
				yield [Cid::parse((string)$row['cid']), (string)$row['kind'], self::bytes($row['bytes'])];
			}
			$cursor->closeCursor();
		} while ($rows === self::PAGE);
	}

	/**
	 * @param callable(Cid, string, string): void $each CID, kind, bytes
	 */
	public function eachBlock(string $did, callable $each, string $kind = ''): void {
		foreach ($this->blocks($did, $kind) as [$cid, $blockKind, $bytes]) {
			$each($cid, $blockKind, $bytes);
		}
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
