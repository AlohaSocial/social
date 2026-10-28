<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\Client\BlobUploadService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\DocumentService;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class BlobUploadServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private string $path;
	private Identity $identity;
	private Person $owner;
	private DocumentService $documents;
	private AtprotoBlobRequest $blobs;
	private AtprotoRepoRequest $repos;
	private IDBConnection $connection;
	private BlobUploadService $uploads;

	protected function setUp(): void {
		$this->path = tempnam(sys_get_temp_dir(), 'social-original-');
		file_put_contents($this->path, "original\0bytes with metadata");
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->owner = (new Person())->setId($this->identity->actorId);
		$this->documents = $this->createMock(DocumentService::class);
		$this->blobs = $this->createMock(AtprotoBlobRequest::class);
		$this->repos = $this->createMock(AtprotoRepoRequest::class);
		$this->connection = $this->createMock(IDBConnection::class);
		$this->uploads = new BlobUploadService($this->documents, $this->blobs, $this->repos, $this->connection);
	}

	protected function tearDown(): void {
		unlink($this->path);
	}

	public static function types(): array {
		return ['image' => ['image/jpeg'], 'video' => ['video/mp4'], 'document' => ['application/pdf']];
	}

	#[DataProvider('types')]
	public function testOriginalBytesAreHashedWithoutPublishingConversion(string $mime): void {
		$document = (new Document())->setId('https://social.test/documents/original')->setMimeType($mime);
		$this->documents->expects($this->once())->method('storeAsIs')->with($this->owner, $this->path, false)->willReturn($document);
		$this->documents->expects($this->never())->method('storeLocalAttachment');
		$this->blobs->method('get')->willReturn(null);
		$this->blobs->expects($this->once())->method('put')->willReturn(true);
		$this->repos->expects($this->once())->method('addBlobBytes')->with(self::DID, filesize($this->path));
		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('commit');
		$blob = $this->uploads->store($this->identity, $this->owner, $this->path);
		$this->assertSame(Cid::forRaw(file_get_contents($this->path))->toString(), $blob->cid->toString());
		$this->assertSame($mime, $blob->mime);
		$this->assertSame(filesize($this->path), $blob->size);
		$this->assertSame($document->getId(), $blob->documentId);
	}

	public function testAnExistingBlobIsNotStoredOrChargedAgain(): void {
		$known = new BlobRef(self::DID, Cid::forRaw(file_get_contents($this->path)), 'existing', 'image/jpeg', filesize($this->path));
		$this->blobs->method('get')->willReturn($known);
		$this->documents->expects($this->never())->method('storeAsIs');
		$this->blobs->expects($this->never())->method('put');
		$this->repos->expects($this->never())->method('addBlobBytes');
		$this->connection->expects($this->never())->method('beginTransaction');
		$this->assertSame($known, $this->uploads->store($this->identity, $this->owner, $this->path));
	}

	public function testConcurrentRegistrationKeepsTheWinningDocumentAndAccounting(): void {
		$known = new BlobRef(self::DID, Cid::forRaw(file_get_contents($this->path)), 'winner', 'image/jpeg', filesize($this->path));
		$this->blobs->method('get')->willReturnOnConsecutiveCalls(null, $known);
		$this->documents->method('storeAsIs')->willReturn((new Document())->setId('other-copy')->setMimeType('image/jpeg'));
		$this->blobs->method('put')->willReturn(false);
		$this->repos->expects($this->never())->method('addBlobBytes');
		$this->assertSame($known, $this->uploads->store($this->identity, $this->owner, $this->path));
	}

	public function testStoragePolicyFailuresDoNotRegisterABlob(): void {
		$this->documents->method('storeAsIs')->willThrowException(new RuntimeException('quota exceeded'));
		$this->blobs->expects($this->never())->method('put');
		$this->connection->expects($this->never())->method('beginTransaction');
		$this->expectException(RuntimeException::class);
		$this->uploads->store($this->identity, $this->owner, $this->path);
	}

	public function testAnAccountingFailureRollsBackTheBlobRegistration(): void {
		$this->documents->method('storeAsIs')->willReturn((new Document())->setId('copy')->setMimeType('image/jpeg'));
		$this->blobs->method('put')->willReturn(true);
		$this->repos->method('addBlobBytes')->willThrowException(new RuntimeException('database failure'));
		$this->connection->expects($this->once())->method('rollBack');
		$this->connection->expects($this->never())->method('commit');
		$this->expectException(RuntimeException::class);
		$this->uploads->store($this->identity, $this->owner, $this->path);
	}
}
