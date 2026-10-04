<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCP\IBinaryFinder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The same video written at two or three sizes, as HLS.
 *
 * `VideoTranscodeService` makes a video *playable* — one H.264 MP4 that every
 * server and browser will take. This makes it *watchable on a phone on a
 * train*: a ladder of heights plus a playlist that lets the player move
 * between them as the connection changes. It is also what PeerTube publishes,
 * so a video laddered here is a video a PeerTube reader gets the same way they
 * get a native one.
 *
 * **One file per rung.** HLS normally means a directory of a few hundred
 * segments; `-hls_flags single_file` writes each rung as a single fragmented
 * MP4 and addresses the segments inside it as byte ranges. The store keeps
 * doing the one thing it does well — a file per row — and a 40-minute video is
 * three files rather than a thousand. It is also, not by coincidence, exactly
 * the shape PeerTube's own fMP4 output has.
 *
 * **Keyframes are forced onto the segment boundary** at every rung, with the
 * same period. Without that, ffmpeg cuts a segment at the next keyframe it
 * happens to find, the rungs end up with different boundaries, and a player
 * switching between them either stalls or skips. With it, every rung's
 * segment *n* covers the same seconds of the video, which is the whole
 * premise of adaptive streaming.
 *
 * **It never upscales.** A rung taller than the source is dropped rather than
 * encoded: a 480p video written out at 1080p is a bigger file of exactly the
 * same picture, and a ladder whose top rung is the worst deal on it is worse
 * than no ladder.
 *
 * Off unless an administrator turns it on, like the transcoder and for the
 * same reasons — this is ffmpeg time on somebody's server, several times over
 * per video.
 */
class VideoLadderService {
	/** The rungs an instance gets when its administrator has not said otherwise. */
	public const DEFAULT_HEIGHTS = [360, 720, 1080];

	/** Nothing outside this is accepted from the setting. */
	public const MIN_HEIGHT = 144;
	public const MAX_HEIGHT = 2160;

	/**
	 * How long a segment is.
	 *
	 * Four seconds is the usual compromise: short enough that a player
	 * reacting to a slow connection reacts within a few seconds, long enough
	 * that the per-segment overhead and the number of byte-range requests stay
	 * small.
	 */
	public const SEGMENT_SECONDS = 4;

	/** What a master playlist is served as. */
	public const PLAYLIST_TYPE = 'application/vnd.apple.mpegurl';

	/** What one rung's fragmented MP4 is served as. */
	public const RENDITION_TYPE = 'video/mp4';

	/**
	 * How long one rung gets.
	 *
	 * Per rung rather than per video, so a three-rung ladder on a long video
	 * is not killed halfway up by a budget sized for one encode.
	 */
	private const TIMEOUT_SECONDS = 1800;

	private const PRESET = 'veryfast';

	/**
	 * Constant Rate Factor per rung.
	 *
	 * A little higher than the transcoder's 23: a rung exists to be small, and
	 * the source file is still there for anybody who wants it at full quality.
	 */
	private const CRF = '26';

	/** Audio bitrate per rung, by height. A 360p rung does not need 128k of audio. */
	private const AUDIO_BITRATE = ['small' => '96k', 'large' => '128k'];

	public function __construct(
		private IBinaryFinder $binaryFinder,
		private ITempManager $tempManager,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/** Whether this server could build a ladder at all. */
	public function isAvailable(): bool {
		return $this->ffmpeg() !== null && $this->ffprobe() !== null;
	}

	/** Whether an administrator has asked for one, and it is possible. */
	public function isEnabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_VIDEO_LADDER)
			&& $this->isAvailable();
	}

	/**
	 * The ladder an administrator configured, cleaned up.
	 *
	 * Anything that is not a number in range is dropped rather than
	 * complained about, and the result is sorted and deduplicated: this is
	 * read on a cron run, where there is nobody to tell.
	 *
	 * @return int[] heights, smallest first
	 */
	public function heights(): array {
		$setting = trim($this->configService->getAppValue(ConfigService::SOCIAL_VIDEO_LADDER_HEIGHTS));
		if ($setting === '') {
			return self::DEFAULT_HEIGHTS;
		}

		$heights = [];
		foreach (explode(',', $setting) as $part) {
			$height = (int)trim($part);
			if ($height >= self::MIN_HEIGHT && $height <= self::MAX_HEIGHT) {
				$heights[] = $height;
			}
		}

		$heights = array_values(array_unique($heights));
		sort($heights);

		return ($heights === []) ? self::DEFAULT_HEIGHTS : $heights;
	}

	/**
	 * Which rungs are worth writing for a source this tall.
	 *
	 * Every configured rung below the source, plus the source's own height
	 * when no rung reaches it — so a 1440p video on a 360/720/1080 ladder gets
	 * a 1440p rung too and a player is never offered a ladder whose best rung
	 * is worse than the file sitting beside it.
	 *
	 * @return int[] heights, smallest first
	 */
	public function rungs(int $sourceHeight): array {
		if ($sourceHeight < self::MIN_HEIGHT) {
			return [];
		}

		$rungs = array_values(array_filter(
			$this->heights(),
			static fn (int $height): bool => $height < $sourceHeight
		));

		if ($rungs === []) {
			// a video shorter than the lowest rung: one rung, itself, so it
			// still gets a playlist and a seek bar that works
			return [$sourceHeight];
		}

		$rungs[] = $sourceHeight;

		return $rungs;
	}

	/** How tall a video is, or 0 when it cannot be read. */
	public function heightOf(string $path): int {
		$ffprobe = $this->ffprobe();
		if ($ffprobe === null || !is_readable($path)) {
			return 0;
		}

		$output = '';
		$ran = $this->run([
			$ffprobe,
			'-v', 'error',
			'-protocol_whitelist', 'file',
			'-select_streams', 'v:0',
			'-show_entries', 'stream=height',
			'-of', 'csv=p=0',
			$path,
		], $output, 60);

		return $ran ? (int)trim($output) : 0;
	}

	/**
	 * Writes one rung.
	 *
	 * @param string $path a readable path to the source video
	 * @param int $height the rung's height
	 *
	 * @return array{file: string, playlist: string, size: int, bandwidth: int}|null
	 *                                                                               the encoded file and the playlist that addresses it, with the
	 *                                                                               media filename already replaced by the placeholder; null when
	 *                                                                               the encode produced nothing usable
	 */
	public function encode(string $path, int $height): ?array {
		$ffmpeg = $this->ffmpeg();
		if ($ffmpeg === null || !is_readable($path) || $height < self::MIN_HEIGHT) {
			return null;
		}

		// ffmpeg writes two files whose names it derives from each other, so
		// they need a directory rather than two unrelated temporary names
		$directory = $this->tempManager->getTemporaryFolder();
		if ($directory === false) {
			return null;
		}

		$media = rtrim($directory, '/') . '/rung.m4s';
		$playlist = rtrim($directory, '/') . '/rung.m3u8';
		$output = '';

		$ran = $this->run([
			$ffmpeg,
			'-nostdin',
			// a file somebody uploaded is not a playlist, a device or a URL
			'-protocol_whitelist', 'file',
			'-i', $path,
			// -2 keeps the width even, which H.264 requires, and takes it from
			// the source's aspect ratio rather than assuming 16:9
			'-vf', 'scale=-2:' . $height,
			'-c:v', 'libx264',
			'-profile:v', 'main',
			'-pix_fmt', 'yuv420p',
			'-preset', self::PRESET,
			'-crf', self::CRF,
			// the line that makes the rungs interchangeable: a keyframe every
			// SEGMENT_SECONDS at every height, so segment n is the same
			// seconds of video on all of them
			'-force_key_frames', 'expr:gte(t,n_forced*' . self::SEGMENT_SECONDS . ')',
			'-c:a', 'aac',
			'-b:a', ($height <= 480) ? self::AUDIO_BITRATE['small'] : self::AUDIO_BITRATE['large'],
			'-f', 'hls',
			'-hls_time', (string)self::SEGMENT_SECONDS,
			'-hls_playlist_type', 'vod',
			// fragmented MP4 rather than MPEG-TS: what PeerTube writes, what
			// plays natively in Safari, and half the container overhead
			'-hls_segment_type', 'fmp4',
			// single_file is what makes a rung one file; independent_segments
			// tells a player it may start on any of them
			'-hls_flags', 'single_file+independent_segments',
			'-hls_list_size', '0',
			'-hls_segment_filename', $media,
			'-y', $playlist,
		], $output, self::TIMEOUT_SECONDS);

		if (!$ran || !is_file($media) || !is_file($playlist) || filesize($media) < 1) {
			$this->cleanUp([$media, $playlist]);

			return null;
		}

		$text = $this->placeholdered((string)file_get_contents($playlist), basename($media));
		@unlink($playlist);

		if ($text === null) {
			@unlink($media);

			return null;
		}

		$size = (int)filesize($media);

		return [
			'file' => $media,
			'playlist' => $text,
			'size' => $size,
			'bandwidth' => $this->bandwidth($size, $this->durationOf($text)),
		];
	}

	/**
	 * The playlist with the media filename swapped for the placeholder.
	 *
	 * It refuses rather than guesses when the playlist names more than one
	 * file: `single_file` is supposed to produce exactly one, and a playlist
	 * that names several is an ffmpeg that did something else — storing it
	 * would mean a rung that 404s halfway through for every reader.
	 */
	public function placeholdered(string $playlist, string $media): ?string {
		$named = [];
		foreach (explode("\n", $playlist) as $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#')) {
				// the init segment is named in an attribute rather than on a
				// line of its own
				if (preg_match('/#EXT-X-MAP:URI="([^"]+)"/', $line, $matches) === 1) {
					$named[$matches[1]] = true;
				}
				continue;
			}

			$named[$line] = true;
		}

		if (array_keys($named) !== [$media]) {
			$this->logger->warning('a rung was written as more than one file and was dropped', [
				'named' => array_keys($named),
			]);

			return null;
		}

		return str_replace($media, \OCA\Social\Model\VideoRendition::URI_PLACEHOLDER, $playlist);
	}

	/** How long a playlist says its video is, in seconds. */
	public function durationOf(string $playlist): float {
		$duration = 0.0;
		if (preg_match_all('/#EXTINF:([0-9.]+)/', $playlist, $matches) > 0) {
			foreach ($matches[1] as $seconds) {
				$duration += (float)$seconds;
			}
		}

		return $duration;
	}

	/**
	 * What the master playlist advertises for a rung, in bits per second.
	 *
	 * Measured from the file rather than taken from the encoder's target: CRF
	 * encoding has no target, and a player choosing a rung is choosing on how
	 * much it will actually have to fetch.
	 */
	public function bandwidth(int $size, float $duration): int {
		if ($duration <= 0.0) {
			return 0;
		}

		return (int)round(((float)$size * 8.0) / $duration);
	}

	private function ffmpeg(): ?string {
		$path = $this->binaryFinder->findBinaryPath('ffmpeg');

		return ($path === false) ? null : $path;
	}

	private function ffprobe(): ?string {
		$path = $this->binaryFinder->findBinaryPath('ffprobe');

		return ($path === false) ? null : $path;
	}

	/** @param string[] $paths */
	private function cleanUp(array $paths): void {
		foreach ($paths as $path) {
			if (is_file($path)) {
				@unlink($path);
			}
		}
	}

	/**
	 * Runs one of the two binaries with a deadline, keeping its stdout.
	 *
	 * @param string[] $command
	 * @param string $output what it wrote to stdout, which ffprobe answers on
	 *
	 * @return bool whether it ran to completion rather than being killed
	 */
	private function run(array $command, string &$output, int $timeout): bool {
		$output = '';

		try {
			$process = @proc_open(
				array_map('strval', $command),
				[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
				$pipes
			);
		} catch (Throwable $e) {
			$this->logger->debug('could not run ffmpeg', ['exception' => $e]);

			return false;
		}

		if (!is_resource($process)) {
			return false;
		}

		$deadline = time() + $timeout;
		foreach ($pipes as $pipe) {
			stream_set_blocking($pipe, false);
		}

		$status = ['running' => true, 'exitcode' => -1];
		do {
			$status = proc_get_status($process);
			if (!$status['running']) {
				break;
			}

			// drained as it goes: ffmpeg writes progress to stderr for the
			// whole encode, and a full pipe is a process that stops
			$output .= (string)@stream_get_contents($pipes[1]);
			@stream_get_contents($pipes[2]);

			usleep(200000);
		} while (time() < $deadline);

		$killed = $status['running'];
		if ($killed) {
			proc_terminate($process, 9);
			$this->logger->warning('a rung took too long and was killed', ['seconds' => $timeout]);
		} else {
			$output .= (string)@stream_get_contents($pipes[1]);
			@stream_get_contents($pipes[2]);
		}

		foreach ($pipes as $pipe) {
			fclose($pipe);
		}
		proc_close($process);

		return !$killed && $status['exitcode'] === 0;
	}
}
