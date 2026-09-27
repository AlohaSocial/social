<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\PeerTubeService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reading a PeerTube `Video`.
 *
 * Everything asserted here is a place where PeerTube writes something an
 * ordinary `Note` keeps somewhere else, or does not have at all -- which is
 * why a federated video used to arrive attributed to nobody, pointing nowhere
 * and with no picture.
 */
class PeerTubeServiceTest extends TestCase {
	private PeerTubeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new PeerTubeService(
			$this->createStub(DocumentInterface::class),
			$this->createStub(IURLGenerator::class),
			$this->createStub(LoggerInterface::class),
		);
	}

	/** A `Video` as PeerTube actually publishes one. */
	private function video(array $overrides = []): array {
		return array_merge([
			'type' => 'Video',
			'id' => 'https://peertube.example/videos/watch/6f4c1e1a',
			'name' => 'The state of the Fediverse',
			'duration' => 'PT1H2M3S',
			'mediaType' => 'text/markdown',
			'content' => "A talk about **federation**.\n\nAnd a second paragraph.",
			'icon' => [
				['type' => 'Image', 'url' => 'https://peertube.example/thumb-small.jpg', 'mediaType' => 'image/jpeg', 'width' => 280, 'height' => 157],
				['type' => 'Image', 'url' => 'https://peertube.example/thumb-big.jpg', 'mediaType' => 'image/jpeg', 'width' => 850, 'height' => 480],
			],
			'url' => [
				['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://peertube.example/w/6f4c1e1a'],
				['type' => 'Link', 'mediaType' => 'video/mp4', 'href' => 'https://peertube.example/static/480.mp4', 'height' => 480],
				['type' => 'Link', 'mediaType' => 'video/mp4', 'href' => 'https://peertube.example/static/720.mp4', 'height' => 720],
				['type' => 'Link', 'rel' => ['metadata', 'video/mp4'], 'mediaType' => 'application/json', 'href' => 'https://peertube.example/meta/720', 'height' => 720],
				['type' => 'Link', 'mediaType' => 'application/x-mpegURL', 'href' => 'https://peertube.example/hls/master.m3u8'],
				['type' => 'Link', 'mediaType' => 'application/x-bittorrent;x-scheme-handler/magnet', 'href' => 'magnet:?xt=urn:btih:deadbeef'],
			],
			'attributedTo' => [
				['type' => 'Person', 'id' => 'https://peertube.example/accounts/alice'],
				['type' => 'Group', 'id' => 'https://peertube.example/video-channels/news'],
			],
		], $overrides);
	}

	public function testTheBestPlayableFileWins(): void {
		$source = $this->service->source($this->video());

		$this->assertInstanceOf(Document::class, $source);
		$this->assertSame('https://peertube.example/static/720.mp4', $source->getUrl());
		$this->assertSame('video/mp4', $source->getMediaType());
	}

	/**
	 * The row exists so the streaming route has something to check a request
	 * against; it is not a copy and must never become one.
	 */
	public function testTheSourceIsMarkedStreamedRatherThanCached(): void {
		$source = $this->service->source($this->video());

		$this->assertTrue($source->isStreamed());
		$this->assertSame(Document::COPY_STREAMED, $source->getLocalCopy());
	}

	/**
	 * A playlist Chrome and Firefox cannot open must never be preferred over a
	 * file they can -- but it beats no player at all on an instance that
	 * transcodes to HLS only.
	 */
	public function testHlsIsTakenOnlyWhenThereIsNoFile(): void {
		$data = $this->video();
		$data['url'] = [
			['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://peertube.example/w/6f4c1e1a'],
			['type' => 'Link', 'mediaType' => 'application/x-mpegURL', 'href' => 'https://peertube.example/hls/master.m3u8'],
		];

		$this->assertSame(
			'https://peertube.example/hls/master.m3u8',
			$this->service->source($data)?->getUrl()
		);
	}

	/**
	 * The case a real PeerTube actually federates. Transcoding to HLS is the
	 * default, and such an instance publishes *one* top-level link -- the
	 * playlist -- and hangs the playable file for each resolution off that
	 * link's `tag`. Reading only the top level found a playlist and nothing
	 * else, which is to say nothing Chrome or Firefox can open, on the
	 * majority of videos on the network.
	 */
	public function testTheFileInsideAnHlsPlaylistsTagIsFound(): void {
		$data = $this->video();
		$data['url'] = [
			['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://peertube.example/w/6f4c1e1a'],
			[
				'type' => 'Link',
				'mediaType' => 'application/x-mpegURL',
				'href' => 'https://peertube.example/hls/master.m3u8',
				'tag' => [
					['type' => 'Infohash', 'name' => '4363496f6e4630567a666b7a62756c6d43556f73'],
					['type' => 'Link', 'mediaType' => 'application/json', 'href' => 'https://peertube.example/meta/720', 'height' => 720],
					['type' => 'Link', 'mediaType' => 'video/mp4', 'href' => 'https://peertube.example/hls/720-fragmented.mp4', 'height' => 720],
					['type' => 'Link', 'mediaType' => 'video/mp4', 'href' => 'https://peertube.example/hls/480-fragmented.mp4', 'height' => 480],
					['type' => 'Link', 'mediaType' => 'application/x-bittorrent;x-scheme-handler/magnet', 'href' => 'magnet:?xt=urn:btih:dead'],
				],
			],
		];

		$source = $this->service->source($data);

		$this->assertSame('https://peertube.example/hls/720-fragmented.mp4', $source?->getUrl());
		$this->assertSame('video/mp4', $source?->getMediaType());
	}

	/** The watch page is still the `text/html` link, not something in a tag. */
	public function testTheWatchPageSurvivesTheNestedLinks(): void {
		$data = $this->video();
		$data['url'][1]['tag'] = [
			['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://peertube.example/not-the-watch-page'],
		];

		$this->assertSame('https://peertube.example/w/6f4c1e1a', $this->service->watchUrl($data));
	}

	public function testAVideoWithNothingPlayableProducesNoSource(): void {
		$data = $this->video();
		$data['url'] = [
			['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://peertube.example/w/6f4c1e1a'],
			['type' => 'Link', 'mediaType' => 'application/x-bittorrent', 'href' => 'https://peertube.example/720.torrent'],
		];

		$this->assertNull($this->service->source($data));
	}

	/** Nobody is streaming 4K through a proxy. */
	public function testAResolutionAboveTheCeilingIsPassedOver(): void {
		$data = $this->video();
		$data['url'][] = ['type' => 'Link', 'mediaType' => 'video/mp4', 'href' => 'https://peertube.example/static/2160.mp4', 'height' => 2160];

		$this->assertSame(
			'https://peertube.example/static/720.mp4',
			$this->service->source($data)?->getUrl()
		);
	}

	public function testTheLargestThumbnailIsTaken(): void {
		$this->assertSame(
			['url' => 'https://peertube.example/thumb-big.jpg', 'mediaType' => 'image/jpeg'],
			$this->service->thumbnail($this->video())
		);
	}

	/** Older PeerTube sends one `Image` rather than a list of them. */
	public function testASingleIconIsReadAsWellAsAList(): void {
		$data = $this->video();
		$data['icon'] = ['type' => 'Image', 'url' => 'https://peertube.example/only.jpg', 'mediaType' => 'image/png'];

		$this->assertSame(
			['url' => 'https://peertube.example/only.jpg', 'mediaType' => 'image/png'],
			$this->service->thumbnail($data)
		);
	}

	public function testAVideoWithNoIconHasNoThumbnail(): void {
		$data = $this->video();
		unset($data['icon']);

		$this->assertNull($this->service->thumbnail($data));
	}

	/** The short `/w/` form, which is a different URL from the object id. */
	public function testTheWatchPageIsTheHtmlLink(): void {
		$this->assertSame(
			'https://peertube.example/w/6f4c1e1a',
			$this->service->watchUrl($this->video())
		);
	}

	public static function durationProvider(): array {
		return [
			'hours, minutes and seconds' => ['PT1H2M3S', 3723],
			'seconds alone' => ['PT113S', 113],
			'minutes alone' => ['PT4M', 240],
			'fractional seconds round' => ['PT7.6S', 8],
			'absent' => ['', 0],
			'not a duration' => ['an hour or so', 0],
			'a duration in days, which no video is' => ['P2D', 0],
		];
	}

	#[DataProvider('durationProvider')]
	public function testDurationIsReadFromTheXsdForm(string $duration, int $expected): void {
		$data = $this->video();
		$data['duration'] = $duration;

		$this->assertSame($expected, $this->service->duration($data));
	}

	/**
	 * The channel, not the account: it is what the `Create` is signed by, what
	 * a reader follows, and what the video is listed under on PeerTube itself.
	 */
	public function testTheChannelWinsTheAttribution(): void {
		$this->assertSame(
			'https://peertube.example/video-channels/news',
			$this->service->attributedTo($this->video())
		);
	}

	public function testThePersonIsUsedWhenThereIsNoChannel(): void {
		$data = $this->video();
		$data['attributedTo'] = [['type' => 'Person', 'id' => 'https://peertube.example/accounts/alice']];

		$this->assertSame(
			'https://peertube.example/accounts/alice',
			$this->service->attributedTo($data)
		);
	}

	/** Every other server sends one id as a string, and that still works. */
	public function testAPlainStringAttributionIsKept(): void {
		$data = $this->video();
		$data['attributedTo'] = 'https://peertube.example/accounts/alice';

		$this->assertSame(
			'https://peertube.example/accounts/alice',
			$this->service->attributedTo($data)
		);
	}

	public function testTheTitleBecomesTheFirstParagraphLinkedToTheWatchPage(): void {
		$this->assertStringStartsWith(
			'<p><a href="https://peertube.example/w/6f4c1e1a">The state of the Fediverse</a></p>',
			$this->service->content($this->video())
		);
	}

	/**
	 * PeerTube declares `text/markdown` and sends the description raw. Passing
	 * that through as html would hand a remote server a way to put markup in a
	 * post that went through no sanitiser of its own.
	 */
	public function testADeclaredMarkdownDescriptionIsEscapedAndSplit(): void {
		$data = $this->video();
		$data['content'] = "<b>not html</b>\n\nsecond";

		$content = $this->service->content($data);

		$this->assertStringContainsString('<p>&lt;b&gt;not html&lt;/b&gt;</p>', $content);
		$this->assertStringContainsString('<p>second</p>', $content);
	}

	/**
	 * Left alone a description reads as asterisks, brackets and a url in
	 * parentheses in the middle of a timeline. The escaping comes first, so
	 * every tag in the answer is one this app wrote.
	 */
	public function testTheLittleOfMarkdownADescriptionUsesIsRendered(): void {
		$data = $this->video();
		$data['content'] = '**Take back your videos! [#JoinPeertube](https://joinpeertube.org)** '
			. 'and *nothing else*, see https://example.org/a';

		$content = $this->service->content($data);

		$this->assertStringContainsString(
			'<strong>Take back your videos! <a href="https://joinpeertube.org"'
			. ' rel="nofollow noopener noreferrer" target="_blank">#JoinPeertube</a></strong>',
			$content
		);
		$this->assertStringContainsString('<em>nothing else</em>', $content);
		$this->assertStringContainsString('>https://example.org/a</a>', $content);
	}

	public static function unsafeMarkdownProvider(): array {
		return [
			'a tag' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
			// only an http(s) target becomes a link; everything else stays text
			'a javascript target' => ['[click](javascript:alert(1))', '[click](javascript:alert(1))'],
			'a data target' => ['[click](data:text/html,x)', '[click](data:text/html,x)'],
			// a quote in a url cannot end the attribute it is written into
			'a quote in a url' => ['[x](https://ok.example/a"b)', 'href="https://ok.example/a&quot;b"'],
		];
	}

	#[DataProvider('unsafeMarkdownProvider')]
	public function testMarkdownIsRenderedOutOfEscapedTextOnly(string $description, string $expected): void {
		$data = $this->video();
		$data['content'] = $description;

		$this->assertStringContainsString($expected, $this->service->content($data));
	}

	/** And the other way round: escaping html shows somebody their own tags. */
	public function testADescriptionThatIsNotDeclaredMarkdownIsPassedThrough(): void {
		$data = $this->video();
		unset($data['mediaType']);
		$data['content'] = '<p>real html</p>';

		$this->assertStringEndsWith('<p>real html</p>', $this->service->content($data));
	}

	public function testATitlelessVideoGetsNoEmptyParagraph(): void {
		$data = $this->video();
		unset($data['name']);
		unset($data['mediaType']);
		$data['content'] = '<p>just a description</p>';

		$this->assertSame('<p>just a description</p>', $this->service->content($data));
	}

	/**
	 * The attachment is what puts the video in the Videos timeline and what
	 * gives the player something to point at.
	 */
	public function testTheAttachmentIsAVideoCarryingItsRunningTime(): void {
		$attachments = $this->service->attachments($this->video(), new Note());

		$this->assertCount(1, $attachments);
		$this->assertSame('video', $attachments[0]->getType());
		$this->assertSame('video/mp4', $attachments[0]->getMediaType());
		$this->assertSame('https://peertube.example/static/720.mp4', $attachments[0]->getRemoteUrl());
		$this->assertSame(3723.0, $attachments[0]->getMeta()?->getDuration());
	}

	public function testAVideoWithNothingPlayableGetsNoAttachment(): void {
		$data = $this->video();
		$data['url'] = [['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://peertube.example/w/1']];

		$this->assertSame([], $this->service->attachments($data, new Note()));
	}
}
