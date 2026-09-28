<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What a post's stored attachment copy becomes once the caching cron (or
 * `occ social:media:retry`) has fetched a remote picture the inbox could not.
 *
 * The copy in the `attachments` column is the client format, keyed by the
 * document's nid, and it is what every reader is served; the refresh has to
 * find the attachment by that key and write it back in that format.
 */
class StreamAttachmentCopyRefreshTest extends TestCase {
	private const ORIGIN = 'https://social.b.example/index.php/apps/social/media/0bef598f-ef65-4857-a108-b3766689b78c.jpeg';

	private IURLGenerator $urlGenerator;

	protected function setUp(): void {
		$this->urlGenerator = $this->createStub(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args): string => 'https://cloud.example.org/media/' . ($args['uuid'] ?? '')
		);
		\OC::$server->register(IURLGenerator::class, $this->urlGenerator);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function remoteDocument(int $nid, string $url): Document {
		$document = new Document();
		$document->setId('https://cloud.example.org/documents/g/' . $nid);
		$document->setNid($nid);
		$document->setUrl($url);
		$document->setMediaType('image/jpeg');
		$document->setParentId('https://social.b.example/index.php/apps/social/@bob/1');

		return $document;
	}

	/**
	 * @param array<array<string, mixed>> $stored
	 * @return array<array<string, mixed>>
	 */
	private function refresh(Document $document, array $stored): array {
		$streamRequest = (new ReflectionClass(StreamRequest::class))->newInstanceWithoutConstructor();
		$property = new \ReflectionProperty(StreamRequest::class, 'urlGenerator');
		$property->setValue($streamRequest, $this->urlGenerator);

		$refreshed = (new \ReflectionMethod(StreamRequest::class, 'updateAttachmentInList'))
			->invoke($streamRequest, $document, $stored);

		return json_decode((string)json_encode($refreshed, JSON_UNESCAPED_SLASHES), true);
	}

	public function testTheFetchedPictureReplacesTheEmptyCopyItWasStoredWith(): void {
		// what the inbox wrote when the first fetch failed: no local copy yet
		$failed = $this->remoteDocument(7, self::ORIGIN);
		$other = $this->remoteDocument(8, 'https://social.b.example/index.php/apps/social/media/other.jpeg');
		$other->setLocalCopy('11111111-2222-4333-8444-555555555555');
		$other->setResizedCopy('66666666-7777-4888-9999-000000000000');
		// as the column holds them: written by json_encode, read back by json_decode
		$stored = json_decode((string)json_encode([
			$failed->convertToMediaAttachment($this->urlGenerator)->asLocal(),
			$other->convertToMediaAttachment($this->urlGenerator)->asLocal(),
		], JSON_UNESCAPED_SLASHES), true);

		// and what the cron made of it afterwards
		$cached = $this->remoteDocument(7, self::ORIGIN);
		$cached->setLocalCopy('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
		$cached->setResizedCopy('ffffffff-0000-4111-8222-333333333333');

		$refreshed = $this->refresh($cached, $stored);

		$this->assertCount(2, $refreshed);
		$first = (new MediaAttachment())->import($refreshed[0]);
		$this->assertSame('7', $first->getId());
		$this->assertSame('image', $first->getType());
		$this->assertSame('https://cloud.example.org/media/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee.jpeg', $first->getUrl());
		$this->assertSame('https://cloud.example.org/media/ffffffff-0000-4111-8222-333333333333.jpeg', $first->getPreviewUrl());
		$this->assertSame(self::ORIGIN, $first->getRemoteUrl());

		// the attachment that was not refreshed is left exactly as it was stored
		$this->assertSame($stored[1], $refreshed[1]);
	}

	/**
	 * A converted video has a new file and a new type. The copy is what the
	 * post is served and federated from, so it has to name both: left alone
	 * it named the original, which the conversion deletes.
	 */
	public function testAConvertedVideoIsNamedByItsNewFileAndType(): void {
		$original = new Document();
		$original->setId('https://cloud.example.org/documents/local/9');
		$original->setNid(9);
		$original->setMediaType('video/quicktime');
		$original->setLocalCopy('11111111-2222-4333-8444-555555555555');
		$stored = json_decode((string)json_encode([
			$original->convertToMediaAttachment($this->urlGenerator)->asLocal(),
		], JSON_UNESCAPED_SLASHES), true);

		$converted = clone $original;
		$converted->setMediaType('video/mp4');
		$converted->setLocalCopy('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');

		$refreshed = $this->refresh($converted, $stored);

		$attachment = (new MediaAttachment())->import($refreshed[0]);
		$this->assertSame('https://cloud.example.org/media/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee.mp4', $attachment->getUrl());
		$this->assertSame('video/mp4', $attachment->getMediaType());
		$this->assertSame('video/mp4', $attachment->asDocument()['mediaType']);
	}
}
