<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheContentDecodeException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheContentSizeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../ActivityPubTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class DocumentInterfaceTest extends ActivityPubTestCase {
	private const DOCUMENT = self::REMOTE_URL . '/media/1';

	/** @var CacheDocumentService&MockObject */
	private $cacheDocumentService;
	/** @var CacheDocumentsRequest&MockObject */
	private $cacheDocumentsRequest;
	private DocumentInterface $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);

		$this->handler = new DocumentInterface(
			$this->cacheDocumentService, $this->cacheDocumentsRequest, new NullLogger()
		);
	}

	private function document(string $url = self::REMOTE_URL . '/media/1.png'): Document {
		$document = new Document();
		$document->setId(self::DOCUMENT);
		$document->setUrl($url);

		return $document;
	}

	private function nothingCached(): void {
		$this->cacheDocumentsRequest->method('getById')->willThrowException(new CacheDocumentDoesNotExistException());
		$this->cacheDocumentsRequest->method('getByUrlAndParent')->willThrowException(new CacheDocumentDoesNotExistException());
	}

	public function testGeneratedAttachmentIdReusesTheCachedRowForTheSamePost(): void {
		$this->cacheDocumentsRequest->method('getById')->willThrowException(new CacheDocumentDoesNotExistException());
		$note = $this->note(self::REMOTE_URL . '/notes/1', self::REMOTE_URL . '/users/bob');
		$incoming = new Document($note);
		$incoming->setId(self::LOCAL_URL . '/documents/g/new-id');
		$incoming->setUrl(self::REMOTE_URL . '/media/1.png');
		$known = new Document();
		$known->setId(self::LOCAL_URL . '/documents/g/cached-id');
		$known->setNid(42);
		$known->setLocalCopy('cached-image');
		$this->cacheDocumentsRequest->expects($this->once())->method('getByUrlAndParent')
			->with($incoming->getUrl(), $note->getId())->willReturn($known);
		$this->cacheDocumentsRequest->expects($this->never())->method('save');
		$this->cacheDocumentService->expects($this->never())->method('saveRemoteFileToCache');

		$this->handler->save($incoming);

		$this->assertSame($known->getId(), $incoming->getId());
		$this->assertSame(42, $incoming->getNid());
		$this->assertSame('cached-image', $incoming->getLocalCopy());
	}

	/**
	 * And what the second delivery says about it is written down.
	 *
	 * The row is found by URL and parent because the attachment carries no
	 * wire id of its own, and the merge that followed lived only in the object
	 * this request happened to hold: a description added later, a blurhash, a
	 * corrected media type all reached the reader of *that* request and never
	 * the row, which kept what it first heard for good.
	 */
	public function testWhatTheSecondDeliverySaysAboutAnAttachmentIsKept(): void {
		$this->cacheDocumentsRequest->method('getById')->willThrowException(new CacheDocumentDoesNotExistException());
		$note = $this->note(self::REMOTE_URL . '/notes/1', self::REMOTE_URL . '/users/bob');
		$incoming = new Document($note);
		$incoming->setId(self::LOCAL_URL . '/documents/g/new-id');
		$incoming->setUrl(self::REMOTE_URL . '/media/1.png');
		$incoming->setDescription('the cat, at last, in focus');
		$known = new Document();
		$known->setId(self::LOCAL_URL . '/documents/g/cached-id');
		$known->setNid(42);
		$known->setLocalCopy('cached-image');
		$this->cacheDocumentsRequest->method('getByUrlAndParent')->willReturn($known);

		$this->cacheDocumentsRequest->expects($this->once())->method('update')
			->with($this->identicalTo($incoming));
		$this->cacheDocumentsRequest->expects($this->never())->method('save');

		$this->handler->save($incoming);

		$this->assertSame('the cat, at last, in focus', $incoming->getDescription());
		$this->assertSame('cached-image', $incoming->getLocalCopy());
	}

	/**
	 * A new attachment is looked for once, not twice.
	 *
	 * `isDuplicate()` is `getByUrlAndParent()` in a try/catch, so asking it
	 * after that lookup has already missed is the same query with the same
	 * answer — one more of them per attachment of every post that arrives.
	 */
	public function testANewAttachmentIsLookedForOnce(): void {
		$this->cacheDocumentsRequest->method('getById')->willThrowException(new CacheDocumentDoesNotExistException());
		$note = $this->note(self::REMOTE_URL . '/notes/1', self::REMOTE_URL . '/users/bob');
		$incoming = new Document($note);
		$incoming->setId(self::LOCAL_URL . '/documents/g/new-id');
		$incoming->setUrl(self::REMOTE_URL . '/media/1.png');

		$this->cacheDocumentsRequest->expects($this->once())->method('getByUrlAndParent')
			->willThrowException(new CacheDocumentDoesNotExistException());
		$this->cacheDocumentsRequest->expects($this->never())->method('isDuplicate');
		$this->cacheDocumentsRequest->expects($this->once())->method('save')
			->with($this->identicalTo($incoming));

		$this->handler->save($incoming);
	}

	public function testKnownDocumentIsUpdatedInPlace(): void {
		$document = $this->document();
		$this->cacheDocumentsRequest->method('getById')->with(self::DOCUMENT)->willReturn($document);

		$this->cacheDocumentsRequest->expects($this->once())->method('update')->with($this->identicalTo($document));
		$this->cacheDocumentsRequest->expects($this->never())->method('save');
		$this->cacheDocumentService->expects($this->never())->method('saveRemoteFileToCache');

		$this->handler->save($document);
	}

	/**
	 * A document arriving a second time -- a redelivery, an `Update` of the
	 * post it hangs off -- describes a file on somebody else's server and knows
	 * nothing about the copy made of it here. Written as it arrived, it cleared
	 * the copy: the cached file was orphaned and every post showing the picture
	 * broke until the caching cron happened to fetch it again.
	 */
	public function testAReDeliveredDocumentKeepsTheCopyTheRowAlreadyHas(): void {
		$stored = $this->document();
		$stored->setNid(42);
		$stored->setLocalCopy('a0a962e5-7e98-433b-80e2-09106a0b074f');
		$stored->setResizedCopy('272c3a32-c626-45a0-b08f-a03e7bb4ab2d');
		$this->cacheDocumentsRequest->method('getById')->with(self::DOCUMENT)->willReturn($stored);

		// the same document as it arrives off the wire: no copy, no key
		$incoming = $this->document();

		$this->cacheDocumentsRequest->expects($this->once())->method('update')
			->with($this->identicalTo($incoming));

		$this->handler->save($incoming);

		$this->assertSame('a0a962e5-7e98-433b-80e2-09106a0b074f', $incoming->getLocalCopy());
		$this->assertSame('272c3a32-c626-45a0-b08f-a03e7bb4ab2d', $incoming->getResizedCopy());
		// without the key a re-imported attachment went back to a client as
		// `id: 0`, and a streamed one would name row zero to the media proxy
		$this->assertSame(42, $incoming->getNid());
	}

	/** A streamed video deliberately has no copy, and must not gain one. */
	public function testAReDeliveredStreamedDocumentStaysStreamed(): void {
		$stored = $this->document();
		$stored->setNid(7);
		$stored->setLocalCopy(Document::COPY_STREAMED);
		$this->cacheDocumentsRequest->method('getById')->willReturn($stored);

		$incoming = $this->document();
		$incoming->setLocalCopy(Document::COPY_STREAMED);

		$this->handler->save($incoming);

		$this->assertTrue($incoming->isStreamed());
		$this->assertSame(7, $incoming->getNid());
	}

	/** Fetching it is the one thing that must not happen to a streamed row. */
	public function testANewStreamedDocumentIsRecordedWithoutBeingFetched(): void {
		$this->nothingCached();
		$this->cacheDocumentsRequest->method('isDuplicate')->willReturn(false);
		$document = $this->document();
		$document->setLocalCopy(Document::COPY_STREAMED);

		$this->cacheDocumentService->expects($this->never())->method('saveRemoteFileToCache');
		$this->cacheDocumentsRequest->expects($this->once())->method('save')->with($this->identicalTo($document));

		$this->handler->save($document);
	}

	public function testNewRemoteDocumentIsFetchedIntoTheCacheAndStored(): void {
		$this->nothingCached();
		$this->cacheDocumentsRequest->method('isDuplicate')->willReturn(false);
		$document = $this->document();

		$this->cacheDocumentService->expects($this->once())
			->method('saveRemoteFileToCache')->with($this->identicalTo($document));
		$this->cacheDocumentsRequest->expects($this->once())->method('save')->with($this->identicalTo($document));
		$this->cacheDocumentsRequest->expects($this->never())->method('update');

		$this->handler->save($document);
	}

	/**
	 * A peer's `mediaType` is what it says about a file; the sniffed type is
	 * what this instance read. `/media/{uuid}` serves the bytes from this
	 * origin, so the row must hold the second.
	 */
	public function testTheSniffedTypeReplacesTheOneThePeerDeclared(): void {
		$this->nothingCached();
		$this->cacheDocumentsRequest->method('isDuplicate')->willReturn(false);
		$document = $this->document();
		$document->setMediaType('text/html');
		$this->cacheDocumentService->method('saveRemoteFileToCache')->willReturnCallback(
			static function (Document $item, string &$mime): void {
				$item->setLocalCopy('a0a962e5-7e98-433b-80e2-09106a0b074f');
				$mime = 'image/gif';
			}
		);

		$this->handler->save($document);

		$this->assertSame('image/gif', $document->getMediaType());
		$this->assertSame('image/gif', $document->getMimeType());
	}

	/**
	 * One attachment that cannot be fetched used to take the whole post with
	 * it, and leave no row for the caching cron to come back to.
	 */
	public function testAnAttachmentThatCannotBeFetchedIsStillRecorded(): void {
		$this->nothingCached();
		$this->cacheDocumentsRequest->method('isDuplicate')->willReturn(false);
		$document = $this->document();
		$this->cacheDocumentService->method('saveRemoteFileToCache')
			->willThrowException(new RequestContentException('service unavailable', 503));

		$this->cacheDocumentsRequest->expects($this->once())->method('save')
			->with($this->identicalTo($document));

		$this->handler->save($document);

		// what getNotCachedDocuments() looks for, and no marker to stop it
		$this->assertSame('', $document->getLocalCopy());
		$this->assertSame(0, $document->getError());
	}

	public function testAHalfFetchedAttachmentKeepsNoDanglingCopy(): void {
		$this->nothingCached();
		$this->cacheDocumentsRequest->method('isDuplicate')->willReturn(false);
		$document = $this->document();
		$this->cacheDocumentService->method('saveRemoteFileToCache')->willReturnCallback(
			static function (Document $item): void {
				$item->setLocalCopy('a0a962e5-7e98-433b-80e2-09106a0b074f');

				throw new CacheContentDecodeException('not an image after all');
			}
		);

		$this->handler->save($document);

		$this->assertSame('', $document->getLocalCopy());
		$this->assertSame('', $document->getResizedCopy());
	}

	/** @return array<string, array{\Throwable, int}> */
	public static function fetchFailureProvider(): array {
		return [
			'unstorable type' => [new CacheContentMimeTypeException(), DocumentService::ERROR_MIMETYPE],
			'not the image it claims' => [new CacheContentDecodeException(), DocumentService::ERROR_CONTENT],
			'larger than this instance stores' => [new CacheContentSizeException(), DocumentService::ERROR_SIZE],
			// the other end, which is worth asking again
			'origin unreachable' => [new RequestNetworkException('timeout'), 0],
			'origin says no' => [new RequestContentException('gone', 410), 0],
		];
	}

	#[DataProvider('fetchFailureProvider')]
	public function testAPermanentFailureIsMarkedAndATransientOneIsNot(
		\Throwable $failure, int $error,
	): void {
		$this->nothingCached();
		$this->cacheDocumentsRequest->method('isDuplicate')->willReturn(false);
		$document = $this->document();
		$this->cacheDocumentService->method('saveRemoteFileToCache')->willThrowException($failure);

		$this->cacheDocumentsRequest->expects($this->once())->method('save');

		$this->handler->save($document);

		$this->assertSame($error, $document->getError());
	}

	public function testNewLocalDocumentIsNotFetchedFromAnywhere(): void {
		$this->nothingCached();
		$this->cacheDocumentsRequest->method('isDuplicate')->willReturn(false);
		$document = $this->document();
		$document->setLocal(true);

		$this->cacheDocumentService->expects($this->never())->method('saveRemoteFileToCache');
		$this->cacheDocumentsRequest->expects($this->once())->method('save')->with($this->identicalTo($document));

		$this->handler->save($document);
	}

	public function testDocumentAlreadyCachedUnderItsUrlIsNotStoredAgain(): void {
		$this->nothingCached();
		$document = $this->document();
		$this->cacheDocumentsRequest->method('isDuplicate')->with($this->identicalTo($document))->willReturn(true);

		$this->cacheDocumentsRequest->expects($this->never())->method('save');

		$this->handler->save($document);
	}

	public function testFreshUploadWithoutUrlIsStoredForItsOwnerWithoutDuplicateCheck(): void {
		$this->nothingCached();
		$upload = new Document();
		$upload->setId(self::LOCAL_URL . '/documents/g/1');
		$upload->setAccount('alice');
		$upload->setLocal(true);

		$this->cacheDocumentsRequest->expects($this->never())->method('isDuplicate');
		$this->cacheDocumentsRequest->expects($this->once())->method('save')->with($this->identicalTo($upload));

		$this->handler->save($upload);
	}

	public function testDocumentAttachedToAStreamRecordsItsParent(): void {
		$this->nothingCached();
		$this->cacheDocumentsRequest->method('isDuplicate')->willReturn(false);
		$note = $this->note(self::REMOTE_URL . '/notes/1', self::REMOTE_URL . '/users/bob');
		$attachment = new Document($note);
		$attachment->setId(self::DOCUMENT);
		$attachment->setUrl(self::REMOTE_URL . '/media/1.png');

		$this->cacheDocumentsRequest->expects($this->once())->method('save')->with($this->identicalTo($attachment));

		$this->handler->save($attachment);

		$this->assertSame($note->getId(), $attachment->getParentId());
	}

	public function testIconOfAnActorMustBeHostedByTheActorsServer(): void {
		$bob = $this->incoming(Person::TYPE, self::REMOTE_URL . '/users/bob', self::REMOTE_URL . '/users/bob');
		$icon = new Image($bob);
		$icon->setId('https://cdn.example/avatars/bob.png');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($bob, $icon);
	}

	public function testIconHostedByTheActorsServerIsAccepted(): void {
		$this->expectNotToPerformAssertions();

		$bob = $this->incoming(Person::TYPE, self::REMOTE_URL . '/users/bob', self::REMOTE_URL . '/users/bob');
		$icon = new Image($bob);
		$icon->setId(self::REMOTE_URL . '/avatars/bob.png');

		$this->handler->activity($bob, $icon);
	}

	public function testAttachmentsOfOtherActivitiesAreNotOriginChecked(): void {
		$this->expectNotToPerformAssertions();

		$create = $this->incoming(Create::TYPE, self::REMOTE_URL . '/notes/1/activity', self::REMOTE_URL . '/users/bob');
		$attachment = new Document($create);
		$attachment->setId('https://cdn.example/media/1.png');

		$this->handler->activity($create, $attachment);
	}
}
