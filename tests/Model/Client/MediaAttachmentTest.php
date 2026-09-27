<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class MediaAttachmentTest extends TestCase {
	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/** Registers a URL generator that builds links for this instance. */
	private function withUrlGenerator(): void {
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $args): string => isset($args['nid'])
				? 'https://cloud.example.org/media/stream/' . $args['nid']
				: 'https://cloud.example.org/media/' . ($args['uuid'] ?? '')
		);
		\OC::$server->register(IURLGenerator::class, $urlGenerator);
	}

	private function mastodonAttachment(): array {
		return [
			'id' => '55',
			'type' => 'image',
			'url' => 'https://files.mastodon.social/media/cat.jpg',
			'preview_url' => 'https://files.mastodon.social/media/small/cat.jpg',
			'remote_url' => 'https://remote.example/media/cat.jpg',
			'text_url' => null,
			'description' => 'A cat',
			'blurhash' => 'UBL_:rOp',
			'meta' => [
				'original' => ['width' => 1200, 'height' => 800, 'size' => '1200x800'],
				'small' => ['width' => 600, 'height' => 400, 'size' => '600x400'],
				'focus' => ['x' => 0, 'y' => 0],
			],
		];
	}

	public function testImportReadsAMastodonMediaAttachment(): void {
		$media = new MediaAttachment();

		$result = $media->import($this->mastodonAttachment());

		$this->assertSame($media, $result);
		$this->assertSame('55', $media->getId());
		$this->assertSame('image', $media->getType());
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $media->getUrl());
		$this->assertSame('https://files.mastodon.social/media/small/cat.jpg', $media->getPreviewUrl());
		$this->assertSame('https://remote.example/media/cat.jpg', $media->getRemoteUrl());
		$this->assertSame('A cat', $media->getDescription());
		$this->assertSame('UBL_:rOp', $media->getBlurHash());
		$this->assertInstanceOf(AttachmentMeta::class, $media->getMeta());
		$this->assertSame(1200, $media->getMeta()->getOriginal()->getWidth());
		$this->assertSame(400, $media->getMeta()->getSmall()->getHeight());
	}

	/**
	 * Mastodon sends every one of these keys on every attachment, so a client
	 * is entitled to declare them non-optional. They used to be run through
	 * `array_filter()` with no callback, which drops every *falsy* value: an
	 * attachment with no alt text lost `description`, one with no preview lost
	 * `preview_url`, and the first attachment ever cached (id `"0"`) lost its
	 * `id` — and the status carrying it then failed to decode too.
	 */
	public function testAsLocalNullsEmptyFieldsRatherThanDroppingThem(): void {
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());
		$media->setDescription('');

		$local = $media->asLocal();

		$this->assertSame('55', $local['id']);
		$this->assertSame('image', $local['type']);
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $local['url']);
		$this->assertSame('https://remote.example/media/cat.jpg', $local['remote_url']);
		$this->assertNull($local['cache_error']);
		$this->assertSame('UBL_:rOp', $local['blurhash']);
		$this->assertArrayHasKey('description', $local);
		$this->assertNull($local['description']);
		$this->assertSame(
			['id', 'type', 'media_type', 'url', 'preview_url', 'hls_url', 'remote_url', 'cache_error', 'meta', 'description', 'blurhash'],
			array_keys($local)
		);
	}

	public function testAsLocalExplainsARejectedRemoteCacheWithoutItsRemoteErrorText(): void {
		$media = (new MediaAttachment())->setCacheError(1);

		$this->assertSame(1, $media->asLocal()['cache_error']);
	}

	/**
	 * The stored row is what a post is served from for ever after, and the
	 * Document it is served as has to state its mime: Pixelfed refuses an
	 * attachment without one. Mastodon's entity has no such key, so it is
	 * this app's own, and a row from before it was written gets a guess.
	 */
	public function testTheMimeSurvivesTheStoredRow(): void {
		$media = (new MediaAttachment())->import($this->mastodonAttachment());
		$media->setMediaType('image/png');

		$stored = $media->asLocal();
		$this->assertSame('image/png', $stored['media_type']);

		$again = (new MediaAttachment())->import($stored);
		$this->assertSame('image/png', $again->getMediaType());
		$this->assertSame('image/png', $again->asDocument()['mediaType']);
	}

	public function testARowWithoutTheMimeGetsAGuessRatherThanNothing(): void {
		// a Mastodon entity, or a row written before media_type was stored
		$media = (new MediaAttachment())->import($this->mastodonAttachment());
		$this->assertSame('image/jpeg', $media->getMediaType(), 'from the .jpg');

		$this->assertSame('video/quicktime', MediaAttachment::guessMediaType('video', 'https://x.example/a/b.MOV?x=1'));
		$this->assertSame('image/jpeg', MediaAttachment::guessMediaType('image', 'https://x.example/media/uuid'));
		$this->assertSame('video/mp4', MediaAttachment::guessMediaType('gifv', 'https://x.example/media/uuid'));
		$this->assertSame('audio/mpeg', MediaAttachment::guessMediaType('audio', ''));
		$this->assertSame('application/pdf', MediaAttachment::guessMediaType('unknown', 'https://x.example/media/report.pdf'));
		$this->assertSame('', MediaAttachment::guessMediaType('unknown', ''));
	}

	public function testTheMetaIsAnObjectOnTheWire(): void {
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());

		$json = json_decode((string)json_encode($media->asLocal()), false);

		$this->assertIsObject($json->meta, 'meta is a dictionary, never a list');
		$this->assertSame(1200, $json->meta->original->width);
	}

	public function testAnAttachmentWithNoMetaAtAllReportsItAsNull(): void {
		// an AttachmentMeta holding nothing json-encodes as `[]`, which a client
		// decoding a dictionary rejects; null is what Mastodon sends instead
		$this->assertNull((new MediaAttachment())->asLocal()['meta']);
	}

	public function testAnAttachmentWithIdZeroKeepsIt(): void {
		$media = new MediaAttachment();
		$media->import(['id' => '0', 'type' => 'image', 'url' => 'https://a.example/x.png']);

		$this->assertSame('0', $media->asLocal()['id']);
	}

	public function testAsDocumentBuildsAnActivityPubDocument(): void {
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());

		$this->assertSame([
			'type' => 'Document',
			// Mastodon's entity carries no mime; the .jpg says what it is
			'mediaType' => 'image/jpeg',
			'url' => 'https://files.mastodon.social/media/cat.jpg',
			// the wire carries the alt text as `name`
			'name' => 'A cat',
			'blurhash' => 'UBL_:rOp',
			'width' => 1200,
			'height' => 800,
		], $media->asDocument());
	}

	/**
	 * A dimension nobody knows is left out rather than sent as a zero.
	 * `"width": 0` is a false statement about a picture, and a receiver is
	 * entitled to act on it: Pixelfed validates `width`/`height`/`blurhash` as
	 * `nullable|min:…` **when the key is present** and drops the whole post
	 * when one fails, so an attachment with no stored dimensions took the post
	 * with it, silently, on the other side.
	 */
	public function testAsDocumentLeavesOutWhatItDoesNotKnow(): void {
		$document = (new MediaAttachment())->asDocument();

		$this->assertArrayNotHasKey('width', $document);
		$this->assertArrayNotHasKey('height', $document);
		$this->assertArrayNotHasKey('blurhash', $document);
	}

	public function testAsDocumentWithoutDimensionsLeavesThemOut(): void {
		$media = new MediaAttachment();
		$media->import(['id' => '1', 'url' => 'https://a.example/x.png']);

		$document = $media->asDocument();

		$this->assertArrayNotHasKey('width', $document);
		$this->assertArrayNotHasKey('height', $document);
	}

	/** Half a pair of dimensions is not a pair, and says nothing useful. */
	public function testASingleDimensionIsNotStatedOnItsOwn(): void {
		$media = new MediaAttachment();
		$media->import([
			'id' => '1', 'url' => 'https://a.example/x.png',
			'meta' => ['original' => ['width' => 1200, 'height' => 0]],
		]);

		$document = $media->asDocument();

		$this->assertArrayNotHasKey('width', $document);
		$this->assertArrayNotHasKey('height', $document);
	}

	/**
	 * Media links are stored absolute, so a row written before the instance
	 * moved — or written by cron under a different overwrite.cli.url than a
	 * web request would have used — points at a host that no longer serves it.
	 * The uuid is the only part still worth keeping.
	 */
	public function testAMediaLinkFromAnOldAddressIsRebuiltForThisInstance(): void {
		$this->withUrlGenerator();
		$media = new MediaAttachment();
		$media->import([
			'id' => '190',
			'type' => 'image',
			'url' => 'http://localhost:8099/index.php/apps/social/media/a0a962e5-7e98-433b-80e2-09106a0b074f.png',
			'preview_url' => 'http://localhost/nextcloud/index.php/apps/social/media/272c3a32-c626-45a0-b08f-a03e7bb4ab2d.png',
		]);

		$local = $media->asLocal();

		$this->assertSame(
			'https://cloud.example.org/media/a0a962e5-7e98-433b-80e2-09106a0b074f.png',
			$local['url'],
		);
		$this->assertSame(
			'https://cloud.example.org/media/272c3a32-c626-45a0-b08f-a03e7bb4ab2d.png',
			$local['preview_url'],
		);
	}

	/**
	 * A federated video's `url` names a cache row rather than a copy, so there
	 * is no uuid in it -- and it has the same problem the uuid links had: it
	 * was written under whichever `overwrite.cli.url` the inbox request ran
	 * under, which on many instances is not the address a reader is on.
	 */
	public function testAStreamedVideoLinkIsRebuiltForThisInstanceToo(): void {
		$this->withUrlGenerator();
		$media = new MediaAttachment();
		$media->import([
			'id' => '711',
			'type' => 'video',
			'url' => 'http://devel/nextcloud/index.php/apps/social/media/stream/711',
			'preview_url' => 'http://localhost/nextcloud/index.php/apps/social/media/272c3a32-c626-45a0-b08f-a03e7bb4ab2d.png',
		]);

		$local = $media->asLocal();

		$this->assertSame('https://cloud.example.org/media/stream/711', $local['url']);
		$this->assertSame(
			'https://cloud.example.org/media/272c3a32-c626-45a0-b08f-a03e7bb4ab2d.png',
			$local['preview_url'],
		);
	}

	/** Somebody else's path that happens to end that way is not ours. */
	public function testARemoteLinkEndingInStreamIsLeftAlone(): void {
		$this->withUrlGenerator();
		$media = new MediaAttachment();
		$media->import(['id' => '1', 'url' => 'https://peertube.example/live/stream/42x']);

		$this->assertSame('https://peertube.example/live/stream/42x', $media->asLocal()['url']);
	}

	public function testALinkThatIsNotOneOfOurUuidsIsLeftAlone(): void {
		$this->withUrlGenerator();
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());

		$local = $media->asLocal();

		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $local['url']);
		$this->assertSame('https://files.mastodon.social/media/small/cat.jpg', $local['preview_url']);
		$this->assertSame('https://remote.example/media/cat.jpg', $local['remote_url']);
	}

	public function testAnAttachmentWithoutLinksReportsThemAsNull(): void {
		$this->withUrlGenerator();

		$local = (new MediaAttachment())->asLocal();

		$this->assertArrayHasKey('url', $local);
		$this->assertNull($local['url']);
		$this->assertArrayHasKey('preview_url', $local);
		$this->assertNull($local['preview_url']);
	}

	public function testJsonSerializeFollowsTheExportFormat(): void {
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());

		$this->assertSame(ACore::FORMAT_LOCAL, $media->getExportFormat());
		$this->assertEquals($media->asLocal(), $media->jsonSerialize());

		$media->setExportFormat(ACore::FORMAT_ACTIVITYPUB);
		$this->assertSame($media->asDocument(), $media->jsonSerialize());
	}

	/**
	 * A client is told there is nothing here rather than handed a link to it.
	 * `url` used to be the empty string once the document stopped inventing
	 * one, and `""` is not a url either — Mastodon sends null.
	 */
	public function testAnAttachmentWithNoCopyHereSendsNullRatherThanAnEmptyUrl(): void {
		$media = new MediaAttachment();
		$media->setId('7')
			->setType('image')
			->setMediaType('image/jpeg')
			->setUrl('')
			->setPreviewUrl('')
			->setRemoteUrl('https://files.remote.example/media/cat.jpg')
			->setCacheError(1);

		$local = $media->asLocal();

		$this->assertNull($local['url']);
		$this->assertNull($local['preview_url']);
		$this->assertSame('https://files.remote.example/media/cat.jpg', $local['remote_url']);
		$this->assertSame(1, $local['cache_error']);
	}

	/**
	 * The wire is the other way round: a peer asking for the bytes has to be
	 * told a host that serves them, and this instance serves none. Naming the
	 * origin there raises none of the questions it would in `asLocal()`,
	 * because no reader's browser is doing the fetching.
	 */
	public function testTheWireNamesTheOriginWhenThereIsNoCopyHere(): void {
		$media = new MediaAttachment();
		$media->setType('image')
			->setMediaType('image/jpeg')
			->setUrl('')
			->setRemoteUrl('https://files.remote.example/media/cat.jpg');

		$this->assertSame(
			'https://files.remote.example/media/cat.jpg',
			$media->asDocument()['url']
		);
	}

	/** A copy that is here is still named, on both sides. */
	public function testACopyHereIsNamedAsBefore(): void {
		$media = new MediaAttachment();
		$media->setType('image')
			->setMediaType('image/jpeg')
			->setUrl('https://cloud.example.org/index.php/apps/social/media/abc.jpeg')
			->setRemoteUrl('https://files.remote.example/media/cat.jpg');

		$this->assertNotNull($media->asLocal()['url']);
		$this->assertSame(
			'https://cloud.example.org/index.php/apps/social/media/abc.jpeg',
			$media->asDocument()['url']
		);
	}
}
