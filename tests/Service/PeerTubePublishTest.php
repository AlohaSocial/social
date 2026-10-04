<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\PeerTubeService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The other direction: the `Video` this instance publishes.
 *
 * PeerTube ingests `Video` objects and nothing else, so an instance publishing
 * `Note`s had, from its side, no videos at all. What is asserted hardest here
 * is the **round trip** -- everything published goes back through the reader in
 * the same file, because two halves of one wire format in one class is only
 * worth anything if they are held to each other.
 */
class PeerTubePublishTest extends TestCase {
	private const WATCH = 'https://cloud.example.org/apps/social/@alice/0123';
	private const ALICE = 'https://cloud.example.org/apps/social/@alice';
	private const CHANNEL = 'https://cloud.example.org/apps/social/@alice_channel';

	/**
	 * What every video carries: the channel it is filed under, then the
	 * account that made it. PeerTube resolves the channel by looking for the
	 * `Group` and refuses a video that names none.
	 *
	 * @return array<int, array{type: string, id: string}>
	 */
	private static function attribution(): array {
		return [
			['type' => 'Group', 'id' => self::CHANNEL],
			['type' => 'Person', 'id' => self::ALICE],
		];
	}

	private PeerTubeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new PeerTubeService(
			$this->createStub(DocumentInterface::class),
			$this->createStub(IURLGenerator::class),
			$this->createStub(LoggerInterface::class),
		);
	}

	private function attachment(
		string $type = 'video',
		string $preview = 'https://cloud.example.org/media/poster.jpeg',
		float $duration = 113,
	): MediaAttachment {
		$meta = new AttachmentMeta();
		$meta->setDuration($duration);
		$original = new AttachmentMetaDim([1920, 1080]);
		$original->setDuration($duration);
		$meta->setOriginal($original);
		$meta->setSmall(new AttachmentMetaDim([1280, 720]));

		$media = new MediaAttachment();
		$media->setId('7')
			->setType($type)
			->setMediaType('video/mp4')
			->setUrl('https://cloud.example.org/media/movie.mp4')
			->setPreviewUrl($preview)
			->setDescription('A cat knocking a glass off a table')
			// PeerTube drops a video link with no `size`, so the publisher
			// refuses to make a `Video` without one
			->setSizeBytes(4_194_304);

		return $media->setMeta($meta);
	}

	/** A note as `exportAsActivityPub()` would have produced it. */
	private function note(string $content = '<p>The cat and the glass</p><p>Again.</p>'): array {
		return [
			'id' => self::WATCH,
			'type' => 'Note',
			'content' => $content,
			'attributedTo' => 'https://cloud.example.org/apps/social/@alice',
			'published' => '2026-09-13T10:00:00Z',
			'sensitive' => false,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'attachment' => [['type' => 'Document', 'mediaType' => 'video/mp4']],
		];
	}

	// which posts are videos at all

	/**
	 * PeerTube's `isUUIDValid()` is validator.js `isUUID(value, 4)`: a
	 * version-5 uuid, which is what a hash of a name really is, made PeerTube
	 * refuse every `Video` sent from here on that one field.
	 */
	public function testTheVideoUuidIsOnePeerTubeAccepts(): void {
		$uuid = PeerTubeService::uuidFor('https://cloud.example.org/@alice/123');

		$this->assertMatchesRegularExpression(
			'/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i',
			$uuid
		);
	}

	/** The same post is the same video however often it is delivered. */
	public function testTheVideoUuidIsStableAndDiffersPerPost(): void {
		$this->assertSame(
			PeerTubeService::uuidFor('https://cloud.example.org/@alice/123'),
			PeerTubeService::uuidFor('https://cloud.example.org/@alice/123')
		);
		$this->assertNotSame(
			PeerTubeService::uuidFor('https://cloud.example.org/@alice/123'),
			PeerTubeService::uuidFor('https://cloud.example.org/@alice/124')
		);
	}

	public function testOneVideoAttachmentMakesItAVideo(): void {
		$this->assertNotNull(PeerTubeService::soleVideo([$this->attachment()]));
	}

	/**
	 * A `Video` object *is* the video -- one title, one duration, one file --
	 * so a post carrying a video and something else is a post.
	 */
	public function testAVideoAlongsideAnythingElseIsNotAVideo(): void {
		$this->assertNull(PeerTubeService::soleVideo([$this->attachment(), $this->attachment('image')]));
	}

	public function testAPostWithNoVideoIsNotAVideo(): void {
		$this->assertNull(PeerTubeService::soleVideo([$this->attachment('image')]));
		$this->assertNull(PeerTubeService::soleVideo([]));
	}

	// the ladder

	/** A rung as `Note::rungsOf()` builds one. */
	private static function rung(int $height, int $size): array {
		return [
			'height' => $height,
			'size' => $size,
			'bandwidth' => $size * 8 / 100,
			'href' => 'https://cloud.example.org/media/hls/stored-uuid/' . $height . '/file',
		];
	}

	private function laddered(): MediaAttachment {
		return $this->attachment()->setHlsUrl('https://cloud.example.org/media/hls/stored-uuid');
	}

	/**
	 * PeerTube reads `application/x-mpegURL` as "this is HLS" and takes the
	 * resolutions from the `Link` tags rather than by fetching the playlist,
	 * so a link with no tags is one it accepts and then has no files for.
	 */
	public function testALadderIsPublishedAsAStreamingPlaylistWithARungPerTag(): void {
		$video = PeerTubeService::asVideo(
			$this->note(), $this->laddered(), self::WATCH, self::attribution(), 0, [],
			[self::rung(360, 500_000), self::rung(720, 2_600_000)]
		);

		$playlists = array_values(array_filter(
			$video['url'],
			static fn (array $link): bool => ($link['mediaType'] ?? '') === 'application/x-mpegURL'
		));

		$this->assertCount(1, $playlists);
		$this->assertSame('https://cloud.example.org/media/hls/stored-uuid', $playlists[0]['href']);
		$this->assertCount(2, $playlists[0]['tag']);
		$this->assertSame(
			[360, 720],
			array_column($playlists[0]['tag'], 'height')
		);
		// every tag is a file link in the shape `isRemoteVideoUrlValid()`
		// accepts: a real type, a height and a size
		foreach ($playlists[0]['tag'] as $tag) {
			$this->assertSame('video/mp4', $tag['mediaType']);
			$this->assertGreaterThan(0, $tag['size']);
		}
	}

	/**
	 * The plain file is always there beside it: a reader with no HLS plays
	 * that, which is the same video at one size.
	 */
	public function testTheWholeFileIsStillOfferedBesideTheLadder(): void {
		$video = PeerTubeService::asVideo(
			$this->note(), $this->laddered(), self::WATCH, self::attribution(), 0, [],
			[self::rung(360, 500_000)]
		);

		$files = array_values(array_filter(
			$video['url'],
			static fn (array $link): bool => ($link['mediaType'] ?? '') === 'video/mp4'
		));

		$this->assertCount(1, $files);
		$this->assertSame('https://cloud.example.org/media/movie.mp4', $files[0]['href']);
	}

	/** A video with no ladder publishes no playlist rather than an empty one. */
	public function testAVideoWithNoLadderPublishesNoPlaylist(): void {
		$video = PeerTubeService::asVideo(
			$this->note(), $this->attachment(), self::WATCH, self::attribution()
		);

		foreach ($video['url'] as $link) {
			$this->assertNotSame('application/x-mpegURL', $link['mediaType'] ?? '');
		}
	}

	/**
	 * A master url with nothing under it would be a playlist PeerTube takes
	 * and then has no files for.
	 */
	public function testAMasterWithNoRungsIsNotPublished(): void {
		$video = PeerTubeService::asVideo(
			$this->note(), $this->laddered(), self::WATCH, self::attribution(), 0, [], []
		);

		foreach ($video['url'] as $link) {
			$this->assertNotSame('application/x-mpegURL', $link['mediaType'] ?? '');
		}
	}

	// the shape

	public function testTheObjectBecomesAVideoCarryingItsRunningTime(): void {
		$video = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH, self::attribution());

		$this->assertSame('Video', $video['type']);
		$this->assertSame('PT113S', $video['duration']);
		$this->assertTrue($video['commentsEnabled']);
		// what the content is, said out loud: this app's posts are html, and a
		// peer that assumed markdown would show somebody their own tags
		$this->assertSame('text/html', $video['mediaType']);
	}

	/**
	 * `url` as a list is the one thing PeerTube actually looks at, and a
	 * `Video` without it is a video nothing can play.
	 */
	public function testTheFileAndTheWatchPageBothTravelInUrl(): void {
		$video = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH, self::attribution());

		$this->assertSame([
			['type' => 'Link', 'mediaType' => 'text/html', 'href' => self::WATCH],
			[
				'type' => 'Link',
				'mediaType' => 'video/mp4',
				'href' => 'https://cloud.example.org/media/movie.mp4',
				'width' => 1920,
				'height' => 1080,
				// `isRemoteVideoUrlValid()` wants `height` and `size` as
				// integers and drops a file link carrying neither, so a
				// `Video` published without them arrived with nothing to play
				'size' => 4_194_304,
			],
		], $video['url']);
	}

	public function testThePosterTravelsAsTheIcon(): void {
		$video = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH, self::attribution());

		$this->assertSame([[
			'type' => 'Image',
			'mediaType' => 'image/jpeg',
			'url' => 'https://cloud.example.org/media/poster.jpeg',
			'width' => 1280,
			'height' => 720,
		]], $video['icon']);
	}

	/**
	 * `icon` is mandatory to PeerTube's validator, so a video with no poster
	 * cannot be published as a `Video` at all — it stays the `Note` it was,
	 * which every Mastodon-family server reads. Sending a `Video` that is
	 * going to be thrown away on arrival is strictly worse.
	 */
	public function testAVideoWithNoPosterIsNotPublishedAsAVideo(): void {
		$attachment = $this->attachment(preview: 'https://cloud.example.org/media/movie.mp4');

		$this->assertNull(
			PeerTubeService::asVideo($this->note(), $attachment, self::WATCH, self::attribution())
		);
	}

	/** The same rule for the other two mandatory fields. */
	public function testAVideoWithNoDurationOrNoChannelIsNotPublishedAsAVideo(): void {
		$this->assertNull(
			PeerTubeService::asVideo(
				$this->note(), $this->attachment(duration: 0), self::WATCH, self::attribution()
			),
			'no duration'
		);

		$this->assertNull(
			PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH, []),
			'no channel'
		);
	}

	/**
	 * Every Mastodon-family server reads `attachment` and nothing else, and
	 * these posts rendered there with an inline player before any of this
	 * existed. Publishing both is the difference between gaining PeerTube and
	 * trading Mastodon for it.
	 */
	public function testTheAttachmentIsKeptForEverybodyElse(): void {
		$video = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH, self::attribution());

		$this->assertSame([['type' => 'Document', 'mediaType' => 'video/mp4']], $video['attachment']);
	}

	public function testEverythingTheNoteAlreadySaidSurvives(): void {
		$note = $this->note();

		$video = PeerTubeService::asVideo($note, $this->attachment(), self::WATCH, self::attribution());

		foreach (['id', 'published', 'sensitive', 'to', 'content'] as $key) {
			$this->assertSame($note[$key], $video[$key], $key . ' was lost');
		}

		// the one field that is deliberately *not* what the note said: a
		// `Video` is attributed to the channel it is filed under as well as to
		// the account that made it, and PeerTube refuses one that is not
		$this->assertSame(self::attribution(), $video['attributedTo']);
	}

	// the title, which a Note does not have

	public function testTheTitleIsTheFirstLineOfThePost(): void {
		$video = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH, self::attribution());

		$this->assertSame('The cat and the glass', $video['name']);
	}

	/** A post that is nothing but the video falls back to its alt text. */
	public function testAPostWithNoTextIsTitledByItsAltText(): void {
		$video = PeerTubeService::asVideo($this->note(''), $this->attachment(), self::WATCH, self::attribution());

		$this->assertSame('A cat knocking a glass off a table', $video['name']);
	}

	public function testATitleIsAlwaysPresent(): void {
		$attachment = $this->attachment();
		$attachment->setDescription('');

		$video = PeerTubeService::asVideo($this->note(''), $attachment, self::WATCH, self::attribution());

		$this->assertSame('Video', $video['name']);
	}

	public function testTheTitleIsPlainTextAndBounded(): void {
		$long = str_repeat('a very long title indeed ', 20);

		$video = PeerTubeService::asVideo(
			$this->note('<p><a href="https://x.example">' . $long . '</a></p>'),
			$this->attachment(),
			self::WATCH,
			self::attribution()
		);

		$this->assertSame(120, mb_strlen($video['name']));
		$this->assertStringNotContainsString('<', $video['name']);
	}

	/** Entities in the html are text by the time they are a title. */
	public function testTheTitleIsDecoded(): void {
		$video = PeerTubeService::asVideo(
			$this->note('<p>Cats &amp; dogs</p>'),
			$this->attachment(),
			self::WATCH,
			self::attribution()
		);

		$this->assertSame('Cats & dogs', $video['name']);
	}

	// the round trip

	/**
	 * What this app publishes, this app can read -- through the same methods
	 * that read framatube.org. Two halves of one wire format in one class are
	 * only worth anything if they are held to each other.
	 */
	public function testWhatIsPublishedIsReadBackIdentically(): void {
		$published = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH, self::attribution());

		$this->assertSame(self::WATCH, $this->service->watchUrl($published));
		$this->assertSame(113, $this->service->duration($published));
		$this->assertSame(
			['url' => 'https://cloud.example.org/media/poster.jpeg', 'mediaType' => 'image/jpeg'],
			$this->service->thumbnail($published)
		);
		// the **channel**, not the account: a reader takes the `Group` because
		// that is what a video is listed under and what somebody follows, and
		// the round trip is where the two halves are held to that agreement
		$this->assertSame(self::CHANNEL, $this->service->attributedTo($published));

		$source = $this->service->source($published);
		$this->assertSame('https://cloud.example.org/media/movie.mp4', $source?->getUrl());
		$this->assertSame('video/mp4', $source?->getMediaType());
	}

	/**
	 * And the content survives the trip: the title is prepended by the reader,
	 * but the post that was written is still in there and is still html.
	 */
	public function testTheContentSurvivesTheRoundTrip(): void {
		$published = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH, self::attribution());

		$read = $this->service->content($published);

		$this->assertStringContainsString('<p>The cat and the glass</p>', $read);
		$this->assertStringContainsString('<p>Again.</p>', $read);
		// declared html, so it is passed through rather than escaped
		$this->assertStringNotContainsString('&lt;p&gt;', $read);
	}
}
