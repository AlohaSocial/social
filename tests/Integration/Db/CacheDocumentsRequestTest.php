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
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
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
	private const MOVED = self::BASE . '/users/owner-new';
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
		$mp4 = $this->document('mp4', ['mediaType' => 'video/mp4', 'localCopy' => 'uuid-mp4']);
		$this->document('webm', ['mediaType' => 'video/webm', 'localCopy' => 'uuid-webm']);
		$this->document('poster', ['mediaType' => 'video/mp4', 'localCopy' => 'uuid-poster', 'resizedCopy' => 'uuid-poster-img']);
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

	public function testDocumentsFollowAnAccountThatMovesAndGoWithTheirParent(): void {
		$this->document('avatar', ['parent' => self::ACTOR]);

		$this->documents->moveAccount(self::ACTOR, self::MOVED);
		$this->assertSame([], $this->documents->getByParent(self::ACTOR));
		$this->assertSame(['avatar'], $this->ours($this->documents->getByParent(self::MOVED)));

		$this->documents->deleteByParent(self::MOVED);
		$this->assertSame([], $this->documents->getByParent(self::MOVED));

		$doc = $this->document('by-url');
		$this->documents->deleteByUrl($doc->getUrl());
		$this->assertFalse($this->documents->isDuplicate($doc));
	}
}
