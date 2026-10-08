<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Repository;

use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Lexicon\LexiconException;
use OCA\Social\Atproto\Model\RepoHead;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCP\IDBConnection;
use Throwable;

/**
 * A repository's writes and reads.
 *
 * A write is one commit: the records change, the tree is rebuilt from
 * them, the commit is signed, the changed blocks are stored and the old
 * ones dropped, the head moves, and one firehose frame is appended — all in
 * one database transaction, so a failure half-way leaves the previous
 * commit as the repository. The lexicon is checked before anything is
 * signed; nothing that does not fit reaches a relay.
 */
class RepositoryService {
	/** what one commit may change: the firehose caps `ops` at 200 */
	public const MAX_WRITES = 200;

	public function __construct(
		private AtprotoRepoRequest $repoRequest,
		private EventService $events,
		private Lexicon $lexicon,
		private IDBConnection $connection,
	) {
	}

	/**
	 * Applies the writes as one commit.
	 *
	 * @param RepoWrite[] $writes
	 * @return CommitResult what was written
	 * @throws AtprotoException when a record does not fit its lexicon or the write fails
	 */
	public function write(string $did, PrivateKey $signingKey, array $writes): CommitResult {
		if ($writes === [] || count($writes) > self::MAX_WRITES) {
			throw new AtprotoException('A commit changes between one and ' . self::MAX_WRITES . ' records');
		}
		foreach ($writes as $write) {
			if ($write->record !== null) {
				try {
					$this->lexicon->validateRecord($write->record);
				} catch (LexiconException $e) {
					throw new AtprotoException('Record ' . $write->path() . ' does not fit its lexicon: ' . $e->getMessage(), 0, $e);
				}
			}
		}

		$this->connection->beginTransaction();
		try {
			$result = $this->commit($did, $signingKey, $writes);
			$this->connection->commit();
		} catch (Throwable $e) {
			$this->connection->rollBack();
			throw $e instanceof AtprotoException ? $e : new AtprotoException('Repository write failed: ' . $e->getMessage(), 0, $e);
		}

		return $result;
	}

	/**
	 * @param RepoWrite[] $writes
	 */
	private function commit(string $did, PrivateKey $signingKey, array $writes): CommitResult {
		$head = $this->repoRequest->getHead($did);
		$leaves = $this->repoRequest->getLeaves($did);
		$oldMstCids = array_fill_keys($this->repoRequest->getBlockCids($did, AtprotoRepoRequest::BLOCK_MST), true);
		$oldCommitCids = $this->repoRequest->getBlockCids($did, AtprotoRepoRequest::BLOCK_COMMIT);
		$prevData = $head === null ? null : $this->rootOf($did, $head);

		$ops = [];
		$recordBlocks = [];
		$written = [];
		foreach ($writes as $write) {
			$path = $write->path();
			$previous = $leaves[$path] ?? null;
			if ($write->action === RepoWrite::DELETE) {
				if ($previous === null) {
					throw new AtprotoException('No record at ' . $path . ' to delete');
				}
				unset($leaves[$path]);
				$this->repoRequest->deleteRecord($did, $write->collection, $write->rkey);
				$ops[] = ['action' => RepoWrite::DELETE, 'path' => $path, 'cid' => null, 'prev' => $previous];
				continue;
			}
			if ($write->action === RepoWrite::CREATE && $previous !== null) {
				throw new AtprotoException('A record already exists at ' . $path);
			}
			if ($write->action === RepoWrite::UPDATE && $previous === null) {
				throw new AtprotoException('No record at ' . $path . ' to update');
			}
			$bytes = DagCbor::encode($write->record);
			$cid = Cid::forDagCbor($bytes);
			$leaves[$path] = $cid;
			$this->repoRequest->putRecord($did, $write->collection, $write->rkey, $cid, $bytes, $write->localId);
			$recordBlocks[$cid->toString()] = $bytes;
			$ops[] = ['action' => $write->action, 'path' => $path, 'cid' => $cid, 'prev' => $previous];
			$written[$path] = $cid;
		}

		$tree = (new Mst($leaves))->build();
		$rev = Tid::after($head?->rev ?? '');
		$commit = Commit::sign($did, $tree->root, $rev, $signingKey);
		$commitBytes = $commit->toBytes();
		$commitCid = $commit->cid();

		$newMst = array_diff_key($tree->blocks, $oldMstCids);
		$this->repoRequest->putBlocks($did, AtprotoRepoRequest::BLOCK_MST, $newMst);
		$this->repoRequest->putBlocks($did, AtprotoRepoRequest::BLOCK_COMMIT, [$commitCid->toString() => $commitBytes]);
		$this->repoRequest->deleteBlocks($did, [...array_keys(array_diff_key($oldMstCids, $tree->blocks)), ...$oldCommitCids]);
		$this->repoRequest->setHead($did, $commitCid, $rev, count($leaves));

		$seq = $this->events->commit(
			$did, $commitCid, $rev, $head?->rev, $prevData, $ops,
			[$commitCid->toString() => $commitBytes] + $newMst + $recordBlocks,
		);

		return new CommitResult($did, $commitCid, $rev, $seq, $written);
	}

	public function getHead(string $did): ?RepoHead {
		return $this->repoRequest->getHead($did);
	}

	public function getRecord(string $did, string $collection, string $rkey): ?StoredRecord {
		return $this->repoRequest->getRecord($did, $collection, $rkey);
	}

	/**
	 * @return StoredRecord[]
	 */
	public function getRecordsByLocalId(string $localId): array {
		return $this->repoRequest->getRecordsByLocalId($localId);
	}

	/**
	 * @return StoredRecord[]
	 */
	public function listRecords(string $did, string $collection, int $limit, string $cursor = '', bool $reverse = false): array {
		return $this->repoRequest->listRecords($did, $collection, $limit, $cursor, $reverse);
	}

	/**
	 * The whole repository as a CAR: the commit first, then the tree, then
	 * the records. What a relay backfills from.
	 *
	 * @throws AtprotoException when there is no repository
	 */
	public function exportCar(string $did): string {
		$head = $this->repoRequest->getHead($did);
		if ($head === null) {
			throw new AtprotoException('No repository for ' . $did);
		}
		$blocks = [];
		$commitBytes = $this->repoRequest->getBlock($did, $head->commitCid);
		if ($commitBytes === null) {
			throw new AtprotoException('The head commit of ' . $did . ' is missing');
		}
		$blocks[$head->commitCid] = $commitBytes;
		$this->repoRequest->eachBlock($did, static function (Cid $cid, string $kind, string $bytes) use (&$blocks): void {
			if ($kind === AtprotoRepoRequest::BLOCK_MST) {
				$blocks[$cid->toString()] = $bytes;
			}
		});
		$this->repoRequest->eachRecordBytes($did, static function (Cid $cid, string $bytes) use (&$blocks): void {
			$blocks[$cid->toString()] = $bytes;
		});

		return Car::encode([Cid::parse($head->commitCid)], $blocks);
	}

	/**
	 * The head commit's bytes, for `getLatestCommit` and the `#sync` frame.
	 */
	public function headCommit(string $did): ?Commit {
		$head = $this->repoRequest->getHead($did);
		if ($head === null) {
			return null;
		}
		$bytes = $this->repoRequest->getBlock($did, $head->commitCid);

		return $bytes === null ? null : Commit::fromBytes($bytes);
	}

	/**
	 * Recomputes the tree from the records and checks it is what the head
	 * names and that the head is signed by $key.
	 *
	 * @return string[] what is wrong, empty when nothing is
	 */
	public function verify(string $did, PublicKey $key): array {
		$problems = [];
		$head = $this->repoRequest->getHead($did);
		if ($head === null) {
			return ['no repository'];
		}
		$commit = $this->headCommit($did);
		if ($commit === null) {
			return ['the head commit block is missing'];
		}
		if (!$commit->verify($key)) {
			$problems[] = 'the head commit is not signed by the signing key';
		}
		if ($commit->rev !== $head->rev) {
			$problems[] = 'the head revision differs from the commit';
		}
		$tree = (new Mst($this->repoRequest->getLeaves($did)))->build();
		if (!$tree->root->equals($commit->data)) {
			$problems[] = 'the tree rebuilt from the records has another root';
		}
		$stored = array_fill_keys($this->repoRequest->getBlockCids($did, AtprotoRepoRequest::BLOCK_MST), true);
		foreach (array_keys($tree->blocks) as $cid) {
			if (!isset($stored[$cid])) {
				$problems[] = 'tree node ' . $cid . ' is not stored';
			}
		}
		foreach (array_keys(array_diff_key($stored, $tree->blocks)) as $cid) {
			$problems[] = 'stored node ' . $cid . ' is not in the tree';
		}

		return $problems;
	}

	public function delete(string $did): void {
		$this->repoRequest->deleteRepository($did);
	}

	private function rootOf(string $did, RepoHead $head): ?Cid {
		$bytes = $this->repoRequest->getBlock($did, $head->commitCid);
		if ($bytes === null) {
			return null;
		}

		return Commit::fromBytes($bytes)->data;
	}
}
