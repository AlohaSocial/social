<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Repository;

use OCA\Social\Atproto\Model\RepoHead;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Db\AtprotoRepoRequest;

/**
 * The repository tables in arrays, so the commit path can be exercised
 * without a database: the same methods, the same shapes.
 */
class InMemoryRepoRequest extends AtprotoRepoRequest {
	/** @var array<string, RepoHead> */
	public array $heads = [];
	/** @var array<string, array<string, StoredRecord>> did => path => record */
	public array $records = [];
	/** @var array<string, array<string, array{kind: string, bytes: string}>> did => cid => block */
	public array $blocks = [];

	public function __construct() {
	}

	#[\Override]
	public function getHead(string $did): ?RepoHead {
		return $this->heads[$did] ?? null;
	}

	#[\Override]
	public function getHeads(int $limit = 500, int $offset = 0): array {
		return array_slice(array_values($this->heads), $offset, $limit);
	}

	#[\Override]
	public function setHead(string $did, Cid $commitCid, string $rev, int $recordCount): void {
		$this->heads[$did] = new RepoHead($did, $commitCid->toString(), $rev, $recordCount, $this->heads[$did]->blobBytes ?? 0, time());
	}

	#[\Override]
	public function addBlobBytes(string $did, int $bytes): void {
	}

	#[\Override]
	public function getLeaves(string $did): array {
		$leaves = [];
		foreach ($this->records[$did] ?? [] as $path => $record) {
			$leaves[$path] = $record->cid;
		}

		return $leaves;
	}

	#[\Override]
	public function getRecord(string $did, string $collection, string $rkey): ?StoredRecord {
		return $this->records[$did][$collection . '/' . $rkey] ?? null;
	}

	#[\Override]
	public function getRecordsByLocalId(string $localId): array {
		$found = [];
		foreach ($this->records as $records) {
			foreach ($records as $record) {
				if ($record->localId === $localId) {
					$found[] = $record;
				}
			}
		}

		return $found;
	}

	#[\Override]
	public function listRecords(string $did, string $collection, int $limit, string $cursor = '', bool $reverse = false): array {
		$records = array_values(array_filter($this->records[$did] ?? [], static fn (StoredRecord $r): bool => $r->collection === $collection));
		usort($records, static fn (StoredRecord $a, StoredRecord $b): int => $reverse ? strcmp($a->rkey, $b->rkey) : strcmp($b->rkey, $a->rkey));
		if ($cursor !== '') {
			$records = array_values(array_filter($records, static fn (StoredRecord $r): bool => $reverse ? strcmp($r->rkey, $cursor) > 0 : strcmp($r->rkey, $cursor) < 0));
		}

		return array_slice($records, 0, $limit);
	}

	#[\Override]
	public function eachRecordBytes(string $did, callable $each): void {
		foreach ($this->records[$did] ?? [] as $record) {
			$each($record->cid, $record->bytes, $record->collection . '/' . $record->rkey);
		}
	}

	#[\Override]
	public function countRecords(string $did): int {
		return count($this->records[$did] ?? []);
	}

	#[\Override]
	public function putRecord(string $did, string $collection, string $rkey, Cid $cid, string $bytes, string $localId): void {
		$this->records[$did][$collection . '/' . $rkey] = new StoredRecord($did, $collection, $rkey, $cid, $bytes, $localId, time());
	}

	#[\Override]
	public function deleteRecord(string $did, string $collection, string $rkey): void {
		unset($this->records[$did][$collection . '/' . $rkey]);
	}

	#[\Override]
	public function putBlocks(string $did, string $kind, array $blocks): void {
		foreach ($blocks as $cid => $bytes) {
			$this->blocks[$did][(string)$cid] = ['kind' => $kind, 'bytes' => $bytes];
		}
	}

	#[\Override]
	public function deleteBlocks(string $did, array $cids): void {
		foreach ($cids as $cid) {
			unset($this->blocks[$did][$cid]);
		}
	}

	#[\Override]
	public function getBlock(string $did, string $cid): ?string {
		return $this->blocks[$did][$cid]['bytes'] ?? null;
	}

	#[\Override]
	public function eachBlock(string $did, callable $each, string $kind = ''): void {
		foreach ($this->blocks[$did] ?? [] as $cid => $block) {
			if ($kind === '' || $block['kind'] === $kind) {
				$each(Cid::parse((string)$cid), $block['kind'], $block['bytes']);
			}
		}
	}

	#[\Override]
	public function getBlockCids(string $did, string $kind = ''): array {
		$cids = [];
		foreach ($this->blocks[$did] ?? [] as $cid => $block) {
			if ($kind === '' || $block['kind'] === $kind) {
				$cids[] = (string)$cid;
			}
		}

		return $cids;
	}

	#[\Override]
	public function deleteRepository(string $did): void {
		unset($this->heads[$did], $this->records[$did], $this->blocks[$did]);
	}
}
