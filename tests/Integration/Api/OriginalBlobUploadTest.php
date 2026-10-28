<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\WriteService;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Xrpc\XrpcBytes;
use OCA\Social\Atproto\Xrpc\XrpcService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

class OriginalBlobUploadTest extends ApiTestCase {
	private const DID = 'did:plc:blobuploadtestaaaaaaaaaa';
	/** @var list<Document> */
	private array $documents = [];

	protected function tearDown(): void {
		foreach ($this->documents as $document) {
			foreach (array_unique([$document->getLocalCopy(), $document->getResizedCopy()]) as $uuid) {
				if ($uuid !== '') {
					Server::get(CacheDocumentService::class)->getFromUuid($uuid)->delete();
				}
			}
			Server::get(CacheDocumentsRequest::class)->deleteById($document->getId());
		}
		Server::get(AtprotoBlobRequest::class)->deleteByDid(self::DID);
		Server::get(AtprotoRepoRequest::class)->deleteRepository(self::DID);
		Server::get(AtprotoIdentityRequest::class)->deleteByDid(self::DID);
		parent::tearDown();
	}

	public static function mediaTypes(): array {
		return ['PNG with metadata' => ['image/png'], 'PDF for a non-Bluesky app' => ['application/pdf']];
	}

	#[DataProvider('mediaTypes')]
	public function testUploadAndReadbackPreserveOriginalBytesAndDeduplicateStorage(string $mime): void {
		$owner = $this->account('alice');
		$identities = Server::get(AtprotoIdentityRequest::class);
		$identities->create($owner->getId(), self::DID, 'blobupload.nextcloud.test', 'sealed', 'did:key:zQ3sh', '', Identity::STATE_ACTIVE);
		$identity = $identities->getByDid(self::DID);
		Server::get(RepositoryService::class)->import(self::DID, PrivateKey::generate(Curve::K256), []);
		$session = new ClientSession(self::PREFIX . 'alice', $identity, 'fixture');
		$bytes = $mime === 'image/png' ? $this->pngWithMetadata() : "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
		$path = tempnam(sys_get_temp_dir(), 'social-upload-');
		file_put_contents($path, $bytes);
		$config = Server::get(ConfigService::class);
		$enabledBefore = $config->getAppValue(ConfigService::ATPROTO_ENABLED);
		$config->setAppValue(ConfigService::ATPROTO_ENABLED, '1');
		try {
			$writes = Server::get(WriteService::class);
			$answer = $writes->upload($session, $path, $mime);
			$cid = $answer['blob']['ref']['$link'];
			$this->assertSame(Cid::forRaw($bytes)->toString(), $cid);
			$this->assertSame($mime, $answer['blob']['mimeType']);
			$this->assertSame(strlen($bytes), $answer['blob']['size']);
			$blob = Server::get(AtprotoBlobRequest::class)->get(self::DID, $cid);
			$this->assertNotNull($blob);
			$document = Server::get(DocumentService::class)->getDocumentById($blob->documentId);
			$this->documents[] = $document;
			$this->assertFalse($document->isPublic(), 'an upload does not publish the ordinary Social media route');
			$read = Server::get(XrpcService::class)->query('com.atproto.sync.getBlob', ['did' => self::DID, 'cid' => $cid]);
			$this->assertInstanceOf(XrpcBytes::class, $read);
			$this->assertSame($bytes, $read->bytes);
			$this->assertSame($answer, $writes->upload($session, $path, $mime));
			$this->assertSame(strlen($bytes), Server::get(AtprotoRepoRequest::class)->getHead(self::DID)?->blobBytes);
			$this->assertSame($document->getId(), Server::get(AtprotoBlobRequest::class)->get(self::DID, $cid)?->documentId);
			if ($mime === 'image/png') {
				$ordinary = Server::get(DocumentService::class)->storeLocalAttachment($owner, $path);
				$this->documents[] = $ordinary;
				$processed = Server::get(CacheDocumentService::class)->getFromUuid($ordinary->getLocalCopy())->getContent();
				$this->assertStringNotContainsString('original metadata', $processed, 'normal Social uploads still remove image metadata');
			}
		} finally {
			unlink($path);
			$config->setAppValue(ConfigService::ATPROTO_ENABLED, $enabledBefore);
		}
	}

	private function pngWithMetadata(): string {
		$image = imagecreatetruecolor(4, 4);
		ob_start();
		imagepng($image);
		$png = (string)ob_get_clean();
		$text = "Comment\0original metadata";
		$chunk = 'tEXt' . $text;
		return substr($png, 0, -12) . pack('N', strlen($text)) . $chunk . pack('N', crc32($chunk)) . substr($png, -12);
	}
}
