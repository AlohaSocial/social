<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Service\PeerTubeService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Everything a `Video` says that a `Note` has nowhere to put.
 *
 * A video is not a post with a rectangle in it: it has a category, a licence,
 * chapters, captions, a support line and two counters, every one of which
 * PeerTube publishes and none of which this app read.
 */
class PeerTubeVideoMetaTest extends TestCase {
	private PeerTubeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new PeerTubeService(
			$this->createStub(DocumentInterface::class),
			$this->createStub(IURLGenerator::class),
			$this->createStub(LoggerInterface::class),
		);
	}

	public function testAVideoThatSaysNothingExtraHasNoBlock(): void {
		$this->assertSame([], $this->service->videoMeta(['type' => 'Video']));
	}

	/**
	 * PeerTube sends these as `{id, label}`. The label is what a reader wants;
	 * the id means nothing off its own instance.
	 */
	public function testTheLabelIsTakenRatherThanTheId(): void {
		$meta = $this->service->videoMeta([
			'category' => ['id' => 15, 'label' => 'Science & Technology'],
			'licence' => ['id' => 2, 'label' => 'Attribution - Share Alike'],
			'language' => ['identifier' => 'en', 'name' => 'English'],
		]);

		$this->assertSame('Science & Technology', $meta['category']);
		$this->assertSame('Attribution - Share Alike', $meta['licence']);
		$this->assertSame('en', $meta['language']);
	}

	public function testABareStringIsTakenAsItIs(): void {
		$meta = $this->service->videoMeta(['category' => 'Music']);

		$this->assertSame('Music', $meta['category']);
	}

	public function testTheCountersAreRead(): void {
		$meta = $this->service->videoMeta(['views' => 412, 'likes' => 10, 'dislikes' => 2]);

		$this->assertSame(412, $meta['views']);
		$this->assertSame(10, $meta['likes']);
		$this->assertSame(2, $meta['dislikes']);
	}

	/**
	 * An absent `downloadEnabled` is PeerTube's own default of "yes", so only a
	 * stated refusal is recorded — an absent field must not read as one.
	 */
	public function testOnlyAStatedRefusalToDownloadIsRecorded(): void {
		$this->assertArrayNotHasKey('download', $this->service->videoMeta([]));
		$this->assertFalse($this->service->videoMeta(['downloadEnabled' => false])['download']);
		$this->assertTrue($this->service->videoMeta(['downloadEnabled' => true])['download']);
	}

	public function testALiveBroadcastSaysSo(): void {
		$this->assertArrayNotHasKey('live', $this->service->videoMeta([]));
		$this->assertTrue($this->service->videoMeta(['isLiveBroadcast' => true])['live']);
	}

	public function testChaptersComeBackInOrderWithTheirOffsets(): void {
		$meta = $this->service->videoMeta([
			'hasParts' => [
				['type' => 'Chapter', 'name' => 'The end', 'startOffset' => 600],
				['type' => 'Chapter', 'name' => 'Intro', 'startOffset' => 0],
				['type' => 'Chapter', 'name' => 'Middle', 'startOffset' => 120],
			],
		]);

		$this->assertSame([
			['title' => 'Intro', 'start' => 0],
			['title' => 'Middle', 'start' => 120],
			['title' => 'The end', 'start' => 600],
		], $meta['chapters']);
	}

	/** A part with no name or no moment is not a chapter. */
	public function testAnIncompleteChapterIsLeftOut(): void {
		$meta = $this->service->videoMeta([
			'hasParts' => [
				['name' => 'Intro'],
				['startOffset' => 10],
				['name' => 'Real', 'startOffset' => 20],
			],
		]);

		$this->assertSame([['title' => 'Real', 'start' => 20]], $meta['chapters']);
	}

	/**
	 * PeerTube hangs its subtitle tracks off `url` as `text/vtt` links, which
	 * is the only place they are actually addressable.
	 */
	public function testCaptionsAreReadOffTheUrlList(): void {
		$meta = $this->service->videoMeta([
			'url' => [
				['type' => 'Link', 'mediaType' => 'text/html', 'href' => 'https://p.example/w/x'],
				[
					'type' => 'Link',
					'mediaType' => 'text/vtt',
					'href' => 'https://p.example/captions/en.vtt',
					'language' => ['identifier' => 'en'],
				],
			],
		]);

		$this->assertSame(
			[['language' => 'en', 'url' => 'https://p.example/captions/en.vtt']],
			$meta['captions']
		);
	}

	public function testTheSameCaptionTwiceIsOneTrack(): void {
		$meta = $this->service->videoMeta([
			'subtitleLanguage' => [['identifier' => 'en', 'url' => 'https://p.example/en.vtt']],
			'url' => [[
				'type' => 'Link', 'mediaType' => 'text/vtt',
				'href' => 'https://p.example/en.vtt', 'language' => 'en',
			]],
		]);

		$this->assertCount(1, $meta['captions']);
	}
}
