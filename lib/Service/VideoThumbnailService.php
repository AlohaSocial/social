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
 * One frame out of a local video, as a poster to show before play;
 * federated PeerTube videos get theirs from PeerTube (see `PeerTubeService`).
 *
 * Not transcoding: one frame is decoded into a small JPEG and the video is
 * stored as uploaded. Uses ffmpeg when present and is skipped otherwise;
 * everything downstream copes with a missing poster.
 */
class VideoThumbnailService {
	/** What a poster is written as, and served as. */
	public const MEDIA_TYPE = 'image/jpeg';

	/**
	 * Where in the video to take the frame from: one second in, as PeerTube
	 * and most players do, since videos often open on black or a fade.
	 */
	private const SEEK_SECONDS = 1;

	/** ffmpeg gets this long before it is killed, per video. */
	private const TIMEOUT_SECONDS = 20;

	/** The widest a poster is written; the height follows the aspect. */
	private const MAX_WIDTH = 1280;

	public function __construct(
		private IBinaryFinder $binaryFinder,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/** Whether this server can make one at all. */
	public function isAvailable(): bool {
		return $this->ffmpeg() !== null;
	}

	/**
	 * A JPEG of one frame, the video's own dimensions, and its duration
	 * (from ffprobe, optional, so ffmpeg alone still yields a poster).
	 *
	 * @param string $path a readable path to the video
	 *
	 * @return array{content: string, width: int, height: int, duration: int}|null
	 *                                                                             null when there is no ffmpeg, or it could not read the file
	 */
	public function poster(string $path): ?array {
		$ffmpeg = $this->ffmpeg();
		if ($ffmpeg === null || !is_readable($path)) {
			return null;
		}

		$target = $this->tempManager->getTemporaryFile('.jpg');
		if ($target === false) {
			return null;
		}

		// `-ss` before `-i` seeks by keyframe, which is fast and enough for a
		// still; a video shorter than the seek point is retried from the start
		foreach ([self::SEEK_SECONDS, 0] as $seek) {
			if ($this->extract($ffmpeg, $path, $target, $seek)) {
				$content = @file_get_contents($target);
				@unlink($target);

				if ($content === false || $content === '') {
					return null;
				}

				$size = @getimagesizefromstring($content);

				return [
					'content' => $content,
					'width' => ($size === false) ? 0 : $size[0],
					'height' => ($size === false) ? 0 : $size[1],
					'duration' => $this->duration($path),
				];
			}
		}

		@unlink($target);

		return null;
	}

	/**
	 * How long the video runs, in whole seconds.
	 *
	 * @return int 0 when there is no ffprobe or it could not say
	 */
	public function duration(string $path): int {
		$ffprobe = $this->binaryFinder->findBinaryPath('ffprobe');
		if ($ffprobe === false) {
			return 0;
		}

		$target = $this->tempManager->getTemporaryFile('.txt');
		if ($target === false) {
			return 0;
		}

		$ran = $this->run([
			$ffprobe,
			'-v', 'error',
			// the same refusal the poster makes: a file somebody uploaded is
			// not a playlist, a device or a URL
			'-protocol_whitelist', 'file',
			'-show_entries', 'format=duration',
			'-of', 'default=noprint_wrappers=1:nokey=1',
			'-o', $target,
			$path,
		]);

		$seconds = $ran ? (float)trim((string)@file_get_contents($target)) : 0.0;
		@unlink($target);

		return ($seconds > 0) ? (int)round($seconds) : 0;
	}

	/**
	 * @return bool whether a frame was written
	 */
	private function extract(string $ffmpeg, string $source, string $target, int $seek): bool {
		$ran = $this->run([
			$ffmpeg,
			'-nostdin',
			// a file somebody uploaded is not a playlist, a device or a URL,
			// and ffmpeg will happily read all three if a container asks it to
			'-protocol_whitelist', 'file',
			'-ss', (string)$seek,
			'-i', $source,
			'-frames:v', '1',
			'-vf', 'scale=\'min(' . self::MAX_WIDTH . ',iw)\':-2',
			...VideoTranscodeService::WITHOUT_METADATA,
			'-f', 'image2',
			'-y', $target,
		]);

		return $ran && is_file($target) && filesize($target) > 0;
	}

	/**
	 * Runs one of the two binaries, with a deadline.
	 *
	 * @param string[] $command
	 *
	 * @return bool whether it ran to completion rather than being killed
	 */
	private function run(array $command): bool {
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

		$deadline = time() + self::TIMEOUT_SECONDS;
		foreach ($pipes as $pipe) {
			stream_set_blocking($pipe, false);
		}

		do {
			$status = proc_get_status($process);
			if (!$status['running']) {
				break;
			}

			// the pipes are drained as it goes: ffmpeg writes what it has to
			// say to stderr, and a full pipe is a process that stops rather
			// than one that finishes
			foreach ($pipes as $pipe) {
				@stream_get_contents($pipe);
			}

			usleep(50000);
		} while (time() < $deadline);

		$killed = $status['running'];
		if ($killed) {
			// a file crafted to make a decoder spin must not hold a request open
			proc_terminate($process, 9);
			$this->logger->warning('a video tool timed out and was killed', ['tool' => $command[0]]);
		}

		foreach ($pipes as $pipe) {
			fclose($pipe);
		}
		proc_close($process);

		return !$killed;
	}

	/** @return string|null the path to ffmpeg, or null when the server has none */
	private function ffmpeg(): ?string {
		$path = $this->binaryFinder->findBinaryPath('ffmpeg');

		return ($path === false) ? null : $path;
	}
}
