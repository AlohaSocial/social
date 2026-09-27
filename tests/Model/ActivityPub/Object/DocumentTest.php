<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Service\DocumentService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class DocumentTest extends TestCase {
	/** @var IURLGenerator&Stub */
	private $urlGenerator;

	protected function setUp(): void {
		$this->urlGenerator = $this->createStub(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $args): string => 'https://cloud.example.org/' . $route . '/' . $args['uuid']
		);
	}

	public function testConstructorSetsTheType(): void {
		$this->assertSame('Document', (new Document())->getType());
	}

	public function testImportReadsTheMediaTypeAndKeepsAGivenId(): void {
		$document = new Document();

		$document->import([
			'id' => 'https://files.mastodon.social/media/1',
			'type' => 'Document',
			'mediaType' => 'image/jpeg',
			'url' => 'https://files.mastodon.social/media/cat.jpg',
		]);

		$this->assertSame('https://files.mastodon.social/media/1', $document->getId());
		$this->assertSame('image/jpeg', $document->getMediaType());
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $document->getUrl());
	}

	public function testImportGeneratesAnIdUnderTheCloudUrlWhenMissing(): void {
		$document = new Document();
		$document->setUrlCloud('https://cloud.example.org');

		$document->import(['type' => 'Document', 'mediaType' => 'image/png', 'url' => 'https://a.example/x.png']);

		$this->assertMatchesRegularExpression('#^https://cloud\.example\.org/documents/g/[0-9a-f-]{36}$#', $document->getId());
	}

	public function testImportWithoutIdNeedsTheCloudUrl(): void {
		$this->expectException(UrlCloudException::class);

		(new Document())->import(['type' => 'Document', 'url' => 'https://a.example/x.png']);
	}

	public function testImportFromDatabaseReadsTheCacheRow(): void {
		$document = new Document();

		$document->importFromDatabase([
			'nid' => 8,
			'id' => 'https://files.mastodon.social/media/1',
			'type' => 'Document',
			'url' => 'https://files.mastodon.social/media/cat.jpg',
			'account' => 'alice@mastodon.social',
			'public' => 1,
			'error' => 0,
			'local_copy' => 'abc',
			'resized_copy' => 'abc_s',
			'blurhash' => 'UBL_:rOp',
			'description' => 'A cat',
			'media_type' => 'image/jpeg',
			'mime_type' => 'image/jpeg',
			'parent_id' => 'https://mastodon.social/users/alice/statuses/1',
			'caching' => '2024-05-01 12:00:00',
			'meta' => '{"original":{"width":1200,"height":800},"focus":{"x":0,"y":0}}',
		]);

		$this->assertSame(8, $document->getNid());
		$this->assertSame('alice@mastodon.social', $document->getAccount());
		$this->assertTrue($document->isPublic());
		$this->assertSame(0, $document->getError());
		$this->assertSame('abc', $document->getLocalCopy());
		$this->assertSame('abc_s', $document->getResizedCopy());
		$this->assertSame('UBL_:rOp', $document->getBlurHash());
		$this->assertSame('A cat', $document->getDescription());
		$this->assertSame('image/jpeg', $document->getMediaType());
		$this->assertSame('image/jpeg', $document->getMimeType());
		$this->assertSame('https://mastodon.social/users/alice/statuses/1', $document->getParentId());
		$this->assertSame((new \DateTime('2024-05-01 12:00:00'))->getTimestamp(), $document->getCaching());
		$this->assertInstanceOf(AttachmentMeta::class, $document->getMeta());
		$this->assertSame(1200, $document->getMeta()->getOriginal()->getWidth());
	}

	public function testImportFromDatabaseWithoutCachingDateOrMeta(): void {
		$document = new Document();

		$document->importFromDatabase(['id' => 'https://a.example/1', 'type' => 'Document', 'caching' => '', 'public' => 0]);

		$this->assertSame(0, $document->getCaching());
		$this->assertFalse($document->isPublic());
		$this->assertNull($document->getMeta());
	}

	public function testMediaUrlsPointToTheMediaRouteWithTheMimeExtension(): void {
		$document = new Document();
		$document->setLocalCopy('abc')
			->setResizedCopy('abc_s');

		$this->assertSame('https://cloud.example.org/social.MediaApi.mediaOpen/abc.jpeg', $document->getMediaUrl($this->urlGenerator, 'image/jpeg'));
		$this->assertSame('https://cloud.example.org/social.MediaApi.mediaOpen/abc', $document->getMediaUrl($this->urlGenerator));
		$this->assertSame('https://cloud.example.org/social.MediaApi.mediaOpen/abc_s.png', $document->getResizedMediaUrl($this->urlGenerator, 'image/png'));
	}

	public function testConvertToMediaAttachmentBuildsTheMastodonEntity(): void {
		$document = new Document();
		$document->setNid(8)
			->setMediaType('image/jpeg')
			->setUrl('https://files.mastodon.social/media/cat.jpg')
			->setLocalCopy('abc')
			->setResizedCopy('abc_s')
			->setDescription('A cat')
			->setBlurHash('UBL_:rOp')
			->setLocalCopySize(1200, 800);
		$document->setResizedCopySize(600, 400);

		$media = $document->convertToMediaAttachment($this->urlGenerator);

		$this->assertSame('8', $media->getId());
		$this->assertSame('image', $media->getType());
		$this->assertSame('https://cloud.example.org/social.MediaApi.mediaOpen/abc.jpeg', $media->getUrl());
		$this->assertSame('https://cloud.example.org/social.MediaApi.mediaOpen/abc_s.jpeg', $media->getPreviewUrl());
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $media->getRemoteUrl());
		$this->assertSame('A cat', $media->getDescription());
		$this->assertSame('UBL_:rOp', $media->getBlurHash());
		$this->assertSame(ACore::FORMAT_LOCAL, $media->getExportFormat());
		$this->assertSame(1200, $media->getMeta()->getOriginal()->getWidth());
		$this->assertSame('600x400', $media->getMeta()->getSmall()->getSize());
		$this->assertSame(0.0, $media->getMeta()->getFocus()->getX());
		$this->assertSame($media->getMeta(), $document->getMeta(), 'the generated meta is kept on the document');
	}

	public function testConvertToMediaAttachmentOfAVideoUsesTheMediaAsItsOwnPreview(): void {
		$document = new Document();
		$document->setNid(9)
			->setMediaType('video/mp4')
			->setLocalCopy('vid')
			->setResizedCopy('');

		$media = $document->convertToMediaAttachment($this->urlGenerator);

		$this->assertSame('video', $media->getType());
		$this->assertSame('https://cloud.example.org/social.MediaApi.mediaOpen/vid.mp4', $media->getUrl());
		$this->assertSame($media->getUrl(), $media->getPreviewUrl(), 'no resized copy: the media is the preview');
	}

	/**
	 * A PDF is not an "application": it is a file a client cannot render, which
	 * Mastodon calls `unknown` -- and it has no preview of itself, so none is
	 * offered rather than the file being handed back as a picture.
	 */
	public function testAFileConvertsToAnUnknownAttachmentWithoutAPreview(): void {
		$document = new Document();
		$document->setNid(9)
			->setMediaType('application/pdf')
			->setLocalCopy('doc')
			->setResizedCopy('')
			->setDescription('minutes.pdf');

		$media = $document->convertToMediaAttachment($this->urlGenerator);

		$this->assertSame('unknown', $media->getType());
		$this->assertSame('application/pdf', $media->getMediaType());
		$this->assertSame('https://cloud.example.org/social.MediaApi.mediaOpen/doc.pdf', $media->getUrl());
		$this->assertSame('', $media->getPreviewUrl(), 'a file is not its own preview');
		$this->assertSame('minutes.pdf', $media->getDescription());
	}

	public function testConvertToMediaAttachmentWithoutUrlGeneratorLeavesLocalUrlsEmpty(): void {
		$document = new Document();
		$document->setMediaType('video/mp4')
			->setUrl('https://files.mastodon.social/media/clip.mp4');

		$media = $document->convertToMediaAttachment(null, ACore::FORMAT_ACTIVITYPUB);

		$this->assertSame('video', $media->getType());
		$this->assertNull($media->getUrl());
		$this->assertSame('', $media->getPreviewUrl());
		$this->assertSame(ACore::FORMAT_ACTIVITYPUB, $media->getExportFormat());
	}

	public function testJsonSerializeAddsTheDocumentFieldsAndParentIdOnlyWhenComplete(): void {
		$document = new Document();
		$document->setId('https://a.example/1')
			->setMediaType('image/png')
			->setMimeType('image/png')
			->setLocalCopy('abc')
			->setResizedCopy('abc_s')
			->setParentId('https://a.example/n/1');

		$json = $document->jsonSerialize();

		$this->assertSame('Document', $json['type']);
		$this->assertSame('image/png', $json['mediaType']);
		$this->assertSame('image/png', $json['mimeType']);
		$this->assertSame('abc', $json['localCopy']);
		$this->assertSame('abc_s', $json['resizedCopy']);
		$this->assertArrayNotHasKey('parentId', $json);

		$document->setCompleteDetails(true);
		$this->assertSame('https://a.example/n/1', $document->jsonSerialize()['parentId']);
	}

	public function testCopySizesDefaultToZero(): void {
		$document = new Document();

		$this->assertSame([0, 0], $document->getLocalCopySize());
		$this->assertSame([0, 0], $document->getResizedCopySize());
	}

	/**
	 * A video is given its duration when it is stored, before anything knows
	 * its dimensions. The meta block used to be built only when the document
	 * carried none at all, so that one field suppressed the rest and the video
	 * went onto the wire with a running time and no size.
	 */
	public function testAPartialMetaIsFilledInRatherThanSkipped(): void {
		$meta = new \OCA\Social\Model\Client\AttachmentMeta();
		$meta->setDuration(113.0);

		$document = new Document();
		$document->setMediaType('video/mp4');
		$document->setLocalCopy('a0a962e5-7e98-433b-80e2-09106a0b074f');
		$document->setResizedCopy('272c3a32-c626-45a0-b08f-a03e7bb4ab2d');
		$document->setLocalCopySize(1920, 1080);
		$document->setResizedCopySize(1280, 720);
		$document->setMeta($meta);

		$media = $document->convertToMediaAttachment();

		$this->assertSame(113.0, $media->getMeta()?->getDuration());
		$this->assertSame(1920, $media->getMeta()?->getOriginal()?->getWidth());
		$this->assertSame(720, $media->getMeta()?->getSmall()?->getHeight());
	}

	/**
	 * The one this instance was asked about in #2290: an image it refused to
	 * cache was still served as a link to itself, with nothing behind it.
	 */
	public function testAnAttachmentWithNoLocalCopyNamesNoUrlOfItsOwn(): void {
		$document = new Document();
		$document->setNid(11)
			->setMediaType('image/jpeg')
			->setUrl('https://files.mastodon.social/media/cat.jpg')
			->setLocalCopy('')
			->setResizedCopy('')
			->setError(DocumentService::ERROR_SIZE);

		$media = $document->convertToMediaAttachment($this->urlGenerator);

		$this->assertSame('', $media->getUrl(), 'no copy here, so no link to here');
		$this->assertSame('', $media->getPreviewUrl());
		// still said, so a reader can be told where it came from and why
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $media->getRemoteUrl());
		$this->assertSame(DocumentService::ERROR_SIZE, $media->getCacheError());
	}

	/**
	 * The same holds before anything has been tried: an attachment waiting for
	 * the cron has no copy either, and a link to one would 404 just the same.
	 */
	public function testAnAttachmentNotYetFetchedNamesNoUrlEither(): void {
		$document = new Document();
		$document->setNid(12)
			->setMediaType('image/png')
			->setUrl('https://files.mastodon.social/media/dog.png')
			->setLocalCopy('');

		$media = $document->convertToMediaAttachment($this->urlGenerator);

		$this->assertSame('', $media->getUrl());
		$this->assertSame(0, $media->getCacheError(), 'nothing was refused; it has not been tried');
	}

	/** A copy that does exist is named exactly as before. */
	public function testAnAttachmentWithACopyIsUnchanged(): void {
		$document = new Document();
		$document->setNid(13)
			->setMediaType('image/jpeg')
			->setUrl('https://files.mastodon.social/media/cat.jpg')
			->setLocalCopy('abc');

		$media = $document->convertToMediaAttachment($this->urlGenerator);

		$this->assertSame('https://cloud.example.org/social.MediaApi.mediaOpen/abc.jpeg', $media->getUrl());
	}
}
