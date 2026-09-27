<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\VideoRendition;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\VideoLadderService;
use OCP\IBinaryFinder;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Which rungs get written, and what is done with the playlist ffmpeg wrote.
 *
 * The encode itself is not exercised here — that is ffmpeg, and a test that
 * needed it would be a test that only runs on machines that have it. What is
 * tested is every decision made around it, which is where the mistakes are.
 */
class VideoLadderServiceTest extends TestCase {
	private ConfigService|Stub $configService;
	private VideoLadderService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createStub(ConfigService::class);
		$this->service = new VideoLadderService(
			$this->createStub(IBinaryFinder::class),
			$this->createStub(ITempManager::class),
			$this->configService,
			new NullLogger(),
		);
	}

	private function configured(string $heights): void {
		$this->configService->method('getAppValue')->willReturn($heights);
	}

	public function testAnUnsetLadderIsTheDefaultOne(): void {
		$this->configured('');

		$this->assertSame(VideoLadderService::DEFAULT_HEIGHTS, $this->service->heights());
	}

	/**
	 * This is read on a cron run where there is nobody to tell, so a setting
	 * somebody fat-fingered is cleaned up rather than refused: the rungs that
	 * are numbers are used and the rest are dropped.
	 */
	public function testARubbishSettingIsCleanedUpRatherThanObeyed(): void {
		$this->configured('720, nonsense, 360, 720, 99999, 0');

		$this->assertSame([360, 720], $this->service->heights());
	}

	public function testASettingWithNothingUsableInItFallsBackToTheDefault(): void {
		$this->configured('nonsense, 4, -1');

		$this->assertSame(VideoLadderService::DEFAULT_HEIGHTS, $this->service->heights());
	}

	/**
	 * A 480p video written out at 1080p is a bigger file of exactly the same
	 * picture, and a ladder whose top rung is the worst deal on it is worse
	 * than no ladder.
	 */
	public function testNoRungIsTallerThanTheVideo(): void {
		$this->configured('360,720,1080');

		$this->assertSame([360, 480], $this->service->rungs(480));
	}

	/** The source's own height is a rung, so the best rung is never worse than the file beside it. */
	public function testTheSourceHeightIsTheTopRung(): void {
		$this->configured('360,720');

		$this->assertSame([360, 720, 1440], $this->service->rungs(1440));
	}

	/** A video already smaller than every rung still gets a playlist — of itself. */
	public function testAVideoSmallerThanEveryRungIsItsOwnLadder(): void {
		$this->configured('360,720,1080');

		$this->assertSame([240], $this->service->rungs(240));
	}

	public function testAVideoWhoseHeightCouldNotBeReadGetsNoRungs(): void {
		$this->configured('360,720');

		$this->assertSame([], $this->service->rungs(0));
	}

	// --- the playlist ffmpeg wrote ----------------------------------------

	private const PLAYLIST = <<<'M3U8'
		#EXTM3U
		#EXT-X-VERSION:7
		#EXT-X-TARGETDURATION:4
		#EXT-X-PLAYLIST-TYPE:VOD
		#EXT-X-INDEPENDENT-SEGMENTS
		#EXT-X-MAP:URI="rung.m4s",BYTERANGE="1346@0"
		#EXTINF:4.000000,
		#EXT-X-BYTERANGE:423600@1346
		rung.m4s
		#EXTINF:2.500000,
		#EXT-X-BYTERANGE:81959@424946
		rung.m4s
		#EXT-X-ENDLIST
		M3U8;

	/**
	 * The name ffmpeg used is a temporary one; the URI a player has to be
	 * given is a route on this server, which is not known here and changes if
	 * the instance moves. Both the segment lines and the `EXT-X-MAP` attribute
	 * name it, and a player follows both.
	 */
	public function testEveryMentionOfTheMediaFileBecomesThePlaceholder(): void {
		$placeheld = $this->service->placeholdered(self::PLAYLIST, 'rung.m4s');

		$this->assertNotNull($placeheld);
		$this->assertStringNotContainsString('rung.m4s', $placeheld);
		$this->assertStringContainsString(
			'#EXT-X-MAP:URI="' . VideoRendition::URI_PLACEHOLDER . '"', $placeheld
		);
		$this->assertSame(2, substr_count($placeheld, VideoRendition::URI_PLACEHOLDER . "\n"));
	}

	/**
	 * `single_file` is supposed to produce exactly one. A playlist naming
	 * several is an ffmpeg that did something else, and storing it would mean
	 * a rung that 404s partway through for every reader who got that far.
	 */
	public function testAPlaylistNamingMoreThanOneFileIsRefused(): void {
		$twoFiles = str_replace("rung.m4s\n#EXT-X-ENDLIST", "other.m4s\n#EXT-X-ENDLIST", self::PLAYLIST);

		$this->assertNull($this->service->placeholdered($twoFiles, 'rung.m4s'));
	}

	public function testTheDurationIsTheSumOfTheSegments(): void {
		$this->assertSame(6.5, $this->service->durationOf(self::PLAYLIST));
	}

	/**
	 * Measured from the file rather than taken from the encoder's target:
	 * CRF encoding has no target, and a player choosing a rung is choosing on
	 * how much it will actually have to fetch.
	 */
	public function testBandwidthIsBitsPerSecondOfTheFileThatWasWritten(): void {
		$this->assertSame(800000, $this->service->bandwidth(1000000, 10.0));
	}

	/** A playlist with no segments in it must not divide by zero. */
	public function testAnEmptyPlaylistHasNoBandwidth(): void {
		$this->assertSame(0, $this->service->bandwidth(1000, 0.0));
	}

	/** Without ffmpeg there is nothing to turn on. */
	public function testItIsNotEnabledWithoutTheBinaries(): void {
		$this->configService->method('getAppValueBool')->willReturn(true);

		$this->assertFalse($this->service->isAvailable());
		$this->assertFalse($this->service->isEnabled());
	}
}
