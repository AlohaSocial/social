<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Migration\Version1000Date20261110000001;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The document cache: lookups by every key the app uses, the caching queue,
 * the video pipelines' work lists and the storage accounting.
 */
class CacheDocumentsRequestTest extends TestCase {
	private const BASE = 'https://remote.example/cachedocs';
	private const POST = self::BASE . '/notes/1';
	private const ACTOR = self::BASE . '/users/owner';
	private const ACCOUNT = 'itest-cachedocs';

	private CacheDocumentsRequest $documents;
	/** @var string[] */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		$this->documents = Server::get(CacheDocumentsRequest::class);
	}

	protected function tearDown(): void {
		foreach ($this->created as $id) {
			$this->documents->deleteById($id);
		}
		parent::tearDown();
	}

	private function document(string $name, array $set = []): Document {
		$document = new Document();
		$document->setId(self::BASE . '/documents/' . $name);
		$document->setUrl(self::BASE . '/files/' . $name);
		$document->setParentId($set['parent'] ?? self::POST);
		$document->setAccount($set['account'] ?? '');
		$document->setMediaType($set['mediaType'] ?? 'image/png');
		$document->setMimeType($set['mediaType'] ?? 'image/png');
		$document->setLocalCopy($set['localCopy'] ?? '');
		$document->setResizedCopy($set['resizedCopy'] ?? '');
		$document->setSizeBytes($set['size'] ?? 0);
		$document->setError($set['error'] ?? 0);
		$document->setPublic($set['public'] ?? true);
		$this->documents->save($document);
		$this->created[] = $document->getId();

		return $document;
	}

	/**
	 * @param Document[] $documents
	 * @return string[] the names of ours among them, in order
	 */
	private function ours(array $documents): array {
		$prefix = self::BASE . '/documents/';

		return array_values(array_map(
			fn (Document $d) => substr($d->getId(), strlen($prefix)),
			array_filter($documents, fn (Document $d) => str_starts_with($d->getId(), $prefix))
		));
	}

	private function nidOf(Document $document): string {
		return (string)$document->getNid();
	}

	public function testADocumentIsFoundByEveryKeyItHas(): void {
		$doc = $this->document('a', ['localCopy' => 'uuid-a-full', 'resizedCopy' => 'uuid-a-small']);

		$this->assertSame($doc->getId(), $this->documents->getByNid($doc->getNid())->getId());
		$this->assertSame($doc->getId(), $this->documents->getById($doc->getId())->getId());
		$this->assertSame($doc->getId(), $this->documents->getByUrl($doc->getUrl())->getId());
		$this->assertSame($doc->getId(), $this->documents->getByLocalCopy('uuid-a-full')->getId());
		$this->assertSame($doc->getId(), $this->documents->getByCopy('uuid-a-small')->getId());
		$this->assertSame($doc->getId(), $this->documents->getByUrlAndParent($doc->getUrl(), self::POST)->getId());
		$this->assertTrue($this->documents->isDuplicate($doc));
		$this->assertSame(['a'], $this->ours($this->documents->getByParent(self::POST)));
	}

	public function testAMissingDocumentIsAnException(): void {
		$doc = $this->document('a');
		$other = (clone $doc)->setParentId(self::BASE . '/notes/other');

		$this->assertFalse($this->documents->isDuplicate($other));
		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->documents->getByLocalCopy('no-such-uuid');
	}

	public function testAPrivateDocumentIsNotServedPublicly(): void {
		$doc = $this->document('private', ['public' => false]);

		$this->assertSame($doc->getId(), $this->documents->getById($doc->getId())->getId());
		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->documents->getById($doc->getId(), true);
	}

	public function testAttachmentsResolveOnlyForTheirOwnAccount(): void {
		$doc = $this->document('upload', ['account' => self::ACCOUNT]);

		$this->assertSame(['upload'], $this->ours($this->documents->getFromArray([$this->nidOf($doc)], self::ACCOUNT)));
		$this->assertSame([], $this->documents->getFromArray([$this->nidOf($doc)], 'someone-else'));
	}

	public function testTheNarrowUpdatesTouchOnlyTheirColumns(): void {
		$doc = $this->document('a', ['localCopy' => 'uuid-a']);

		$doc->setDescription('a red square');
		$this->documents->updateDescription($doc);
		$doc->setMediaType('image/webp')->setMimeType('image/webp');
		$this->documents->updateMediaType($doc);
		$doc->setResizedCopy('uuid-a-poster');
		$this->documents->updatePoster($doc);
		$this->documents->updateFocus($doc);
		$this->documents->setSize($doc->getNid(), 1234);

		$stored = $this->documents->getById($doc->getId());
		$this->assertSame('a red square', $stored->getDescription());
		$this->assertSame('image/webp', $stored->getMediaType());
		$this->assertSame('uuid-a-poster', $stored->getResizedCopy());
		$this->assertSame('uuid-a', $stored->getLocalCopy());
		$this->assertSame(1234, $stored->getSizeBytes());

		$stored->setUrl(self::BASE . '/files/a-moved')->setError(2);
		$this->documents->update($stored);
		$this->assertSame(2, $this->documents->getById($doc->getId())->getError());
	}

	public function testTheCachingQueueSkipsCachedFailedAndInProgressDocuments(): void {
		$waiting = $this->document('waiting');
		$this->document('cached', ['localCopy' => 'uuid-cached']);
		$this->document('failed', ['error' => 1]);
		$busy = $this->document('busy');
		$this->documents->initCaching($busy);

		$this->assertSame(['waiting'], $this->ours($this->documents->getNotCachedDocuments(0)));

		$waiting->setLocalCopy('uuid-waiting')->setSizeBytes(99)->setMediaType('image/jpeg');
		$this->documents->endCaching($waiting);
		$this->assertSame([], $this->ours($this->documents->getNotCachedDocuments(0)));
		$this->assertSame(99, $this->documents->getByNid($waiting->getNid())->getSizeBytes());
	}

	/** Newest first, and paged on by nid to the older ones. */
	public function testTheCachingQueueIsNewestFirstAndPagesOn(): void {
		$this->document('older');
		$newer = $this->document('newer');

		$this->assertSame(['newer', 'older'], $this->ours($this->documents->getNotCachedDocuments(0)));
		$this->assertSame(['older'], $this->ours($this->documents->getNotCachedDocuments(0, (int)$newer->getNid())));
	}

	/** Two workers that read the same row: one statement decides which takes it. */
	public function testADocumentIsClaimedOnceAndOnlyWhileItWaits(): void {
		$waiting = $this->document('claimed');
		$cached = $this->document('cached', ['localCopy' => 'uuid-cached']);
		$failed = $this->document('failed', ['error' => 1]);

		$this->assertTrue($this->documents->claimForCaching($waiting));
		$this->assertFalse($this->documents->claimForCaching($waiting), 'taken already');
		$this->assertFalse($this->documents->claimForCaching($cached));
		$this->assertFalse($this->documents->claimForCaching($failed));
		$this->assertSame([], $this->ours($this->documents->getNotCachedDocuments(0)));
	}

	public function testAFailedDownloadCanBeRetriedOnce(): void {
		$failed = $this->document('failed', ['error' => 3]);

		$this->assertSame($failed->getId(), $this->documents->getFailedUncachedByUrl($failed->getUrl())->getId());
		$this->assertTrue($this->documents->resetRemoteErrorForRetry($failed->getId()));
		$this->assertFalse($this->documents->resetRemoteErrorForRetry($failed->getId()), 'nothing left to reset');
		$this->assertSame(['failed'], $this->ours($this->documents->getNotCachedDocuments(0)));
	}

	public function testDocumentsWithoutAMediaTypeAreFoundForTheBackfill(): void {
		$this->document('untyped', ['mediaType' => '', 'localCopy' => 'uuid-untyped']);
		$this->document('avatar', ['mediaType' => '', 'localCopy' => 'avatar']);
		$this->document('typed', ['localCopy' => 'uuid-typed']);

		$this->assertSame(['untyped'], $this->ours($this->documents->getWithoutMediaType(10000)));
	}

	public function testVideoStorageIsCountedPerAccount(): void {
		$this->document('video', ['account' => self::ACCOUNT, 'mediaType' => 'video/mp4', 'localCopy' => 'uuid-v', 'size' => 1000]);
		$this->document('streamed', ['account' => self::ACCOUNT, 'mediaType' => 'video/mp4', 'localCopy' => Document::COPY_STREAMED, 'size' => 5000]);
		$this->document('picture', ['account' => self::ACCOUNT, 'localCopy' => 'uuid-p', 'size' => 70]);

		$this->assertSame(1000, $this->documents->videoBytesOf(self::ACCOUNT));
		$this->assertSame(0, $this->documents->videoBytesOf(''));
		$this->assertEquals(['video/mp4' => 2, 'image/png' => 1], $this->documents->countLocalCopiesByType(self::ACCOUNT));
		$this->assertSame([], $this->documents->countLocalCopiesByType(''));

		$row = array_values(array_filter(
			$this->documents->videoBytesByAccount(1000),
			fn (array $r) => $r['account'] === self::ACCOUNT
		));
		$this->assertSame([['account' => self::ACCOUNT, 'bytes' => 1000, 'files' => 1]], $row);
	}

	public function testTheVideoPipelinesEachSeeTheirOwnWork(): void {
		// uploaded here, so they have an account: only those are transcoded
		$mp4 = $this->document('mp4', ['account' => self::ACCOUNT, 'mediaType' => 'video/mp4', 'localCopy' => 'uuid-mp4']);
		$this->document('webm', ['account' => self::ACCOUNT, 'mediaType' => 'video/webm', 'localCopy' => 'uuid-webm']);
		$this->document('poster', ['account' => self::ACCOUNT, 'mediaType' => 'video/mp4', 'localCopy' => 'uuid-poster', 'resizedCopy' => 'uuid-poster-img']);
		$after = (string)((int)$mp4->getNid() - 1);

		$this->assertSame(['mp4', 'webm'], $this->ours($this->documents->getVideosWithoutPoster(100, $after)));
		$this->assertSame(['mp4', 'webm', 'poster'], $this->ours($this->documents->getVideosToTranscode(100, $after)));
		$this->assertSame(['mp4', 'poster'], $this->ours($this->documents->getVideosToLadder(100, $after)));

		$this->documents->setTranscoded($mp4->getNid(), 1);
		$this->documents->setLaddered($mp4->getNid(), 1);
		$this->assertSame(['webm', 'poster'], $this->ours($this->documents->getVideosToTranscode(100, $after)));
		$this->assertSame(['poster'], $this->ours($this->documents->getVideosToLadder(100, $after)));

		$webm = $this->documents->getByUrl(self::BASE . '/files/webm');
		$this->documents->replaceVideo($webm->getNid(), 'uuid-webm-as-mp4', 'video/mp4');
		$this->assertSame(['poster'], $this->ours($this->documents->getVideosToTranscode(100, $after)));
		$this->assertSame(['webm', 'poster'], $this->ours($this->documents->getVideosToLadder(100, $after)));
	}

	/**
	 * `occ social:media:strip-metadata` walks the local video and sound
	 * uploads with bytes here, and a rewritten copy brings its size along
	 * and sends the video back to the ladder.
	 */
	public function testTheMetadataWalkSeesLocalVideoAndSoundWithBytes(): void {
		$video = $this->document('video', ['account' => self::ACCOUNT, 'mediaType' => 'video/mp4', 'localCopy' => 'uuid-video', 'size' => 100]);
		$this->document('sound', ['account' => self::ACCOUNT, 'mediaType' => 'audio/mpeg', 'localCopy' => 'uuid-sound']);
		$this->document('picture', ['account' => self::ACCOUNT, 'mediaType' => 'image/png', 'localCopy' => 'uuid-picture']);
		$this->document('remote', ['mediaType' => 'video/mp4', 'localCopy' => 'uuid-remote']);
		$this->document('streamed', ['account' => self::ACCOUNT, 'mediaType' => 'video/mp4', 'localCopy' => Document::COPY_STREAMED]);
		$this->document('nothing', ['account' => self::ACCOUNT, 'mediaType' => 'video/mp4']);
		$after = (string)((int)$video->getNid() - 1);

		$this->assertSame(['video', 'sound'], $this->ours($this->documents->getLocalMediaToStrip(100, $after)));
		$this->assertSame(['video'], $this->ours($this->documents->getLocalMediaToStrip(1, $after)), 'a page at a time');
		$this->assertSame(['sound'], $this->ours($this->documents->getLocalMediaToStrip(100, $video->getNid())), 'after the nid given');

		$this->documents->setLaddered($video->getNid(), 1);
		$this->documents->replaceLocalCopy($video->getNid(), 'uuid-video-clean', 90);

		$again = $this->documents->getByUrl(self::BASE . '/files/video');
		$this->assertSame('uuid-video-clean', $again->getLocalCopy());
		$this->assertSame(90, $again->getSizeBytes());
		$this->assertSame(0, $again->getLaddered());
		$this->assertSame('video/mp4', $again->getMediaType());
	}

	/** A document is known to be a Bluesky blob, or handed to the video service, from either table. */
	public function testADocumentPublishedOnBlueskyIsRecognised(): void {
		$did = 'did:plc:itestcachedocsblob000000';
		$blobs = Server::get(\OCA\Social\Db\AtprotoBlobRequest::class);
		$videos = Server::get(\OCA\Social\Db\AtprotoVideoRequest::class);
		try {
			$this->assertFalse($blobs->hasDocument(self::BASE . '/documents/blob'));
			$this->assertFalse($videos->hasDocument(self::BASE . '/documents/upload'));

			$blobs->put(new \OCA\Social\Atproto\Model\BlobRef(
				$did, \OCA\Social\Atproto\Protocol\Cid::forRawDigest(hash('sha256', 'bytes', true)),
				self::BASE . '/documents/blob', 'video/mp4', 5
			));
			$videos->add(self::BASE . '/notes/upload', $did, self::BASE . '/documents/upload');

			$this->assertTrue($blobs->hasDocument(self::BASE . '/documents/blob'));
			$this->assertTrue($videos->hasDocument(self::BASE . '/documents/upload'));
			$this->assertFalse($blobs->hasDocument(self::BASE . '/documents/upload'));
		} finally {
			$blobs->deleteByDid($did);
			$videos->removeByDid($did);
		}
	}

	public function testTheUsagePageWalksEveryDocumentByNid(): void {
		$first = $this->document('a', ['localCopy' => 'uuid-a', 'size' => 10]);
		$this->document('b');

		$rows = array_values(array_filter(
			$this->documents->getUsagePage(1000, (string)((int)$first->getNid() - 1)),
			fn (array $r) => str_starts_with($r['id'], self::BASE)
		));

		$this->assertSame([self::BASE . '/documents/a', self::BASE . '/documents/b'], array_column($rows, 'id'));
		$this->assertSame(10, $rows[0]['size']);
		$this->assertNull($rows[0]['actor_local'], 'the parent is a post, not a cached actor');
	}

	public function testOnlyOldDocumentsWhoseParentIsGoneAreOrphans(): void {
		$old = $this->document('old', ['parent' => self::BASE . '/notes/gone']);
		$this->document('new', ['parent' => self::BASE . '/notes/gone-too']);
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)
			->set('creation', $qb->createNamedParameter(new DateTime('-30 days'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($old->getId()))));
		$qb->executeStatement();

		$this->assertSame(['old'], $this->ours($this->documents->getOrphanedByParent(7, 1000)));
	}

	/**
	 * The sweep reads the orphans a page at a time in nid order, and a page
	 * starts after the last row of the one before: a row that would not go
	 * is not read again at the head of every page.
	 */
	public function testOrphansAreReadAfterACursor(): void {
		$first = $this->document('first', ['parent' => self::BASE . '/notes/gone']);
		$this->document('second', ['parent' => self::BASE . '/notes/gone-too']);
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)
			->set('creation', $qb->createNamedParameter(new DateTime('-30 days'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->in('id_prim', $qb->createNamedParameter(
				[md5(self::BASE . '/documents/first'), md5(self::BASE . '/documents/second')], IQueryBuilder::PARAM_STR_ARRAY
			)));
		$qb->executeStatement();

		$this->assertSame(['first', 'second'], $this->ours($this->documents->getOrphanedByParent(7, 1000)));
		$this->assertSame(['second'], $this->ours($this->documents->getOrphanedByParent(7, 1000, (int)$first->getNid())));
	}

	public function testDocumentsGoWithTheirParent(): void {
		$this->document('avatar', ['parent' => self::ACTOR]);
		$this->assertSame(['avatar'], $this->ours($this->documents->getByParent(self::ACTOR)));

		$this->documents->deleteByParent(self::ACTOR);
		$this->assertSame([], $this->documents->getByParent(self::ACTOR));

		$doc = $this->document('by-url');
		$this->documents->deleteByUrl($doc->getUrl());
		$this->assertFalse($this->documents->isDuplicate($doc));
	}

	/**
	 * `/media/{uuid}` finds a row by the indexed key of its copy names, so a
	 * name that changes is found under the new name and no longer under the
	 * old one, and a purge, which keeps the names, keeps the row reachable.
	 */
	public function testACopyIsFoundUnderItsCurrentNameOnly(): void {
		$doc = $this->document('renamed', ['localCopy' => 'uuid-r-full', 'resizedCopy' => 'uuid-r-small']);

		$doc->setLocalCopy('uuid-r-full-2')->setResizedCopy('uuid-r-small-2');
		$this->documents->endCaching($doc);
		$this->assertSame($doc->getId(), $this->documents->getByCopy('uuid-r-full-2')->getId());
		$this->assertSame($doc->getId(), $this->documents->getByCopy('uuid-r-small-2')->getId());

		$this->documents->replaceLocalCopy($doc->getNid(), 'uuid-r-full-3', 10);
		$this->assertSame($doc->getId(), $this->documents->getByLocalCopy('uuid-r-full-3')->getId());

		$this->documents->markEvicted($doc->getNid());
		$this->assertSame($doc->getId(), $this->documents->getByCopy('uuid-r-full-3')->getId());

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->documents->getByCopy('uuid-r-full-2');
	}

	/** Rows written before the keys existed are keyed by the migration. */
	public function testTheMigrationKeysTheRowsAlreadyThere(): void {
		$doc = $this->document('legacy', ['localCopy' => 'uuid-l-full', 'resizedCopy' => 'uuid-l-small']);
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)
			->set('local_copy_prim', $qb->createNamedParameter(''))
			->set('resized_copy_prim', $qb->createNamedParameter(''))
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($doc->getId()))));
		$qb->executeStatement();

		try {
			$this->documents->getByCopy('uuid-l-small');
			$this->fail('an unkeyed row was found');
		} catch (CacheDocumentDoesNotExistException) {
		}

		Server::get(Version1000Date20261110000001::class)
			->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);

		$this->assertSame($doc->getId(), $this->documents->getByCopy('uuid-l-full')->getId());
		$this->assertSame($doc->getId(), $this->documents->getByCopy('uuid-l-small')->getId());
	}
}
