<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheContentDecodeException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheContentSizeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IAvatarManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DocumentServiceTest extends TestCase {
	private const DOC_ID = 'https://remote.example/media/1';
	private const UUID = '2b5a7a87-8db1-445f-a17b-405790f91c80';

	private IURLGenerator|MockObject $urlGenerator;
	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private ActorsRequest|MockObject $actorsRequest;
	private StreamRequest|MockObject $streamRequest;
	private CacheDocumentService|MockObject $cacheService;
	private ConfigService|MockObject $configService;
	private MiscService|Stub $miscService;
	private DocumentService $service;
	/** @var array<array{0: int, 1: int}> the (timeout, connect timeout) of every bounded scope entered */
	private array $timeoutScopes = [];
	private bool $inTimeoutScope = false;

	protected function setUp(): void {
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->cacheService = $this->createMock(CacheDocumentService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->miscService = $this->createStub(MiscService::class);
		$this->configService->method('withRequestTimeout')->willReturnCallback(
			function (int $timeout, callable $action, int $connectTimeout = 0): mixed {
				$this->timeoutScopes[] = [$timeout, $connectTimeout];
				$this->inTimeoutScope = true;
				try {
					return $action();
				} finally {
					$this->inTimeoutScope = false;
				}
			}
		);

		$this->service = new DocumentService(
			$this->urlGenerator,
			$this->cacheDocumentsRequest,
			$this->createStub(\OCA\Social\Db\RenditionsRequest::class),
			$this->actorsRequest,
			$this->streamRequest,
			$this->cacheService,
			$this->configService,
			$this->miscService,
			$this->createStub(IAvatarManager::class),
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	private function document(string $localCopy = '', int $error = 0): Document {
		$document = new Document();
		$document->setId(self::DOC_ID);
		$document->setUrl('https://remote.example/files/pic.png');
		$document->setMimeType('image/png');
		$document->setLocalCopy($localCopy);
		$document->setResizedCopy($localCopy === '' ? '' : 'resized-' . $localCopy);
		$document->setError($error);

		return $document;
	}

	private function viewer(string $username = 'alice', string $id = 'https://cloud.example/@alice'): Person {
		$person = new Person();
		$person->setId($id);
		$person->setPreferredUsername($username);

		return $person;
	}

	// viewer-scoped lookups: a document id is not a capability

	public function testAPublicDocumentIsReadableByAnyone(): void {
		$doc = $this->document('copy-1');
		$doc->setPublic(true);
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$file = $this->createStub(ISimpleFile::class);
		$this->cacheService->method('getContentFromCache')->with('copy-1')->willReturn($file);

		$mime = '';
		$this->assertSame($file, $this->service->getFromCacheAsViewer(self::DOC_ID, null, $mime));
		$this->assertSame('image/png', $mime);
	}

	public function testANonPublicDocumentIsNotReadableWithoutASession(): void {
		$this->cacheDocumentsRequest->method('getById')->willReturn($this->document('copy-1'));
		$this->cacheService->expects($this->never())->method('getContentFromCache');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->expectExceptionMessage('unknown document');
		$mime = '';
		$this->service->getFromCacheAsViewer(self::DOC_ID, null, $mime);
	}

	public function testAViewerReadsTheirOwnUpload(): void {
		$doc = $this->document('copy-1');
		$doc->setAccount('alice');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$file = $this->createStub(ISimpleFile::class);
		$this->cacheService->method('getContentFromCache')->willReturn($file);

		$mime = '';
		$this->assertSame(
			$file, $this->service->getFromCacheAsViewer(self::DOC_ID, $this->viewer(), $mime)
		);
	}

	public function testAnotherAccountsUploadIsRefused(): void {
		// the finding: any session could name any id and read anybody's media
		$doc = $this->document('copy-1');
		$doc->setAccount('bob');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->expects($this->never())->method('getContentFromCache');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$mime = '';
		$this->service->getFromCacheAsViewer(self::DOC_ID, $this->viewer(), $mime);
	}

	public function testAnAttachmentOfAPostTheViewerCanSeeIsReadable(): void {
		$doc = $this->document('copy-1');
		$doc->setParentId('https://remote.example/notes/1');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$viewer = $this->viewer();
		$this->streamRequest->expects($this->once())->method('setViewer')->with($viewer);
		$this->streamRequest->expects($this->once())->method('getStreamById')
			->with('https://remote.example/notes/1', true)
			->willReturn(new Stream());
		$file = $this->createStub(ISimpleFile::class);
		$this->cacheService->method('getContentFromCache')->willReturn($file);

		$mime = '';
		$this->assertSame($file, $this->service->getFromCacheAsViewer(self::DOC_ID, $viewer, $mime));
	}

	public function testAnAttachmentOfAPostTheViewerCannotSeeIsRefused(): void {
		// a direct message's attachment: the timeline check is what keeps it out
		$doc = $this->document('copy-1');
		$doc->setParentId('https://remote.example/notes/secret');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->streamRequest->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->cacheService->expects($this->never())->method('getContentFromCache');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$mime = '';
		$this->service->getFromCacheAsViewer(self::DOC_ID, $this->viewer(), $mime);
	}

	public function testAViewerReadsTheirOwnActorsAvatar(): void {
		$doc = $this->document('copy-1');
		$doc->setParentId('https://cloud.example/@alice');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->streamRequest->expects($this->never())->method('getStreamById');
		$file = $this->createStub(ISimpleFile::class);
		$this->cacheService->method('getContentFromCache')->willReturn($file);

		$mime = '';
		$this->assertSame(
			$file, $this->service->getFromCacheAsViewer(self::DOC_ID, $this->viewer(), $mime)
		);
	}

	public function testTheResizedCopyIsScopedTheSameWay(): void {
		$this->cacheDocumentsRequest->method('getById')->willReturn($this->document('copy-1'));
		$this->cacheService->expects($this->never())->method('getContentFromCache');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$mime = '';
		$this->service->getResizedFromCacheAsViewer(self::DOC_ID, $this->viewer(), $mime);
	}

	public function testCachingOnBehalfOfAViewerIsScopedTheSameWay(): void {
		$this->cacheDocumentsRequest->method('getById')->willReturn($this->document('copy-1'));

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->service->cacheRemoteDocumentAsViewer(self::DOC_ID, $this->viewer());
	}

	public function testAnUnknownIdIsRefusedTheSameWayAsAForbiddenOne(): void {
		// probing must not tell the caller which ids are real
		$this->cacheDocumentsRequest->method('getById')
			->willThrowException(new CacheDocumentDoesNotExistException());

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$mime = '';
		$this->service->getFromCacheAsViewer('https://remote.example/media/none', $this->viewer(), $mime);
	}

	// the cached copy of a remote url

	public function testGetCachedFromUrlServesTheStoredCopy(): void {
		$this->cacheDocumentsRequest->method('getByUrl')
			->with('https://remote.example/header.jpg')->willReturn($this->document('copy-9'));
		$file = $this->createStub(ISimpleFile::class);
		$this->cacheService->method('getContentFromCache')->with('copy-9')->willReturn($file);

		$mime = '';
		$this->assertSame($file, $this->service->getCachedFromUrl('https://remote.example/header.jpg', $mime));
		$this->assertSame('image/png', $mime);
	}

	public function testGetCachedFromUrlReportsAnUncachedUrl(): void {
		$this->cacheDocumentsRequest->method('getByUrl')->willReturn($this->document());

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->expectExceptionMessage('not cached');
		$this->service->getCachedFromUrl('https://remote.example/header.jpg');
	}

	public function testGetMediaFromArrayDelegates(): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->expects($this->once())
			->method('getFromArray')
			->with(['1', '2'], 'alice@cloud.example.com')
			->willReturn([$doc]);

		$this->assertSame([$doc], $this->service->getMediaFromArray(['1', '2'], 'alice@cloud.example.com'));
	}

	public function testGetFromUuidRejectsMalformedIds(): void {
		$this->cacheService->expects($this->never())->method('getFromUuid');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('invalid document');
		$this->service->getFromUuid('../../etc/passwd');
	}

	public function testGetFromUuidReturnsThePublicCopyAndItsRow(): void {
		$file = $this->createStub(ISimpleFile::class);
		$document = $this->createStub(Document::class);
		$document->method('isPublic')->willReturn(true);
		$this->cacheDocumentsRequest->expects($this->once())
			->method('getByCopy')->with(self::UUID)->willReturn($document);
		$this->cacheService->expects($this->once())->method('getFromUuid')->with(self::UUID)->willReturn($file);

		$this->assertSame([$file, $document], $this->service->getFromUuid(self::UUID));
	}

	public function testGetFromUuidServesTheAttachmentOfAFollowersOnlyPostByItsUuid(): void {
		// the uuid is the capability: Mastodon fetches media unsigned, so a copy
		// that is only handed out when its row says `public` is a broken image
		// on every followers-only post with a picture
		$file = $this->createStub(ISimpleFile::class);
		$document = $this->createStub(Document::class);
		$document->method('isPublic')->willReturn(false);
		$this->cacheDocumentsRequest->method('getByCopy')->with(self::UUID)->willReturn($document);
		$this->cacheService->method('getFromUuid')->with(self::UUID)->willReturn($file);

		$this->assertSame([$file, $document], $this->service->getFromUuid(self::UUID));
	}

	public function testGetFromUuidOfAnUnknownUuidIsNotFound(): void {
		$this->cacheDocumentsRequest->method('getByCopy')->with(self::UUID)
			->willThrowException(new CacheDocumentDoesNotExistException());
		$this->cacheService->expects($this->never())->method('getFromUuid');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('unknown document');
		$this->service->getFromUuid(self::UUID);
	}

	public function testCacheRemoteDocumentReturnsAnAlreadyCachedDocument(): void {
		$doc = $this->document('local-1');
		$this->cacheDocumentsRequest->method('getById')->with(self::DOC_ID, true)->willReturn($doc);
		$this->cacheDocumentsRequest->expects($this->never())->method('initCaching');
		$this->cacheService->expects($this->never())->method('saveRemoteFileToCache');

		$this->assertSame($doc, $this->service->cacheRemoteDocument(self::DOC_ID, true));
	}

	public function testCacheRemoteDocumentHidesDocumentsInError(): void {
		$this->cacheDocumentsRequest->method('getById')->willReturn($this->document('', DocumentService::ERROR_MIMETYPE));

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->service->cacheRemoteDocument(self::DOC_ID);
	}

	public function testCacheRemoteDocumentDoesNotRetryWhileACachingIsInProgress(): void {
		$doc = $this->document();
		$doc->setCaching(time() - 60);
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheDocumentsRequest->expects($this->never())->method('initCaching');

		$this->assertSame($doc, $this->service->cacheRemoteDocument(self::DOC_ID));
	}

	public function testCacheRemoteDocumentDownloadsAndAttachesTheFile(): void {
		$doc = $this->document();
		$doc->setCaching(time() - CacheDocumentsRequest::CACHING_TIMEOUT * 60 - 10);
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheDocumentsRequest->expects($this->once())->method('initCaching')->with($this->identicalTo($doc));
		$this->cacheService->expects($this->once())
			->method('saveRemoteFileToCache')
			->with($this->identicalTo($doc))
			->willReturnCallback(function (Document $document) {
				$document->setLocalCopy('fresh');
			});
		$this->cacheDocumentsRequest->expects($this->once())->method('endCaching')->with($this->identicalTo($doc));
		$this->streamRequest->expects($this->once())->method('updateAttachments')->with($this->identicalTo($doc));

		$this->assertSame($doc, $this->service->cacheRemoteDocument(self::DOC_ID));
		$this->assertSame('fresh', $doc->getLocalCopy());
	}

	/** @return array<string, array{\Exception, int}> */
	public static function cachingErrorProvider(): array {
		return [
			'wrong mime type' => [new CacheContentMimeTypeException(), DocumentService::ERROR_MIMETYPE],
			'download cut off' => [new RequestResultSizeException(), DocumentService::ERROR_SIZE],
			'larger than this instance stores' => [
				new CacheContentSizeException(), DocumentService::ERROR_SIZE,
			],
			'storage not found' => [new NotFoundException(), DocumentService::ERROR_PERMISSION],
			'storage not permitted' => [new NotPermittedException(), DocumentService::ERROR_PERMISSION],
		];
	}

	#[DataProvider('cachingErrorProvider')]
	public function testCacheRemoteDocumentRecordsPermanentErrors(\Exception $failure, int $error): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')->willThrowException($failure);
		$this->cacheDocumentsRequest->expects($this->once())->method('endCaching')->with($this->identicalTo($doc));
		$this->cacheDocumentsRequest->expects($this->never())->method('deleteById');
		$this->streamRequest->expects($this->never())->method('updateAttachments');

		try {
			$this->service->cacheRemoteDocument(self::DOC_ID);
			$this->fail('expected CacheDocumentDoesNotExistException');
		} catch (CacheDocumentDoesNotExistException $e) {
			$this->assertSame($error, $doc->getError());
		}
	}

	/** @return array<string, array{\Exception}> */
	public static function goneProvider(): array {
		return [
			'remote says gone' => [new RequestContentException('gone', 410)],
			'blocked instance' => [new UnauthorizedFediverseException()],
		];
	}

	#[DataProvider('goneProvider')]
	public function testCacheRemoteDocumentDeletesUnreachableDocuments(\Exception $failure): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')->willThrowException($failure);
		$this->cacheDocumentsRequest->expects($this->once())->method('deleteById')->with(self::DOC_ID);
		$this->cacheDocumentsRequest->expects($this->never())->method('endCaching');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->service->cacheRemoteDocument(self::DOC_ID);
	}

	public function testCacheRemoteDocumentLeavesTransientErrorsRetryable(): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')->willThrowException(new RequestNetworkException('timeout'));
		$this->cacheDocumentsRequest->expects($this->once())->method('endCaching');
		$this->cacheDocumentsRequest->expects($this->never())->method('deleteById');

		try {
			$this->service->cacheRemoteDocument(self::DOC_ID);
			$this->fail('expected CacheDocumentDoesNotExistException');
		} catch (CacheDocumentDoesNotExistException $e) {
			$this->assertSame(0, $doc->getError());
		}
	}

	public function testAnUndecodableDocumentIsRecordedSoItIsNotRetriedForever(): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')
			->willThrowException(new CacheContentDecodeException('not an image'));
		$this->cacheDocumentsRequest->expects($this->once())->method('endCaching')->with($doc);
		$this->cacheDocumentsRequest->expects($this->never())->method('deleteById');

		try {
			$this->service->cacheRemoteDocument(self::DOC_ID);
			$this->fail('expected CacheDocumentDoesNotExistException');
		} catch (CacheDocumentDoesNotExistException $e) {
			// a non-zero error is what keeps getNotCachedDocuments() from
			// handing the same row back on the next run
			$this->assertSame(DocumentService::ERROR_CONTENT, $doc->getError());
		}
	}

	public function testTheSniffedMimeTypeIsCarriedOntoTheStoredRow(): void {
		$doc = $this->document();
		$doc->setMimeType('');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')
			->willReturnCallback(function (Document $document, string &$mime): void {
				$document->setLocalCopy('local-1');
				$mime = 'image/webp';
			});

		$this->service->cacheRemoteDocument(self::DOC_ID);

		$this->assertSame('image/webp', $doc->getMimeType());
		$this->assertSame('image/webp', $doc->getMediaType());
	}

	/**
	 * What a peer said its attachment was does not survive this instance
	 * reading the bytes: `/media/{uuid}` serves them from this origin, so the
	 * type it states has to be the sniffed one. A peer that declares
	 * `text/html` over a file that sniffs as a GIF used to have that served
	 * back, from here, as a page.
	 */
	public function testThePeersDeclaredMediaTypeIsReplacedByTheSniffedOne(): void {
		$doc = $this->document();
		$doc->setMediaType('text/html');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')
			->willReturnCallback(function (Document $document, string &$mime): void {
				$document->setLocalCopy('local-1');
				$mime = 'image/gif';
			});

		$this->service->cacheRemoteDocument(self::DOC_ID);

		$this->assertSame('image/gif', $doc->getMediaType());
		$this->assertSame('image/gif', $doc->getMimeType());
	}

	/**
	 * A row written with no type never gained one: the caching run only looks
	 * at rows with *no* local copy, so a document that was stored and whose
	 * type was never recorded kept an empty one for good -- clients leave
	 * `type` unset, which is how one decides to show nothing at all.
	 */
	public function testAStoredDocumentWithNoTypeIsGivenTheOneItsBytesSayItIs(): void {
		$document = $this->document('local-1');
		$document->setMediaType('');
		$document->setMimeType('');
		$this->cacheDocumentsRequest->method('getWithoutMediaType')->with(200)->willReturn([$document]);
		$this->cacheService->method('sniffStored')->with('local-1')->willReturn('image/webp');
		$this->cacheDocumentsRequest->expects($this->once())->method('updateMediaType')
			->with($this->identicalTo($document));

		$this->assertSame(1, $this->service->fillMissingMediaTypes(200));
		$this->assertSame('image/webp', $document->getMediaType());
		$this->assertSame('image/webp', $document->getMimeType());
	}

	public function testARowWhoseFileSaysNothingIsLeftForTheNextPass(): void {
		$document = $this->document('local-1');
		$document->setMediaType('');
		$this->cacheDocumentsRequest->method('getWithoutMediaType')->willReturn([$document]);
		$this->cacheService->method('sniffStored')->willReturn('');
		$this->cacheDocumentsRequest->expects($this->never())->method('updateMediaType');

		$this->assertSame(0, $this->service->fillMissingMediaTypes());
	}

	public function testOneUnreadableFileDoesNotStopTheBackfill(): void {
		$bad = $this->document('local-1');
		$bad->setId('https://remote.example/media/bad');
		$good = $this->document('local-2');
		$good->setId('https://remote.example/media/good');
		$this->cacheDocumentsRequest->method('getWithoutMediaType')->willReturn([$bad, $good]);
		$this->cacheService->method('sniffStored')->willReturnCallback(
			static function (string $uuid): string {
				if ($uuid === 'local-1') {
					throw new \RuntimeException('appdata is gone');
				}

				return 'image/png';
			}
		);

		$this->assertSame(1, $this->service->fillMissingMediaTypes());
	}

	public function testOneUnusableRowDoesNotEndTheCachingRun(): void {
		// the poison pill: an Error escaping one row used to abandon caching for
		// every row queued behind it, silently, on every run
		$poison = $this->document();
		$poison->setId('https://remote.example/media/poison');
		$good = $this->document();
		$good->setId('https://remote.example/media/good');
		$this->cacheDocumentsRequest->method('getNotCachedDocuments')->willReturn([$poison, $good]);
		$this->cacheDocumentsRequest->method('getById')->willReturnCallback(
			fn (string $id) => $id === $poison->getId() ? $poison : $good
		);
		$this->cacheService->method('saveRemoteFileToCache')->willReturnCallback(
			function (Document $document, string &$mime): void {
				if ($document->getId() === 'https://remote.example/media/poison') {
					throw new \Error('Call to a member function on null');
				}
				$document->setLocalCopy('local-1');
				$mime = 'image/png';
			}
		);

		$this->assertSame(1, $this->service->manageCacheDocuments());
	}

	public function testGetFromCacheReturnsTheLocalCopyAndItsMimeType(): void {
		$doc = $this->document('local-1');
		$this->cacheDocumentsRequest->method('getById')->with(self::DOC_ID, false)->willReturn($doc);
		$file = $this->createStub(ISimpleFile::class);
		$this->cacheService->expects($this->once())->method('getContentFromCache')->with('local-1')->willReturn($file);

		$mime = '';
		$this->assertSame($file, $this->service->getFromCache(self::DOC_ID, $mime));
		$this->assertSame('image/png', $mime);
	}

	public function testGetResizedFromCacheReturnsTheResizedCopy(): void {
		$doc = $this->document('local-1');
		$this->cacheDocumentsRequest->method('getById')->with(self::DOC_ID, true)->willReturn($doc);
		$file = $this->createStub(ISimpleFile::class);
		$this->cacheService->expects($this->once())->method('getContentFromCache')->with('resized-local-1')->willReturn($file);

		$mime = '';
		$this->assertSame($file, $this->service->getResizedFromCache(self::DOC_ID, $mime, true));
		$this->assertSame('image/png', $mime);
	}

	public function testGetFromCachePropagatesAMissingDocument(): void {
		$this->cacheDocumentsRequest->method('getById')->willThrowException(new CacheDocumentDoesNotExistException());

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->service->getFromCache(self::DOC_ID);
	}

	public function testManageCacheDocumentsSkipsLocalAvatarsAndCountsSuccesses(): void {
		$avatar = $this->document('avatar');
		$header = $this->document('header');
		$pending = $this->document();
		$pending->setId('https://remote.example/media/2');
		$failing = $this->document();
		$failing->setId('https://remote.example/media/3');
		$this->cacheDocumentsRequest->method('getNotCachedDocuments')->willReturn([$avatar, $header, $pending, $failing]);
		$this->cacheDocumentsRequest->method('getById')->willReturnCallback(fn (string $id) => $id === $pending->getId() ? $pending : $failing);
		$this->cacheService->method('saveRemoteFileToCache')->willReturnCallback(function (Document $document) {
			if ($document->getId() === 'https://remote.example/media/3') {
				throw new RequestNetworkException('timeout');
			}
		});

		$this->assertSame(1, $this->service->manageCacheDocuments());
	}

	/**
	 * The caching run is where a picture the inbox could not finish in the
	 * federation timeout gets finished, so its download is not held to that
	 * timeout: curl ends a transfer that is still arriving when it runs out.
	 */
	public function testTheCachingRunDownloadsUnderTheLongerBackgroundTimeout(): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->method('getNotCachedDocuments')->willReturn([$doc]);
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$downloadedInScope = null;
		$this->cacheService->expects($this->once())->method('saveRemoteFileToCache')->willReturnCallback(
			function (Document $document, string &$mime) use (&$downloadedInScope): void {
				$downloadedInScope = $this->inTimeoutScope;
				$document->setLocalCopy('local-1');
				$mime = 'image/png';
			}
		);

		$this->assertSame(1, $this->service->manageCacheDocuments());
		$this->assertTrue($downloadedInScope);
		$this->assertSame(
			[[DocumentService::BACKGROUND_FETCH_TIMEOUT, ConfigService::DEFAULT_REQUEST_TIMEOUT]],
			$this->timeoutScopes
		);
		$this->assertGreaterThan(ConfigService::DEFAULT_REQUEST_TIMEOUT, DocumentService::BACKGROUND_FETCH_TIMEOUT);
		// a download still running must not be started again by the next pass
		$this->assertLessThan(CacheDocumentsRequest::CACHING_TIMEOUT * 60, DocumentService::BACKGROUND_FETCH_TIMEOUT);
	}

	/**
	 * A streamed file is played from the instance that published it, and the
	 * cache job passes over it the way it passes over an avatar.
	 *
	 * Belt and braces, and deliberately so: such a row should never reach the
	 * loop, because `getNotCachedDocuments()` asks for an empty `local_copy`
	 * and `PeerTubeService` names it `stream` before the row is written. This
	 * pins the guard that holds if either of those ever changes, because the
	 * cost is not symmetric -- the file on the other side of it is a video of
	 * hundreds of megabytes.
	 */
	public function testManageCacheDocumentsDoesNotDownloadAStreamedFile(): void {
		$streamed = $this->document(Document::COPY_STREAMED);
		$streamed->setId('https://peertube.example/videos/watch/abc');
		$this->cacheDocumentsRequest->method('getNotCachedDocuments')->willReturn([$streamed]);
		$this->cacheDocumentsRequest->expects($this->never())->method('getById');
		$this->cacheService->expects($this->never())->method('saveRemoteFileToCache');

		$this->assertSame(0, $this->service->manageCacheDocuments());
	}

	private function alice(int $avatarVersion): Person {
		$alice = new Person();
		$alice->setId('https://cloud.example.com/apps/social/@alice');
		$alice->setUserId('alice');
		$alice->setAvatarVersion($avatarVersion);

		return $alice;
	}

	private function avatarUrl(int $version): string {
		$url = 'https://cloud.example.com/avatar/alice/128?v=' . $version;
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('core.avatar.getAvatar', ['userId' => 'alice', 'size' => 128, 'v' => $version])
			->willReturn($url);

		return $url;
	}

	/**
	 * The avatar version is the avatar app's setting, and it is a number.
	 *
	 * Read as text on a Nextcloud that types what it stores, it threw — out of
	 * `createActor()`, so making an account answered 500 and every request
	 * that needed an actor answered 401 after it. Asserted as "asks for the
	 * number", because what the call was is the whole of the bug.
	 */
	public function testTheAvatarVersionIsAskedForAsANumber(): void {
		$this->avatarUrl(0);
		$this->configService->expects($this->once())->method('getUserValueInt')
			->with('version', 'alice', 'avatar')->willReturn(0);
		$this->configService->expects($this->never())->method('getUserValue');
		$cached = new Image();
		$cached->setId('https://cloud.example.com/documents/avatar/existing');
		$this->cacheDocumentsRequest->method('getByUrl')->willReturn($cached);

		$this->assertSame('https://cloud.example.com/documents/avatar/existing', $this->service->cacheLocalAvatarByUsername($this->alice(0)));
	}

	/**
	 * A new avatar is a new address, which is what makes a peer fetch the
	 * picture again, and a document of its own: the same address for every
	 * version made the second one a duplicate that was never saved.
	 */
	public function testANewAvatarIsANewLocalDocumentAtAnAddressOfItsOwn(): void {
		$url = $this->avatarUrl(2);
		$alice = $this->alice(1);
		$this->configService->method('getUserValueInt')->with('version', 'alice', 'avatar')->willReturn(2);
		$this->cacheDocumentsRequest->method('getByUrl')->with($url)->willThrowException(new CacheDocumentDoesNotExistException());
		$icon = new Image();
		$icon->setUrlCloud('https://cloud.example.com');
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromType')->with(Image::TYPE)->willReturn($icon);
		$imageInterface = $this->createMock(ImageInterface::class);
		$imageInterface->expects($this->once())->method('save')->with($this->identicalTo($icon));
		$ap->method('getInterfaceFromType')->with(Image::TYPE)->willReturn($imageInterface);
		AP::set($ap);
		$this->actorsRequest->expects($this->once())->method('update')->with($this->identicalTo($alice));

		$id = $this->service->cacheLocalAvatarByUsername($alice);

		$this->assertSame($icon->getId(), $id);
		$this->assertStringStartsWith('https://cloud.example.com/documents/avatar/', $id);
		$this->assertSame($url, $icon->getUrl());
		$this->assertSame(Document::COPY_LOCAL_AVATAR, $icon->getLocalCopy());
		$this->assertTrue($icon->isLocal(), 'not downloaded from this server\'s own address');
		$this->assertSame(2, $alice->getAvatarVersion());
		$this->assertSame($url, $icon->getMediaUrl($this->urlGenerator), 'shown at its own address');
	}

	public function testAnUnchangedAvatarIsTheDocumentItAlreadyHas(): void {
		$url = $this->avatarUrl(2);
		$this->configService->method('getUserValueInt')->willReturn(2);
		$cached = new Image();
		$cached->setId('https://cloud.example.com/documents/avatar/existing');
		$cached->setMediaType('image/png');
		$this->cacheDocumentsRequest->expects($this->once())->method('getByUrl')->with($url)->willReturn($cached);
		$this->cacheDocumentsRequest->expects($this->never())->method('update');
		$this->actorsRequest->expects($this->never())->method('update');

		$this->assertSame('https://cloud.example.com/documents/avatar/existing', $this->service->cacheLocalAvatarByUsername($this->alice(2)));
	}

	/**
	 * The streaming routes hand the nid over as the string it was in the url.
	 * It has to reach the lookup as one: typed through to an `int` parameter
	 * under strict types it was a TypeError, and every video a 500.
	 */
	public function testAStreamedDocumentIsLookedUpByTheNidTheRouteWasGiven(): void {
		$this->cacheDocumentsRequest->expects($this->once())->method('getByNid')
			->with($this->identicalTo('42'))
			->willReturn($this->document());

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('document is not streamed');

		$this->service->openStreamed('42');
	}

	public function testANidThatIsNotANumberIsNotLookedUp(): void {
		$this->cacheDocumentsRequest->expects($this->never())->method('getByNid');

		foreach (['abc', '12abc', '', '0'] as $nid) {
			try {
				$this->service->openStreamed($nid);
				$this->fail('opened ' . var_export($nid, true));
			} catch (NotFoundException $e) {
				$this->assertSame('invalid document', $e->getMessage());
			}
		}
	}

	// applyMediaAttributes()

	private function upload(int $nid, string $description): Document {
		$document = new Document();
		$document->setNid($nid);
		$document->setLocalCopy('copy' . $nid);
		$document->setMediaType('image/png');
		$document->setDescription($description);

		return $document;
	}

	/**
	 * An edit names the attachments it changes by id. Only the post's own are
	 * touched, only uploads of the editing account are read, and each document
	 * is written first so a later rebuild of the copies keeps the new words.
	 */
	public function testMediaAttributesRewriteThePostsOwnAttachments(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/media/x');
		$changed = (new MediaAttachment())->setId('11')->setDescription('Old words');
		$untouched = (new MediaAttachment())->setId('12')->setDescription('Stays');

		$this->cacheDocumentsRequest->expects($this->once())->method('getFromArray')
			->with(['11'], 'alice')
			->willReturn([$this->upload(11, 'Old words')]);
		$this->cacheDocumentsRequest->expects($this->once())->method('updateDescription')
			->with($this->callback(fn (Document $d): bool => $d->getDescription() === 'New words'));
		$this->cacheDocumentsRequest->expects($this->once())->method('updateFocus');

		$result = $this->service->applyMediaAttributes([$changed, $untouched], 'alice', [
			['id' => '11', 'description' => 'New words', 'focus' => '0.5,-0.25'],
			// not on this post: ignored, as Mastodon ignores it
			['id' => '99', 'description' => 'Somebody else\'s'],
		]);

		$this->assertCount(2, $result);
		$this->assertSame('11', $result[0]->getId());
		$this->assertSame('New words', $result[0]->getDescription());
		$this->assertSame(0.5, $result[0]->getMeta()?->getFocus()?->getX());
		$this->assertSame(-0.25, $result[0]->getMeta()?->getFocus()?->getY());
		$this->assertSame($untouched, $result[1]);
		$this->assertSame('Old words', $changed->getDescription(), 'the copy the revision keeps is not changed in place');
	}

	/** A document of another account is never read, so the copy stays as it was. */
	public function testMediaAttributesForAnUploadOfSomebodyElseChangeNothing(): void {
		$attachment = (new MediaAttachment())->setId('11')->setDescription('Old words');
		$this->cacheDocumentsRequest->method('getFromArray')->willReturn([]);
		$this->cacheDocumentsRequest->expects($this->never())->method('updateDescription');

		$result = $this->service->applyMediaAttributes([$attachment], 'mallory', [['id' => '11', 'description' => 'x']]);

		$this->assertSame([$attachment], $result);
	}

	public function testMediaAttributesNamingNothingOnThePostReadNothing(): void {
		$attachment = (new MediaAttachment())->setId('11');
		$this->cacheDocumentsRequest->expects($this->never())->method('getFromArray');

		$this->assertSame([$attachment], $this->service->applyMediaAttributes([$attachment], 'alice', [['id' => '99']]));
	}
}
