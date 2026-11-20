<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\VideoTranscodeService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether the videos posted here will play elsewhere. iPhones record `.mov`
 * and many phones write HEVC in `.mp4`; Pixelfed accepts only `video/mp4` and
 * Chrome and Firefox do not play HEVC. `video_transcode`, on by default,
 * converts them to H.264 but needs ffmpeg, which the administrator installs.
 * Switched off with ffmpeg present is a choice, so only information.
 */
class VideoConverter implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#videos-do-not-play-elsewhere';

	public function __construct(
		private IL10N $l10n,
		private ConfigService $configService,
		private VideoTranscodeService $videoTranscodeService,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'config';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: video conversion');
	}

	#[\Override]
	public function run(): SetupResult {
		if (!$this->videoTranscodeService->isAvailable()) {
			return SetupResult::warning(
				$this->l10n->t('ffmpeg was not found, so videos and sound files are stored as they were uploaded, including any location and device data in them. iPhone .mov videos and HEVC videos will not play on other servers — Pixelfed refuses anything that is not an MP4 — or in Chrome and Firefox. Install ffmpeg on this server and Aloha Social removes that data and converts them to H.264 in an MP4 by itself.'),
				self::DOC
			);
		}

		if (!$this->configService->getAppValueBool(ConfigService::SOCIAL_VIDEO_TRANSCODE)) {
			return SetupResult::info(
				$this->l10n->t('ffmpeg is installed, but converting videos is switched off in Administration → Aloha Social → Server. iPhone .mov videos and HEVC videos are stored and sent as they were uploaded, and will not play on other servers or in Chrome and Firefox.'),
				self::DOC
			);
		}

		if (!$this->videoTranscodeService->canProbe()) {
			return SetupResult::success(
				$this->l10n->t('Videos are converted to H.264 in an MP4. ffprobe was not found, so an .mp4 is left as it was uploaded, whatever codec it holds.')
			);
		}

		return SetupResult::success(
			$this->l10n->t('Videos that other servers or browsers would not play are converted to H.264 in an MP4.')
		);
	}
}
