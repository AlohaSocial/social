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
 * Turning a video into the one format the rest of the network will play:
 * H.264 in an MP4 with AAC audio, bounded to a height an administrator sets,
 * with `+faststart` so a browser can start playing early. Pixelfed accepts
 * only `video/mp4`, Safari plays no WebM, and HEVC plays only in Safari.
 *
 * On by default where ffmpeg exists, and switchable off since re-encoding is
 * lossy and replaces the original; an H.264 MP4 is never re-encoded. Every
 * upload loses its container metadata (such as GPS) via `withoutMetadata()`,
 * which remuxes without re-encoding. Conversion runs in a background job,
 * never in a request. ffmpeg's protocol whitelist is `file`, and a deadline
 * kills a decoder a crafted file makes spin.
 */
class VideoTranscodeService {
	/** What everything on the network can play, and the only thing Pixelfed accepts. */
	public const TARGET_TYPE = 'video/mp4';

	/**
	 * What is worth converting: the formats this app accepts on upload, except
	 * `video/mp4`, which is already the target.
	 */
	public const CONVERTIBLE = [
		'video/quicktime',
		'video/webm',
		'video/x-matroska',
		'video/ogg',
		'video/x-msvideo',
		'video/mpeg',
	];

	/** The tallest a converted video is written, unless an administrator says otherwise. */
	public const DEFAULT_MAX_HEIGHT = 1080;

	/**
	 * How long ffmpeg gets, per video: generous for a background job, but
	 * finite so a spinning decoder cannot hold a cron worker for ever.
	 */
	private const TIMEOUT_SECONDS = 900;

	/** How long a remux gets: it copies the streams, so it is bounded by the disk, not the CPU. */
	private const REMUX_TIMEOUT_SECONDS = 300;

	/**
	 * The muxer a stored file of each type is rewritten with when its
	 * metadata is taken off. A type that is not here is stored as it came.
	 */
	public const REMUX_FORMATS = [
		'video/mp4' => 'mp4',
		'video/quicktime' => 'mov',
		'video/webm' => 'webm',
		'audio/mpeg' => 'mp3',
		'audio/mp4' => 'mp4',
		'audio/aac' => 'adts',
		'audio/ogg' => 'ogg',
		'audio/opus' => 'ogg',
		'audio/wav' => 'wav',
		'audio/x-wav' => 'wav',
		'audio/flac' => 'flac',
	];

	/**
	 * What every ffmpeg that writes a file is told, so that none of the
	 * source's container metadata — a phone's GPS position and model among
	 * it — is copied into what it writes.
	 */
	public const WITHOUT_METADATA = ['-map_metadata', '-1', '-map_chapters', '-1'];

	/** How long ffprobe gets to read a file's header. */
	private const PROBE_TIMEOUT_SECONDS = 30;

	/**
	 * How fast to encode: `veryfast` is several times quicker than `medium`
	 * for a file a few per cent larger.
	 */
	private const PRESET = 'veryfast';

	/** Constant Rate Factor. 23 is ffmpeg's default and visually transparent enough here. */
	private const CRF = '23';

	public function __construct(
		private IBinaryFinder $binaryFinder,
		private ITempManager $tempManager,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/** Whether this server could convert anything at all. */
	public function isAvailable(): bool {
		return $this->ffmpeg() !== null;
	}

	/** Whether an administrator has asked for it, and it is possible. */
	public function isEnabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_VIDEO_TRANSCODE)
			&& $this->isAvailable();
	}

	/** Whether a file of this type is worth converting, whatever is inside it. */
	public function shouldConvert(string $mimeType): bool {
		return in_array(strtolower($mimeType), self::CONVERTIBLE, true);
	}

	/**
	 * Whether a file of this type might be worth converting, from the type
	 * alone; a new post asks this to decide whether its delivery waits.
	 */
	public function mayNeedConversion(string $mimeType): bool {
		if ($this->shouldConvert($mimeType)) {
			return true;
		}

		return strtolower($mimeType) === self::TARGET_TYPE && $this->canProbe();
	}

	/**
	 * Whether this file has to be converted before it plays everywhere. An
	 * MP4 is converted when its video is not H.264 or ffprobe cannot tell;
	 * without ffprobe an MP4 is left as it is.
	 *
	 * @param string $path a readable path to the video
	 */
	public function needsConversion(string $mimeType, string $path): bool {
		if ($this->shouldConvert($mimeType)) {
			return true;
		}

		if (strtolower($mimeType) !== self::TARGET_TYPE || !$this->canProbe()) {
			return false;
		}

		return $this->videoCodec($path) !== 'h264';
	}

	/** Whether the codec inside an MP4 can be asked about. */
	public function canProbe(): bool {
		return $this->ffprobe() !== null;
	}

	/**
	 * The codec of the first video stream, as ffprobe names it (`h264`,
	 * `hevc`, …).
	 *
	 * @return string|null null when there is no ffprobe, or it could not read
	 *                     the file, or the file has no video stream
	 */
	public function videoCodec(string $path): ?string {
		$ffprobe = $this->ffprobe();
		if ($ffprobe === null || !is_readable($path)) {
			return null;
		}

		$output = '';
		$ran = $this->run([
			$ffprobe,
			'-v', 'error',
			'-protocol_whitelist', 'file',
			'-select_streams', 'v:0',
			'-show_entries', 'stream=codec_name',
			'-of', 'default=noprint_wrappers=1:nokey=1',
			$path,
		], self::PROBE_TIMEOUT_SECONDS, $output);

		$codec = strtolower(trim(strtok($output, "\n") ?: ''));

		return ($ran && $codec !== '') ? $codec : null;
	}

	/** The tallest a converted video is written. */
	public function maxHeight(): int {
		$height = $this->configService->getAppValueInt(ConfigService::SOCIAL_VIDEO_MAX_HEIGHT);

		return ($height > 0) ? $height : self::DEFAULT_MAX_HEIGHT;
	}

	/**
	 * Converts one video, and says where the result is. The source is left
	 * alone for the caller to decide about once the result is checked.
	 *
	 * @param string $path a readable path to the video
	 *
	 * @return string|null the path of the converted file, or null when there
	 *                     is no ffmpeg, the source could not be read, or the
	 *                     conversion produced nothing
	 */
	public function convert(string $path): ?string {
		$ffmpeg = $this->ffmpeg();
		if ($ffmpeg === null || !is_readable($path)) {
			return null;
		}

		$target = $this->tempManager->getTemporaryFile('.mp4');
		if ($target === false) {
			return null;
		}

		$height = $this->maxHeight();
		$ran = $this->run([
			$ffmpeg,
			'-nostdin',
			// a file somebody uploaded is not a playlist, a device or a URL,
			// and ffmpeg will read all three if a container asks it to
			'-protocol_whitelist', 'file',
			'-i', $path,
			// only ever smaller
			'-vf', 'scale=-2:\'min(' . $height . ',ih)\'',
			'-c:v', 'libx264',
			// `high` with yuv420p plays everywhere, including hardware decoders
			// that refuse 4:2:2
			'-profile:v', 'high',
			'-pix_fmt', 'yuv420p',
			'-preset', self::PRESET,
			'-crf', self::CRF,
			'-c:a', 'aac',
			'-b:a', '128k',
			// a video with no audio track at all is not an error
			'-af', 'anull',
			...self::WITHOUT_METADATA,
			// the index at the front, so a browser can start playing before
			// the whole file has arrived
			'-movflags', '+faststart',
			'-y', $target,
		], self::TIMEOUT_SECONDS);

		if (!$ran || !is_file($target) || filesize($target) < 1) {
			@unlink($target);

			return null;
		}

		return $target;
	}

	/**
	 * The same video or sound file without its container metadata (location,
	 * device, title, chapters), with the streams copied, not re-encoded. The
	 * source is left alone, as with `convert()`.
	 *
	 * @param string $path a readable path to the file
	 *
	 * @return string|null the path of the rewritten file, or null when there
	 *                     is no ffmpeg, the type is not one it rewrites, or
	 *                     the rewrite produced nothing
	 */
	public function withoutMetadata(string $path, string $mimeType): ?string {
		$format = self::REMUX_FORMATS[strtolower($mimeType)] ?? null;
		$ffmpeg = $this->ffmpeg();
		if ($format === null || $ffmpeg === null || !is_readable($path)) {
			return null;
		}

		$target = $this->tempManager->getTemporaryFile();
		if ($target === false) {
			return null;
		}

		$ran = $this->run(array_merge([
			$ffmpeg,
			'-nostdin',
			'-protocol_whitelist', 'file',
			'-i', $path,
			...self::WITHOUT_METADATA,
			'-c', 'copy',
		], in_array($format, ['mp4', 'mov'], true) ? ['-movflags', '+faststart'] : [], [
			'-f', $format,
			'-y', $target,
		]), self::REMUX_TIMEOUT_SECONDS);

		if (!$ran || !is_file($target) || filesize($target) < 1) {
			@unlink($target);

			return null;
		}

		return $target;
	}

	private function ffmpeg(): ?string {
		$path = $this->binaryFinder->findBinaryPath('ffmpeg');

		return ($path === false) ? null : $path;
	}

	private function ffprobe(): ?string {
		$path = $this->binaryFinder->findBinaryPath('ffprobe');

		return ($path === false) ? null : $path;
	}

	/**
	 * Runs ffmpeg or ffprobe with a deadline.
	 *
	 * @param string[] $command
	 * @param string $output what it wrote to stdout, which ffprobe answers on
	 *
	 * @return bool whether it ran to completion rather than being killed
	 */
	private function run(array $command, int $timeout, string &$output = ''): bool {
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

			// drain the pipes as it goes: a full pipe stalls ffmpeg
			$output .= (string)@stream_get_contents($pipes[1]);
			@stream_get_contents($pipes[2]);

			usleep(200000);
		} while (time() < $deadline);

		$killed = $status['running'];
		if ($killed) {
			proc_terminate($process, 9);
			$this->logger->warning('a transcode timed out and was killed', [
				'seconds' => $timeout,
			]);
		} else {
			$output .= (string)@stream_get_contents($pipes[1]);
		}

		foreach ($pipes as $pipe) {
			fclose($pipe);
		}
		proc_close($process);

		return !$killed && $status['exitcode'] === 0;
	}
}
