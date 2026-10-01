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
 * Whether the videos posted here will play anywhere else.
 *
 * An iPhone records `.mov`, and Android phones — and iPhones exporting through
 * some apps — write HEVC inside an `.mp4`. **Pixelfed accepts `video/mp4` and
 * nothing else**, and drops the rest on arrival; Chrome and Firefox do not play
 * HEVC. Converting them to H.264 is what `video_transcode` does, it is on by
 * default, and it needs ffmpeg — which is a package the administrator installs
 * and this app cannot. Without it nothing says so: the upload works, the video
 * plays in Safari here, and it simply never arrives anywhere that matters.
 *
 * Switched off with ffmpeg present is a decision, so it is said as
 * information rather than as a warning.
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
				$this->l10n->t('ffmpeg was not found, so videos are stored as they were uploaded. iPhone .mov videos and HEVC videos will not play on other servers — Pixelfed refuses anything that is not an MP4 — or in Chrome and Firefox. Install ffmpeg on this server and Aloha Social converts them to H.264 in an MP4 by itself.'),
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
