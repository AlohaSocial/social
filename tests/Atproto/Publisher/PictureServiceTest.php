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
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCP\Files\SimpleFS\ISimpleFile;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PictureServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private static string $noise = '';
	/** @var BlobRef[] */
	private array $blobs = [];
	private string $reencoded = '';

	/** A PNG of noise, which no encoder makes small: between a card's limit and a post picture's. */
	private static function noise(): string {
		if (self::$noise === '') {
			$image = imagecreatetruecolor(700, 700);
			mt_srand(7);
			for ($y = 0; $y < 700; $y++) {
				for ($x = 0; $x < 700; $x++) {
					imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
				}
			}
			ob_start();
			imagepng($image, null, 0);
			self::$noise = (string)ob_get_clean();
		}

		return self::$noise;
	}

	private function pictures(string $bytes): PictureService {
		$blobRequest = $this->createMock(AtprotoBlobRequest::class);
		$blobRequest->method('getByDocument')->willReturnCallback(fn (string $did, string $documentId): ?BlobRef => array_values(array_filter($this->blobs, static fn (BlobRef $b): bool => $b->documentId === $documentId))[0] ?? null);
		$blobRequest->method('put')->willReturnCallback(function (BlobRef $blob): void {
			$this->blobs[] = $blob;
		});
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn($bytes);
		$cache = $this->createMock(CacheDocumentService::class);
		$cache->method('getFromUuid')->willReturn($file);
		$documents = $this->createMock(DocumentService::class);
		$documents->method('storeLocalAttachment')->willReturnCallback(function (Person $owner, string $path): Document {
			$this->reencoded = (string)file_get_contents($path);
			$copy = new Document();
			$copy->setId('https://social.test/documents/copy');

			return $copy;
		});

		return new PictureService($blobRequest, $this->createMock(AtprotoRepoRequest::class), $cache, $documents, new NullLogger());
	}

	private function document(): Document {
		$document = new Document();
		$document->setId('https://social.test/documents/original');
		$document->setLocalCopy('5f0c3e4a-1d2b-4c3d-8e9f-0a1b2c3d4e5f');
		$document->setMimeType('image/png');

		return $document;
	}

	public function testAPictureThatFitsIsUsedAsItIs(): void {
		$this->assertGreaterThan(1000000, strlen(self::noise()));
		$this->assertLessThan(PictureService::MAX_BYTES, strlen(self::noise()));

		$blob = $this->pictures(self::noise())->blobFor($this->identity(), new Person(), $this->document())['blob'] ?? null;

		$this->assertSame(['https://social.test/documents/original', 'image/png'], [$blob?->documentId, $blob?->mime]);
		$this->assertTrue(Cid::forRaw(self::noise())->equals($blob->cid));
	}

	public function testALowerLimitMakesASmallerCopyAndDoesNotReuseTheLargerBlob(): void {
		$pictures = $this->pictures(self::noise());
		$pictures->blobFor($this->identity(), new Person(), $this->document());

		$blob = $pictures->blobFor($this->identity(), new Person(), $this->document(), 1000000)['blob'] ?? null;

		$this->assertSame(['https://social.test/documents/copy', 'image/jpeg'], [$blob?->documentId, $blob?->mime]);
		$this->assertLessThanOrEqual(1000000, $blob->size);
		$this->assertTrue(Cid::forRaw($this->reencoded)->equals($blob->cid), 'the blob is the stored copy');
	}

	private function identity(): Identity {
		return new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
	}
}
