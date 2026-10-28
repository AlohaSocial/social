<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Repository;

use Generator;
use InvalidArgumentException;
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
use OCA\Social\Atproto\Protocol\MstEditor;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCP\IDBConnection;
use Throwable;

/**
 * A repository's writes and reads.
 *
 * A write is one commit: the records change, the tree's nodes on the way
 * to them are rewritten, the commit is signed, the changed blocks are
 * stored and the old ones dropped, the head moves, and one firehose frame
 * is appended — all in
 * one database transaction, so a failure half-way leaves the previous
 * commit as the repository. The transaction starts by locking the head
 * row, so two commits of one repository run one after the other and the
 * second builds on the first. A record made once per Social object
 * (RepoWrite::createOnce()) is looked for under that lock, so of two
 * commits that both found none before, the second writes nothing. The
 * lexicon is checked before anything is signed; nothing that does not fit
 * reaches a relay.
 */
class RepositoryService {
	/** what one commit may change: the firehose caps `ops` at 200 */
	public const MAX_WRITES = 200;
	/** how much of a CAR is handed out at once when a repository is streamed */
	private const PIECE = 65536;
	private ?string $batchDid = null;
	private ?PrivateKey $batchSigningKey = null;
	private ?string $batchSwapCommit = null;
	/** @var list<RepoWrite> */
	private array $batchWrites = [];
	/** @var array<string, StoredRecord|null> */
	private array $batchRecords = [];

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
	 * @return CommitResult|null what was written, or null when a record made
	 *                           once is there already and nothing was written
	 * @throws AtprotoException when a record does not fit its lexicon or the write fails
	 */
	public function write(string $did, PrivateKey $signingKey, array $writes, ?string $swapCommit = null): ?CommitResult {
		if ($this->batchDid !== null) {
			return $this->stage($did, $signingKey, $writes);
		}
		$this->validateWrites($writes);

		$this->connection->beginTransaction();
		try {
			$result = $this->commit($did, $signingKey, $writes, $swapCommit);
			$this->connection->commit();
		} catch (Throwable $e) {
			$this->connection->rollBack();
			throw $e instanceof AtprotoException ? $e : new AtprotoException('Repository write failed: ' . $e->getMessage(), 0, $e);
		}

		return $result;
	}

	/**
	 * Begin a repository write scope for several Social actions to share one commit.
	 * @param array<string, string|null> $swapRecords record paths mapped to
	 *                                                their expected CID, or null when the path must not exist
	 */
	public function beginBatch(string $did, PrivateKey $signingKey, ?string $swapCommit = null, array $swapRecords = []): void {
		if ($this->batchDid !== null) {
			throw new AtprotoException('A repository write batch is already active');
		}
		$this->connection->beginTransaction();
		try {
			$head = $this->repoRequest->lockHead($did);
			if ($swapCommit !== null && $head?->commitCid !== $swapCommit) {
				throw new InvalidSwapException('The repository commit changed before the batch could be applied');
			}
			foreach ($swapRecords as $path => $expectedCid) {
				[$collection, $rkey] = array_pad(explode('/', $path, 2), 2, '');
				if ($collection === '' || $rkey === '') {
					throw new AtprotoException('Invalid record path in swap check');
				}
				$record = $this->repoRequest->getRecord($did, $collection, $rkey);
				$actualCid = $record?->cid->toString();
				if ($actualCid !== $expectedCid) {
					throw new InvalidSwapException('The record changed before the write could be applied');
				}
			}
		} catch (Throwable $e) {
			$this->connection->rollBack();
			throw $e;
		}
		$this->batchDid = $did;
		$this->batchSigningKey = $signingKey;
		$this->batchSwapCommit = $swapCommit;
		$this->batchWrites = [];
		$this->batchRecords = [];
	}

	public function batchActive(): bool {
		return $this->batchDid !== null;
	}

	/** Commit every write staged in the active scope as one repository commit. */
	public function commitBatch(): ?CommitResult {
		if ($this->batchDid === null || $this->batchSigningKey === null) {
			throw new AtprotoException('No repository write batch is active');
		}
		$did = $this->batchDid;
		$key = $this->batchSigningKey;
		$swapCommit = $this->batchSwapCommit;
		$writes = $this->batchWrites;
		try {
			$result = null;
			if ($writes !== []) {
				$this->validateWrites($writes);
				$result = $this->commit($did, $key, $writes, $swapCommit);
			} elseif ($swapCommit !== null && $this->repoRequest->lockHead($did)?->commitCid !== $swapCommit) {
				throw new InvalidSwapException('The repository commit changed before the batch could be applied');
			}
			$this->connection->commit();
		} catch (Throwable $e) {
			$this->connection->rollBack();
			$this->clearBatch();
			throw $e instanceof AtprotoException ? $e : new AtprotoException('Repository write batch failed: ' . $e->getMessage(), 0, $e);
		}
		$this->clearBatch();

		return $result;
	}

	/** Discard staged records and roll back their Social-side database changes. */
	public function rollbackBatch(): void {
		if ($this->batchDid === null) {
			return;
		}
		try {
			$this->connection->rollBack();
		} finally {
			$this->clearBatch();
		}
	}

	private function clearBatch(): void {
		$this->batchDid = null;
		$this->batchSigningKey = null;
		$this->batchSwapCommit = null;
		$this->batchWrites = [];
		$this->batchRecords = [];
	}

	/** @param RepoWrite[] $writes */
	private function validateWrites(array $writes): void {
		if ($writes === [] || count($writes) > self::MAX_WRITES) {
			throw new AtprotoException('A commit changes between one and ' . self::MAX_WRITES . ' records');
		}
		foreach ($writes as $write) {
			if ($write->record !== null) {
				try {
					$this->lexicon->validateRecord($write->record, $write->validate);
				} catch (LexiconException $e) {
					throw new AtprotoException('Record ' . $write->path() . ' does not fit its lexicon: ' . $e->getMessage(), 0, $e);
				}
			}
		}

	}

	/** @param RepoWrite[] $writes */
	private function stage(string $did, PrivateKey $signingKey, array $writes): ?CommitResult {
		if ($did !== $this->batchDid || $this->batchSigningKey === null || $this->batchSigningKey->curve !== $signingKey->curve || !hash_equals($this->batchSigningKey->secret(), $signingKey->secret())) {
			throw new AtprotoException('A repository write batch cannot change identity');
		}
		$this->validateWrites($writes);
		if (count($this->batchWrites) + count($writes) > self::MAX_WRITES) {
			throw new AtprotoException('A commit changes between one and ' . self::MAX_WRITES . ' records');
		}
		if ($this->batchMadeAlready($writes)) {
			return null;
		}
		$paths = [];
		foreach ($writes as $write) {
			if (isset($paths[$write->path()]) || array_key_exists(self::recordKey($did, $write->collection, $write->rkey), $this->batchRecords)) {
				throw new AtprotoException('A batch may change a record only once');
			}
			$paths[$write->path()] = true;
			$previous = $this->getRecord($did, $write->collection, $write->rkey);
			if ($write->action === RepoWrite::CREATE && $previous !== null) {
				throw new AtprotoException('A record already exists at ' . $write->path());
			}
			if ($write->action !== RepoWrite::CREATE && $previous === null) {
				throw new AtprotoException('No record at ' . $write->path());
			}
			$this->batchWrites[] = $write;
			$key = self::recordKey($did, $write->collection, $write->rkey);
			$this->batchRecords[$key] = $write->action === RepoWrite::DELETE
				? null
				: $this->stored($did, $write);
		}

		return null;
	}

	private function stored(string $did, RepoWrite $write): StoredRecord {
		$bytes = DagCbor::encode($write->record ?? []);

		return new StoredRecord($did, $write->collection, $write->rkey, Cid::forDagCbor($bytes), $bytes, $write->localId, time());
	}

	private static function recordKey(string $did, string $collection, string $rkey): string {
		return $did . "\0" . $collection . "\0" . $rkey;
	}

	/**
	 * @param RepoWrite[] $writes
	 */
	private function commit(string $did, PrivateKey $signingKey, array $writes, ?string $swapCommit): ?CommitResult {
		$head = $this->repoRequest->lockHead($did);
		if ($swapCommit !== null && $head?->commitCid !== $swapCommit) {
			throw new InvalidSwapException('The repository commit changed before the batch could be applied');
		}
		if ($this->madeAlready($writes)) {
			return null;
		}
		$oldCommitCids = $this->repoRequest->getBlockCids($did, AtprotoRepoRequest::BLOCK_COMMIT);
		$prevData = $head === null ? null : $this->rootOf($did, $head);

		$known = null;
		try {
			$tree = MstEditor::open($prevData, fn (string $cid): ?string => $this->repoRequest->getBlock($did, $cid));
			$change = $this->change($tree, $writes, $head?->recordCount ?? 0);
		} catch (InvalidArgumentException) {
			// a stored node is missing or not a node: the tree is made whole
			// from the records, and what is stored becomes that tree
			$tree = MstEditor::whole((new Mst($this->repoRequest->getLeaves($did)))->build());
			$change = $this->change($tree, $writes, $head?->recordCount ?? 0);
			$known = array_fill_keys($this->repoRequest->getBlockCids($did, AtprotoRepoRequest::BLOCK_MST), true);
		}
		$built = $tree->close();
		$known ??= $tree->loaded();
		$newMst = array_diff_key($built->blocks, $known);

		foreach ($change['records'] as [$write, $cid, $bytes]) {
			if ($write->action === RepoWrite::DELETE) {
				$this->repoRequest->deleteRecord($did, $write->collection, $write->rkey);
			} else {
				$this->repoRequest->putRecord($did, $write->collection, $write->rkey, $cid, $bytes, $write->localId);
			}
		}
		$rev = Tid::after($head?->rev ?? '');
		$commit = Commit::sign($did, $built->root, $rev, $signingKey);
		$commitBytes = $commit->toBytes();
		$commitCid = $commit->cid();

		$this->repoRequest->putBlocks($did, AtprotoRepoRequest::BLOCK_MST, $newMst);
		$this->repoRequest->putBlocks($did, AtprotoRepoRequest::BLOCK_COMMIT, [$commitCid->toString() => $commitBytes]);
		$this->repoRequest->deleteBlocks($did, [...array_keys(array_diff_key($known, $built->blocks)), ...$oldCommitCids]);
		$this->repoRequest->setHead($did, $commitCid, $rev, $change['count']);

		$seq = $this->events->commit(
			$did, $commitCid, $rev, $head?->rev, $prevData, $change['ops'],
			[$commitCid->toString() => $commitBytes] + $newMst + $change['blocks'],
		);

		return new CommitResult($did, $commitCid, $rev, $seq, $change['written']);
	}

	/**
	 * Whether a record made once is there already: another commit wrote it
	 * since the caller looked.
	 *
	 * @param RepoWrite[] $writes
	 */
	private function madeAlready(array $writes): bool {
		foreach ($writes as $write) {
			if (!$write->once) {
				continue;
			}
			foreach ($this->repoRequest->getRecordsByLocalId($write->localId) as $record) {
				if ($record->collection === $write->collection) {
					return true;
				}
			}
		}

		return false;
	}

	/** @param RepoWrite[] $writes */
	private function batchMadeAlready(array $writes): bool {
		foreach ($writes as $write) {
			if (!$write->once) {
				continue;
			}
			foreach ($this->getRecordsByLocalId($write->localId) as $record) {
				if ($record->collection === $write->collection) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The writes applied to the tree, and what they mean for the record
	 * table, the firehose frame and the result; nothing is written yet.
	 *
	 * @param RepoWrite[] $writes
	 * @return array{
	 *     ops: list<array{action: string, path: string, cid: Cid|null, prev: Cid|null}>,
	 *     records: list<array{0: RepoWrite, 1: Cid, 2: string}>,
	 *     blocks: array<string, string>,
	 *     written: array<string, Cid>,
	 *     count: int,
	 * }
	 * @throws AtprotoException when a write does not fit what is there
	 * @throws InvalidArgumentException when a stored node is missing or malformed
	 */
	private function change(MstEditor $tree, array $writes, int $count): array {
		$ops = [];
		$records = [];
		$blocks = [];
		$written = [];
		foreach ($writes as $write) {
			$path = $write->path();
			$previous = $tree->get($path);
			if ($write->action === RepoWrite::DELETE) {
				if ($previous === null) {
					throw new AtprotoException('No record at ' . $path . ' to delete');
				}
				$tree->delete($path);
				$records[] = [$write, $previous, ''];
				$ops[] = ['action' => RepoWrite::DELETE, 'path' => $path, 'cid' => null, 'prev' => $previous];
				$count--;
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
			$tree->put($path, $cid);
			$records[] = [$write, $cid, $bytes];
			$blocks[$cid->toString()] = $bytes;
			$ops[] = ['action' => $write->action, 'path' => $path, 'cid' => $cid, 'prev' => $previous];
			$written[$path] = $cid;
			if ($previous === null) {
				$count++;
			}
		}

		return ['ops' => $ops, 'records' => $records, 'blocks' => $blocks, 'written' => $written, 'count' => $count];
	}

	/**
	 * A repository that moved here (§13.1), written as one commit: the
	 * records as they were, byte for byte, so each keeps its CID and every
	 * reference to it stays good, under a commit this server signs with the
	 * account's new key. Records are not checked against a lexicon: a
	 * repository holds whatever its owner wrote, of any application. Nothing
	 * goes on the firehose; the account is announced when it is activated.
	 *
	 * @param array<string, string> $records path => record bytes (DAG-CBOR)
	 * @param string $afterRev the old repository's revision, which this one follows
	 * @throws AtprotoException when the repository is not empty or a path is not one
	 */
	public function import(string $did, PrivateKey $signingKey, array $records, string $afterRev = ''): Cid {
		if ($this->repoRequest->getHead($did) !== null) {
			throw new AtprotoException('A repository for ' . $did . ' is here already');
		}
		$this->connection->beginTransaction();
		try {
			$leaves = [];
			foreach ($records as $path => $bytes) {
				[$collection, $rkey] = explode('/', (string)$path, 2) + [1 => ''];
				if (!Mst::isValidKey((string)$path) || !Syntax::isNsid($collection)) {
					throw new AtprotoException('Not a record path: ' . $path);
				}
				$cid = Cid::forDagCbor($bytes);
				$this->repoRequest->putRecord($did, $collection, $rkey, $cid, $bytes, '');
				$leaves[(string)$path] = $cid;
			}
			$tree = (new Mst($leaves))->build();
			$rev = Tid::after($afterRev);
			$commit = Commit::sign($did, $tree->root, $rev, $signingKey);
			$this->repoRequest->putBlocks($did, AtprotoRepoRequest::BLOCK_MST, $tree->blocks);
			$this->repoRequest->putBlocks($did, AtprotoRepoRequest::BLOCK_COMMIT, [$commit->cid()->toString() => $commit->toBytes()]);
			$this->repoRequest->setHead($did, $commit->cid(), $rev, count($leaves));
			$this->connection->commit();
		} catch (Throwable $e) {
			$this->connection->rollBack();
			throw $e instanceof AtprotoException ? $e : new AtprotoException('Repository import failed: ' . $e->getMessage(), 0, $e);
		}

		return $commit->cid();
	}

	public function getHead(string $did): ?RepoHead {
		return $this->repoRequest->getHead($did);
	}

	public function getRecord(string $did, string $collection, string $rkey): ?StoredRecord {
		$key = self::recordKey($did, $collection, $rkey);
		if (array_key_exists($key, $this->batchRecords)) {
			return $this->batchRecords[$key];
		}

		return $this->repoRequest->getRecord($did, $collection, $rkey);
	}

	/**
	 * @return StoredRecord[]
	 */
	public function getRecordsByLocalId(string $localId): array {
		$records = [];
		foreach ($this->repoRequest->getRecordsByLocalId($localId) as $record) {
			$records[self::recordKey($record->did, $record->collection, $record->rkey)] = $record;
		}
		foreach ($this->batchWrites as $write) {
			$key = self::recordKey($this->batchDid ?? '', $write->collection, $write->rkey);
			unset($records[$key]);
			$record = $this->batchRecords[$key] ?? null;
			if ($record instanceof StoredRecord && $record->localId === $localId) {
				$records[$key] = $record;
			}
		}

		return array_values($records);
	}

	/**
	 * @return array<int, StoredRecord> by record id, newest first
	 */
	public function getRecordsSince(string $collection, int $since, int $limit, int $beforeId = 0): array {
		return $this->repoRequest->getRecordsSince($collection, $since, $limit, $beforeId);
	}

	/**
	 * @return StoredRecord[]
	 */
	public function listRecords(string $did, string $collection, int $limit, string $cursor = '', bool $reverse = false): array {
		$records = [];
		$overlayCount = count(array_filter(array_keys($this->batchRecords), static fn (string $key): bool => str_starts_with($key, $did . "\0" . $collection . "\0")));
		foreach ($this->repoRequest->listRecords($did, $collection, $limit + $overlayCount, $cursor, $reverse) as $record) {
			$records[$record->rkey] = $record;
		}
		foreach ($this->batchRecords as $key => $record) {
			if (!str_starts_with($key, $did . "\0" . $collection . "\0")) {
				continue;
			}
			$rkey = substr($key, strlen($did . "\0" . $collection . "\0"));
			if ($record === null) {
				unset($records[$rkey]);
			} else {
				$records[$rkey] = $record;
			}
		}
		$records = array_filter($records, fn (StoredRecord $record): bool => $cursor === '' || ($reverse ? $record->rkey > $cursor : $record->rkey < $cursor));
		usort($records, static fn (StoredRecord $left, StoredRecord $right): int => $reverse ? strcmp($left->rkey, $right->rkey) : strcmp($right->rkey, $left->rkey));

		return array_slice($records, 0, $limit);
	}

	/**
	 * The whole repository as a CAR, in pieces: the header, then the commit,
	 * the tree and the records as they are read, so a large repository is
	 * never whole in memory. What a relay backfills from.
	 *
	 * @return Generator<int, string> the CAR's bytes, in order
	 * @throws AtprotoException when there is no repository
	 */
	public function streamCar(string $did): Generator {
		$head = $this->repoRequest->getHead($did);
		if ($head === null) {
			throw new AtprotoException('No repository for ' . $did);
		}
		$commitBytes = $this->repoRequest->getBlock($did, $head->commitCid);
		if ($commitBytes === null) {
			throw new AtprotoException('The head commit of ' . $did . ' is missing');
		}

		return $this->carPieces($did, Cid::parse($head->commitCid), $commitBytes);
	}

	/**
	 * The whole repository as a CAR in a temporary stream, for a request
	 * body: spilled to disk past a few megabytes, so a large repository is
	 * never whole in memory. The caller closes it.
	 *
	 * @return resource at its start
	 * @throws AtprotoException when there is no repository
	 */
	public function carStream(string $did) {
		return self::spill($this->streamCar($did));
	}

	/**
	 * Pieces of a CAR into a temporary stream, spilled to disk past a
	 * megabyte.
	 *
	 * @param iterable<string> $pieces
	 * @return resource at its start
	 * @throws AtprotoException
	 */
	public static function spill(iterable $pieces) {
		$car = fopen('php://temp/maxmemory:' . (1024 * 1024), 'w+b');
		if ($car === false) {
			throw new AtprotoException('could not buffer the repository');
		}
		try {
			foreach ($pieces as $piece) {
				fwrite($car, $piece);
			}
		} catch (Throwable $e) {
			fclose($car);
			throw $e;
		}
		rewind($car);

		return $car;
	}

	/**
	 * @return Generator<int, string> pieces of about PIECE bytes
	 */
	private function carPieces(string $did, Cid $commitCid, string $commitBytes): Generator {
		yield Car::header([$commitCid]) . Car::block($commitCid, $commitBytes);
		$piece = '';
		foreach ($this->repoRequest->blocks($did, AtprotoRepoRequest::BLOCK_MST) as [$cid, , $bytes]) {
			$piece .= Car::block($cid, $bytes);
			if (strlen($piece) >= self::PIECE) {
				yield $piece;
				$piece = '';
			}
		}
		foreach ($this->repoRequest->recordBytes($did) as [$cid, $bytes]) {
			$piece .= Car::block($cid, $bytes);
			if (strlen($piece) >= self::PIECE) {
				yield $piece;
				$piece = '';
			}
		}
		if ($piece !== '') {
			yield $piece;
		}
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
