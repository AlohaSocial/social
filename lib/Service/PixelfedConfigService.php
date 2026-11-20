<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Story;

/**
 * What `/api/v2/config` answers: the numbers and switches the Pixelfed app
 * reads on launch before it draws anything, deciding how many pictures may be
 * picked, how long a caption may be, and which screens to offer.
 *
 * Every limit is derived from what the server enforces, never restated, so
 * the client cannot allow what the server refuses. The feature switches say
 * what this app does, so the app draws no empty screens.
 */
class PixelfedConfigService {
	/**
	 * What a caption may be: the status limit `/api/v1/instance` reports, also
	 * Pixelfed's default.
	 */
	private const MAX_CAPTION_LENGTH = InstanceService::MAX_CHARACTERS;

	/** What an alt text may be, matching the column the API writes it to. */
	public const MAX_ALTTEXT_LENGTH = 1500;

	/** What a bio may be. */
	private const MAX_BIO_LENGTH = 500;

	/** What a display name may be. */
	private const MAX_NAME_LENGTH = 255;

	public function __construct(
		private InstanceService $instanceService,
		private ConfigService $configService,
	) {
	}

	/** Whether this instance shrinks what is uploaded to it. */
	private function optimisesImages(): bool {
		return $this->configService->getAppValueInt(ConfigService::SOCIAL_IMAGE_MAX_EDGE) > 0;
	}

	/**
	 * @return array the config object, in Pixelfed's shape
	 */
	public function config(): array {
		$instance = $this->instanceService->getLocal();
		$maxUpload = $this->instanceService->imageSizeLimit();

		return [
			// accounts here come from Nextcloud users, so the app must not offer a
			// sign-up form
			'open_registration' => false,
			'uploader' => [
				// Pixelfed counts in kilobytes here, which is the one place its
				// shape differs from Mastodon's bytes
				'max_photo_size' => intdiv($maxUpload, 1024),
				'max_caption_length' => self::MAX_CAPTION_LENGTH,
				'max_altext_length' => self::MAX_ALTTEXT_LENGTH,
				'album_limit' => Stream::MAX_ATTACHMENTS,
				// A client may skip its own compression on these, so they say what this
				// instance does: pictures are re-encoded only to rotate them or when
				// `image_max_edge` is set, which these two report.
				'image_quality' => $this->optimisesImages()
					? $this->configService->getAppValueInt(ConfigService::SOCIAL_IMAGE_QUALITY) : 100,
				'optimize_image' => $this->optimisesImages(),
				// video is never re-encoded here, whatever the setting
				'optimize_video' => false,
				'media_types' => implode(',', $this->instanceService->supportedMimeTypes()),
				'max_collection_length' => CollectionsRequest::MAX_ITEMS,
			],
			'site' => [
				'name' => $instance->getTitle(),
				'domain' => $instance->getUri(),
				'url' => $this->configService->getCloudUrl(),
				'description' => $instance->getShortDescription(),
			],
			'account' => [
				'max_bio_length' => self::MAX_BIO_LENGTH,
				'max_name_length' => self::MAX_NAME_LENGTH,
			],
			'features' => [
				// what this app does
				'albums' => true,
				'collections' => true,
				'stories' => true,
				'places' => true,
				'hashtags' => true,
				'video' => true,
				// the timelines take `only_short`, by the rule in `limits`
				'shorts' => true,
				'polls' => true,
				'bookmarks' => true,
				'mobile_apis' => true,
				// and what it does not, stated explicitly
				'circles' => false,
				'direct_messages' => true,
				'live_streaming' => false,
				'push_notifications' => false,
				'stories_reactions' => true,
			],
			'limits' => [
				'max_album_length' => Stream::MAX_ATTACHMENTS,
				'max_caption_length' => self::MAX_CAPTION_LENGTH,
				'max_altext_length' => self::MAX_ALTTEXT_LENGTH,
				'story_length' => Story::MAX_PER_ACTOR,
				'story_lifetime' => Story::LIFETIME,
				// what `only_short` counts as a short: a single video no
				// longer than this many seconds, and no wider than this
				// width-to-height ratio
				'short_max_duration' => Stream::SHORT_MAX_SECONDS,
				'short_max_aspect_ratio' => Stream::SHORT_MAX_ASPECT,
			],
			'activitypub' => [
				'enabled' => true,
				'remote_follow' => true,
			],
		];
	}
}
