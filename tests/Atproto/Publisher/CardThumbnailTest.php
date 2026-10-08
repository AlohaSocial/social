<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\CardThumbnail;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\DocumentService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class CardThumbnailTest extends TestCase {
	private const IMAGE = 'https://nextcloud.com/hub.jpg';

	/** @var array<string, Document> the cached documents, by URL */
	private array $cached = [];
	/** @var DocumentInterface&MockObject */
	private DocumentInterface $documentInterface;
	/** @var DocumentService&MockObject */
	private DocumentService $documents;
	/** @var PictureService&MockObject */
	private PictureService $pictures;
	private CardThumbnail $thumbnails;
	private Identity $identity;

	protected function setUp(): void {
		$this->identity = new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$requests = $this->createMock(CacheDocumentsRequest::class);
		$requests->method('getByUrl')->willReturnCallback(fn (string $url): Document => $this->cached[$url] ?? throw new CacheDocumentDoesNotExistException());
		$this->documentInterface = $this->createMock(DocumentInterface::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->pictures = $this->createMock(PictureService::class);
		$this->thumbnails = new CardThumbnail($requests, $this->documentInterface, $this->documents, $this->pictures, new NullLogger());
	}

	private function card(string $image = self::IMAGE): StreamCard {
		$card = new StreamCard('https://social.test/@alice/1', 'https://nextcloud.com/blog/');
		$card->setImage($image);

		return $card;
	}

	private function copy(string $localCopy = 'uuid', int $error = 0): Document {
		$document = new Document();
		$document->setId(self::IMAGE);
		$document->setUrl(self::IMAGE);
		$document->setLocalCopy($localCopy);
		$document->setError($error);

		return $document;
	}

	private function blob(): BlobRef {
		return new BlobRef('did:plc:alice', Cid::forRaw('hub'), self::IMAGE, 'image/jpeg', 3);
	}

	public function testThePicturesFetchedOnceAsAnyRemoteDocumentAndFitToACard(): void {
		$this->documentInterface->expects($this->once())->method('save')->with($this->callback(function (Document $document): bool {
			$this->assertSame([self::IMAGE, self::IMAGE, 'https://social.test/@alice/1', false], [$document->getId(), $document->getUrl(), $document->getParentId(), $document->isLocal()]);
			$this->cached[self::IMAGE] = $this->copy();

			return true;
		}));
		$this->pictures->expects($this->exactly(2))->method('blobFor')->with($this->identity, $this->anything(), $this->anything(), CardThumbnail::MAX_BYTES)->willReturn(['blob' => $this->blob(), 'width' => 1, 'height' => 1]);

		$this->assertEquals($this->blob(), $this->thumbnails->blobFor($this->identity, new Person(), $this->card()));
		$this->assertEquals($this->blob(), $this->thumbnails->blobFor($this->identity, new Person(), $this->card()), 'and not fetched again');
	}

	public function testAPictureThatCannotBeHadMeansACardWithout(): void {
		$this->pictures->expects($this->never())->method('blobFor');
		$this->assertNull($this->thumbnails->blobFor($this->identity, new Person(), $this->card('')));
		$this->assertNull($this->thumbnails->blobFor($this->identity, new Person(), $this->card('javascript:alert(1)')));

		$this->cached[self::IMAGE] = $this->copy('', 3);
		$this->documents->expects($this->never())->method('cacheRemoteDocument');
		$this->assertNull($this->thumbnails->blobFor($this->identity, new Person(), $this->card()), 'a fetch that failed for good is not tried again');
	}

	public function testAFetchThatMayWorkLaterIsTriedAsTheCacheTriesAny(): void {
		$this->cached[self::IMAGE] = $this->copy('');
		$this->documents->expects($this->once())->method('cacheRemoteDocument')->with(self::IMAGE, true)->willReturn($this->copy());
		$this->pictures->method('blobFor')->willReturn(['blob' => $this->blob(), 'width' => 1, 'height' => 1]);

		$this->assertNotNull($this->thumbnails->blobFor($this->identity, new Person(), $this->card()));
	}
}
