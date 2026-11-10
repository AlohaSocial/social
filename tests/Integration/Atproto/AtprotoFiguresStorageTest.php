<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Client\WriteService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Event;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoEventRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The reads behind the administrators' Bluesky figures, against the real
 * database: the records written in a window, found from its first id; the
 * repository totals summed from the heads; the newest event's time.
 *
 * The figures are instance-wide, so each is read before this test's rows
 * are written and again after, and the difference is what is asserted.
 */
class AtprotoFiguresStorageTest extends TestCase {
	private AtprotoRepoRequest $repositories;
	private AtprotoEventRequest $events;
	private string $did;

	protected function setUp(): void {
		$this->repositories = Server::get(AtprotoRepoRequest::class);
		$this->events = Server::get(AtprotoEventRequest::class);
		$this->did = 'did:plc:' . substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz234567', 2)), 0, 24);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testThePostsWrittenHereInTheWindowAreCountedFromItsFirstId(): void {
		$since = time() - 60;
		$before = $this->repositories->countRecordsWrittenSince(RecordMapper::POST, $since);

		$this->record(RecordMapper::POST, '3kfig1', 'https://social.test/@alice/1');
		$this->record(RecordMapper::POST, '3kfig2', 'https://social.test/@alice/2');
		// a record a moved-in repository brought has no object here
		$this->record(RecordMapper::POST, '3kfig3', '');
		$this->record(RecordMapper::LIKE, '3kfig4', 'https://social.test/@alice/like/1');

		$this->assertSame($before + 2, $this->repositories->countRecordsWrittenSince(RecordMapper::POST, $since));
		$this->assertSame(0, $this->repositories->countRecordsWrittenSince(RecordMapper::POST, time() + 3600));

		$last = $this->repositories->lastRecordId();
		$found = $this->repositories->recordAtOrAfter($last);
		$this->assertSame($last, $found['id'] ?? null);
		$this->assertEqualsWithDelta(time(), $found['creation'] ?? 0, 120, 'the creation time reads back as a Unix time');
		$this->assertNull($this->repositories->recordAtOrAfter($last + 1));
		$this->assertGreaterThan(0, $this->repositories->firstRecordIdSince($since));
		$this->assertLessThanOrEqual($last, $this->repositories->firstRecordIdSince($since));
	}

	public function testTheRepositoryTotalsAreSummedFromTheHeads(): void {
		$before = $this->repositories->sumHeads();

		$this->repositories->setHead($this->did, Cid::forRaw($this->did), '3kznmn7xqxl22', 7);
		$this->repositories->addBlobBytes($this->did, 1234);

		$this->assertSame(
			['records' => $before['records'] + 7, 'blob_bytes' => $before['blob_bytes'] + 1234],
			$this->repositories->sumHeads()
		);
	}

	public function testTheBlobsRegisteredBeforeTheFirstCommitAreCountedByIt(): void {
		$blobs = Server::get(AtprotoBlobRequest::class);
		$blobs->put(new BlobRef($this->did, Cid::forRaw('before the first commit'), 'https://social.test/documents/early', 'image/png', 4096));
		// no repository row yet: nothing to add the bytes to
		$this->repositories->addBlobBytes($this->did, 4096);

		$this->repositories->setHead($this->did, Cid::forRaw($this->did), '3kznmn7xqxl22', 1);
		$this->assertSame(4096, $this->repositories->getHead($this->did)?->blobBytes);

		$this->repositories->addBlobBytes($this->did, 100);
		$this->repositories->setHead($this->did, Cid::forRaw($this->did . '2'), '3kznmn7xqxl23', 2);
		$this->assertSame(4196, $this->repositories->getHead($this->did)?->blobBytes, 'later commits keep the count');
	}

	public function testTheRecordsOutsideTheCollectionsSocialHasARoleForAreCountedWithTheirBytes(): void {
		$this->record(RecordMapper::POST, '3kuse1', 'https://social.test/@alice/1');
		$this->record('app.bsky.graph.list', '3kuse2', '');
		$this->repositories->putRecord($this->did, 'com.example.notes.entry', '3kuse3', Cid::forRaw('a'), str_repeat('x', 300), '');
		$this->repositories->putRecord($this->did, 'com.example.notes.entry', '3kuse4', Cid::forRaw('b'), str_repeat('y', 200), '');

		$this->assertSame(['records' => 2, 'bytes' => 500], $this->repositories->usageOutside($this->did, WriteService::roleCollections()));
		$this->assertSame(['records' => 0, 'bytes' => 0], $this->repositories->usageOutside('did:plc:nobodyhereatall2345678901', WriteService::roleCollections()));
	}

	public function testTheNewestEventsTimeIsTheTimeOfTheHighestSequence(): void {
		$this->events->append($this->did, Event::KIND_IDENTITY, 'first', 2_000_000_000);
		$this->events->append($this->did, Event::KIND_ACCOUNT, 'second', 2_000_000_100);

		$this->assertSame(2_000_000_100, $this->events->latestTime());
	}

	private function record(string $collection, string $rkey, string $localId): void {
		$this->repositories->putRecord($this->did, $collection, $rkey, Cid::forRaw($collection . $rkey), 'bytes', $localId);
	}

	private function cleanUp(): void {
		$this->repositories->deleteRepository($this->did);
		Server::get(AtprotoBlobRequest::class)->deleteByDid($this->did);
		RowSeeder::delete(CoreRequestBuilder::TABLE_ATPROTO_EVENT, ['did' => $this->did]);
	}
}
