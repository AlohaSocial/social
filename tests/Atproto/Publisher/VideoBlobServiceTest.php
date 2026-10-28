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
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCP\Files\SimpleFS\ISimpleFile;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class VideoBlobServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private string $bytes = 'a short film';
	/** @var AtprotoBlobRequest&MockObject */
	private AtprotoBlobRequest $blobRequest;
	private Identity $alice;
	private Document $video;
	private VideoBlobService $videos;
	private AtprotoRepoRequest $repoRequest;
	private bool $blobInserted = true;

	protected function setUp(): void {
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->video = new Document();
		$this->video->setId('https://social.test/documents/local/7');
		$this->video->setMimeType('video/mp4');
		$this->video->setLocalCopy('uuid-7');
		$this->blobRequest = $this->createMock(AtprotoBlobRequest::class);
		$this->blobRequest->method('put')->willReturnCallback(fn (): bool => $this->blobInserted);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getSize')->willReturnCallback(fn (): int => strlen($this->bytes));
		$file->method('read')->willReturnCallback(function () {
			$stream = fopen('php://memory', 'w+b');
			fwrite($stream, $this->bytes);
			rewind($stream);

			return $stream;
		});
		$cache = $this->createMock(CacheDocumentService::class);
		$cache->method('getFromUuid')->with('uuid-7')->willReturn($file);
		$documents = $this->createMock(DocumentService::class);
		$documents->method('getDocumentById')->willReturn($this->video);
		$this->repoRequest = $this->createMock(AtprotoRepoRequest::class);
		$this->videos = new VideoBlobService($this->blobRequest, $this->repoRequest, $cache, $documents, new NullLogger());
	}

	public function testAConcurrentDuplicateRegistrationDoesNotCountBlobBytesAgain(): void {
		$this->blobInserted = false;
		$this->repoRequest->expects($this->never())->method('addBlobBytes');

		$blob = $this->videos->blobFor($this->alice, $this->video);

		$this->assertNotNull($blob);
		$this->assertTrue(Cid::forRaw($this->bytes)->equals($blob->cid));
	}

	public function testAVideoIsHashedAsItIsReadAndKeptAsItIs(): void {
		$this->repoRequest->expects($this->once())->method('addBlobBytes')->with(self::DID, strlen($this->bytes));
		$this->blobRequest->expects($this->once())->method('put')->with($this->callback(fn (BlobRef $blob): bool => $blob->cid->equals(Cid::forRaw($this->bytes))
			&& $blob->documentId === $this->video->getId() && $blob->mime === 'video/mp4' && $blob->size === strlen($this->bytes)));

		$blob = $this->videos->blobFor($this->alice, $this->video);

		$this->assertSame(Cid::forRaw($this->bytes)->toString(), $blob?->cid->toString());
	}

	public function testOnlyAnMp4WithinBlueskysLimitIsABlob(): void {
		$this->blobRequest->expects($this->never())->method('put');

		$this->video->setMimeType('video/webm');
		$this->assertNull($this->videos->blobFor($this->alice, $this->video));
	}

	public function testABlobIsServedAsAStreamWhileItIsStillItsFile(): void {
		$blob = new BlobRef(self::DID, Cid::forRaw($this->bytes), $this->video->getId(), 'video/mp4', strlen($this->bytes));

		$opened = $this->videos->open($blob);
		$this->assertSame($this->bytes, stream_get_contents($opened['stream']));
		$this->assertSame(strlen($this->bytes), $opened['size']);

		$this->bytes = 'replaced by a transcode';
		$this->expectException(AtprotoException::class);
		$this->videos->open($blob);
	}

	public function testACidFromADigestIsTheCidOfTheBytes(): void {
		$this->assertTrue(Cid::forRawDigest(hash('sha256', 'abc', true))->equals(Cid::forRaw('abc')));
		$this->expectException(\InvalidArgumentException::class);
		Cid::forRawDigest('short');
	}
}
