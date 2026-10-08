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
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCP\Accounts\IAccountManager;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IAvatar;
use OCP\IAvatarManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PictureServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private static string $noise = '';
	/** @var BlobRef[] */
	private array $blobs = [];
	private string $reencoded = '';
	/** @var string[] what was stored as a document of its own */
	private array $stored = [];
	private bool $customAvatar = true;
	private bool $avatarPublished = true;

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
		$blobRequest->method('get')->willReturnCallback(fn (string $did, string $cid): ?BlobRef => array_values(array_filter($this->blobs, static fn (BlobRef $b): bool => $b->cid->toString() === $cid))[0] ?? null);
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
			$this->stored[] = $this->reencoded;
			$copy = new Document();
			$copy->setId('https://social.test/documents/copy');

			return $copy;
		});

		$avatar = $this->createMock(IAvatar::class);
		$avatar->method('getFile')->with(-1)->willReturn($file);
		$avatar->method('isCustomAvatar')->willReturnCallback(fn (): bool => $this->customAvatar);
		$avatars = $this->createMock(IAvatarManager::class);
		$avatars->method('getAvatar')->with('alice')->willReturn($avatar);
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getFromId')->with('https://social.test/@alice')->willReturn((new Person())->setUserId('alice'));
		$accounts->method('mayPublish')->with($this->anything(), IAccountManager::PROPERTY_AVATAR)->willReturnCallback(fn (): bool => $this->avatarPublished);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(AccountService::class)->willReturn($accounts);

		return new PictureService($blobRequest, $this->createMock(AtprotoRepoRequest::class), $cache, $documents, $avatars, new NullLogger(), $container);
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

	private static function alice(): Person {
		$alice = new Person();
		$alice->setUserId('alice');
		$alice->setLocal(true);

		return $alice;
	}

	public function testALocalAvatarIsTheNextcloudAvatarStoredOnce(): void {
		$pictures = $this->pictures(self::png());

		$blob = $pictures->avatarBlob($this->identity(), self::alice());
		$again = $pictures->avatarBlob($this->identity(), self::alice());

		$this->assertSame(['https://social.test/documents/copy', 'image/png', 4, 3], [$blob['blob']->documentId ?? null, $blob['blob']->mime ?? null, $blob['width'] ?? null, $blob['height'] ?? null]);
		$this->assertTrue(Cid::forRaw(self::png())->equals($blob['blob']->cid));
		$this->assertSame([self::png()], $this->stored, 'stored once, the second time known by its bytes');
		$this->assertTrue($again['blob']->cid->equals($blob['blob']->cid));
	}

	public function testAnActorReadFromTheCacheIsFoundItsAccount(): void {
		$cached = new Person();
		$cached->setId('https://social.test/@alice');
		$cached->setLocal(true);

		$this->assertNotNull($this->pictures(self::png())->avatarBlob($this->identity(), $cached));
		$this->assertSame('', $cached->getUserId(), 'the actor handed in is left as it was');
	}

	public function testAGeneratedAvatarOrOneKeptFromOtherServersIsNotPublished(): void {
		$this->customAvatar = false;
		$this->assertNull($this->pictures(self::png())->avatarBlob($this->identity(), self::alice()), 'generated');

		$this->customAvatar = true;
		$this->avatarPublished = false;
		$this->assertNull($this->pictures(self::png())->avatarBlob($this->identity(), self::alice()), 'kept from other servers');
		$this->assertSame([], $this->stored);
	}

	public function testACopyIsStoredOnceHoweverOftenItIsAskedFor(): void {
		$pictures = $this->pictures(self::noise());
		$pictures->blobFor($this->identity(), new Person(), $this->document(), 1000000);
		$pictures->blobFor($this->identity(), new Person(), $this->document(), 1000000);

		$this->assertCount(1, $this->stored);
	}

	/** A four-by-three PNG. */
	private static function png(): string {
		$image = imagecreatetruecolor(4, 3);
		ob_start();
		imagepng($image);

		return (string)ob_get_clean();
	}

	public function testAPictureOfATypeTheProfileDoesNotTakeIsMadeAJpegForIt(): void {
		$image = imagecreatetruecolor(40, 40);
		ob_start();
		imagewebp($image);
		$webp = (string)ob_get_clean();
		$pictures = $this->pictures($webp);
		$document = $this->document();
		$document->setMimeType('image/webp');
		$pictures->blobFor($this->identity(), new Person(), $document);

		$blob = $pictures->blobFor($this->identity(), new Person(), $document, PictureService::PROFILE_MAX_BYTES, PictureService::PROFILE_TYPES)['blob'] ?? null;

		$this->assertSame('image/jpeg', $blob?->mime, 'not the WebP blob a post may use');
	}

	private function identity(): Identity {
		return new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
	}
}
