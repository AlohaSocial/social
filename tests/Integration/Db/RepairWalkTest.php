<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\CheckService;
use OCA\Social\Tests\Integration\QueryCounter;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The repairs `occ social:check:install` runs, on a table of a realistic size.
 *
 * Both used to read the whole of their table into PHP and then ask the
 * database about every row: on an instance with a few hundred thousand posts
 * that is the memory limit, and four hundred thousand queries before it. They
 * now walk a page at a time, which is bounded whatever the table holds: the
 * queries grow with the number of pages, and the memory not at all.
 */
class RepairWalkTest extends TestCase {
	private const BASE = 'https://remote.example/repairwalk';
	private const KNOWN = self::BASE . '/users/known';
	private const GONE = self::BASE . '/users/gone';
	private const NOTES = 1200;
	private const FOLLOWS = 600;

	/** @var string[] */
	private array $notes = [];

	/** @var string[] */
	private array $follows = [];

	/** @var string[] cached actor ids */
	private array $actors = [];

	protected function tearDown(): void {
		$streamRequest = Server::get(StreamRequest::class);
		foreach ($this->notes as $id) {
			$streamRequest->deleteById($id, Note::TYPE);
		}
		$followsRequest = Server::get(FollowsRequest::class);
		foreach ($this->follows as $id) {
			$followsRequest->deleteById($id);
		}
		foreach ($this->actors as $id) {
			Server::get(CacheActorsRequest::class)->deleteCacheById($id);
		}

		parent::tearDown();
	}

	private function cache(string $id): void {
		$person = new Person();
		$person->setId($id)->setPreferredUsername(basename($id));
		$person->setAccount(basename($id) . '@remote.example')
			->setFollowers($id . '/followers')
			->setInbox($id . '/inbox')
			->setLocal(false);
		Server::get(CacheActorsRequest::class)->save($person);
		$this->actors[] = $id;
	}

	/**
	 * `$count` notes by the cached author, numbered from `$from`, with one by an
	 * author nobody has cached every hundred, so the orphans fall across
	 * several pages.
	 *
	 * @return string[] the orphans' ids
	 */
	private function seedNotes(int $count, int $from = 0): array {
		$streamRequest = Server::get(StreamRequest::class);
		$orphans = [];
		for ($i = $from; $i < $from + $count; $i++) {
			$author = ($i % 100 === 50) ? self::GONE : self::KNOWN;
			$note = new Note();
			$note->setId(self::BASE . '/notes/' . $i);
			$note->setAttributedTo($author);
			$note->setTo(ACore::CONTEXT_PUBLIC);
			$note->setContent('<p>' . $i . '</p>');
			$note->setPublishedTime(time() - $i);
			$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() - $i));
			$streamRequest->save($note);
			$this->notes[] = $note->getId();
			if ($author === self::GONE) {
				$orphans[] = $note->getId();
			}
		}

		return $orphans;
	}

	/** @return string[] the ids of the follows whose follower is not cached */
	private function seedFollows(int $count, int $from = 0): array {
		$followsRequest = Server::get(FollowsRequest::class);
		$orphans = [];
		for ($i = $from; $i < $from + $count; $i++) {
			$actor = ($i % 100 === 50) ? self::GONE : self::KNOWN;
			$follow = new Follow();
			$follow->setId(self::BASE . '/follows/' . $i);
			$follow->setActorId($actor);
			$follow->setObjectId(self::BASE . '/users/followed' . $i);
			$follow->setFollowId(self::BASE . '/users/followed' . $i . '/followers');
			$follow->setAccepted(true);
			$followsRequest->save($follow);
			$this->follows[] = $follow->getId();
			if ($actor === self::GONE) {
				$orphans[] = $follow->getId();
			}
		}

		return $orphans;
	}

	private function countRows(string $table, string $type = ''): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($table);
		if ($type !== '') {
			$qb->where($qb->expr()->eq('type', $qb->createNamedParameter($type)));
		}
		$cursor = $qb->executeQuery();
		$total = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $total;
	}

	private function streamExists(string $id): bool {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('id')->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($id))));
		$cursor = $qb->executeQuery();
		$found = $cursor->fetchOne() !== false;
		$cursor->closeCursor();

		return $found;
	}

	/**
	 * Runs `$repair`, and answers what it returned and how far the peak memory
	 * rose above where it started.
	 *
	 * Not under `QueryCounter`: its log of every statement grows with the
	 * deletes and would be measured along with the walk.
	 *
	 * @return array{int, int}
	 */
	private function measureMemory(callable $repair): array {
		gc_collect_cycles();
		memory_reset_peak_usage();
		$before = memory_get_usage();
		$result = (int)$repair();

		return [$result, memory_get_peak_usage() - $before];
	}

	/**
	 * The ceiling on the statements a walk over `$rows` rows may run: two a
	 * page — the page and the cached actors it names — one more for the page
	 * that comes back short, and the deletes. A query per row would be
	 * `$rows` on its own.
	 */
	private static function walkBudget(int $rows, int $deleted, int $perDelete): int {
		return 2 * (intdiv($rows, CheckService::REPAIR_PAGE) + 1) + $deleted * $perDelete;
	}

	public function testRemovingNotesOfUncachedAuthorsWalksInPages(): void {
		$connection = Server::get(IDBConnection::class);
		$checkService = Server::get(CheckService::class);
		// notes other tests left without a cached author: removed first, so
		// the measured walks delete only the orphans seeded here
		$checkService->removeInvalidNotes();
		$this->cache(self::KNOWN);
		$connection->beginTransaction();
		$orphans = $this->seedNotes(self::NOTES);
		$connection->commit();
		$rows = $this->countRows(CoreRequestBuilder::TABLE_STREAM, Note::TYPE);

		// what deleting one note costs, which the walk pays per orphan whatever
		// it reads them with
		$first = (string)array_pop($orphans);
		$deleteCost = QueryCounter::record(
			static fn () => Server::get(StreamRequest::class)->deleteById($first, Note::TYPE)
		)->count();
		$run = QueryCounter::record(static fn (): int => $checkService->removeInvalidNotes());
		$removed = (int)$run->result();

		$this->assertSame(count($orphans), $removed);
		foreach ($orphans as $id) {
			$this->assertFalse($this->streamExists($id), $id . ' has no cached author and should be gone');
		}
		$this->assertTrue($this->streamExists(self::BASE . '/notes/0'), 'a note by a cached author is kept');
		$this->assertLessThanOrEqual(
			self::walkBudget($rows, $removed, $deleteCost), $run->count(),
			'a walk over ' . $rows . ' notes; ' . mb_strimwidth($run->summary(), 0, 2000, '…')
		);

		// the memory over the same table, with one new orphan to delete
		$connection->beginTransaction();
		$orphans = $this->seedNotes(100, self::NOTES);
		$connection->commit();
		$rows = $this->countRows(CoreRequestBuilder::TABLE_STREAM, Note::TYPE);
		[$removed, $memory] = $this->measureMemory(static fn (): int => $checkService->removeInvalidNotes());

		$this->assertSame(count($orphans), $removed);
		$this->assertLessThan(
			4 * 1024 * 1024, $memory,
			'the walk holds one page at a time, not ' . $rows . ' notes'
		);
	}

	public function testRemovingFollowsOfUncachedAccountsWalksInPages(): void {
		$connection = Server::get(IDBConnection::class);
		$checkService = Server::get(CheckService::class);
		// follows other tests left with an account that is not cached
		$checkService->removeInvalidFollows();
		$this->cache(self::KNOWN);
		for ($i = 0; $i < self::FOLLOWS + 100; $i++) {
			$this->cache(self::BASE . '/users/followed' . $i);
		}
		$connection->beginTransaction();
		$orphans = $this->seedFollows(self::FOLLOWS);
		$connection->commit();
		$rows = $this->countRows(CoreRequestBuilder::TABLE_FOLLOWS);

		$run = QueryCounter::record(static fn (): int => $checkService->removeInvalidFollows());
		$removed = (int)$run->result();

		$this->assertSame(count($orphans), $removed);
		$remaining = $this->countRows(CoreRequestBuilder::TABLE_FOLLOWS);
		$this->assertSame($rows - $removed, $remaining);
		$this->assertLessThanOrEqual(
			self::walkBudget($rows, $removed, 1), $run->count(),
			'a walk over ' . $rows . ' follows; ' . mb_strimwidth($run->summary(), 0, 2000, '…')
		);

		$connection->beginTransaction();
		$orphans = $this->seedFollows(100, self::FOLLOWS);
		$connection->commit();
		[$removed, $memory] = $this->measureMemory(static fn (): int => $checkService->removeInvalidFollows());

		$this->assertSame(count($orphans), $removed);
		$this->assertLessThan(4 * 1024 * 1024, $memory, 'the walk holds one page at a time');
	}
}
