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
 * reads once, on launch, before it will draw anything.
 *
 * It is a bootstrap call, not a feature. The app asks for it first and uses the
 * answer to decide how many pictures its picker may select, how long a caption
 * may be, and which screens to offer at all -- so a wrong number here is a
 * client that lets somebody compose a post the server will refuse, and a missing
 * one is an app that will not start.
 *
 * **Every value is derived, never restated.** The ceilings come from the same
 * constants the server enforces and the mime list is asked of the one place that
 * decides what an upload may be. A second copy of any of them would drift, and
 * the failure that causes is the worst kind: the client says yes and the server
 * says no, with nothing in between to explain it.
 *
 * The feature switches say what this app actually does, not what Pixelfed does.
 * Announcing a screen that is not there is worse than not announcing it: the app
 * draws the tab, the reader taps it, and it is empty for a reason nobody can
 * see.
 */
class PixelfedConfigService {
	/**
	 * What a caption may be, which is Mastodon's status limit here and is the
	 * same number `/api/v1/instance` reports. Pixelfed's own default is 500 as
	 * well.
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
			// Registration is Nextcloud's business, not this app's: an account
			// here exists because a Nextcloud user does. Saying otherwise would
			// have the app offer a sign-up form that cannot work.
			'open_registration' => false,
			'uploader' => [
				// Pixelfed counts in kilobytes here, which is the one place its
				// shape differs from Mastodon's bytes
				'max_photo_size' => intdiv($maxUpload, 1024),
				'max_caption_length' => self::MAX_CAPTION_LENGTH,
				'max_altext_length' => self::MAX_ALTTEXT_LENGTH,
				'album_limit' => Stream::MAX_ATTACHMENTS,
				// A client may act on these by skipping its own compression, so
				// they say what this instance actually does. Nothing is
				// recompressed unless an administrator asked for it: metadata
				// is stripped losslessly and a picture is re-encoded only when
				// it has to be rotated — or when `image_max_edge` is set, which
				// is the case these two report.
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
				'polls' => true,
				'bookmarks' => true,
				'mobile_apis' => true,
				// and what it does not, said out loud rather than left for a
				// client to discover by tapping
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
			],
			'activitypub' => [
				'enabled' => true,
				'remote_follow' => true,
			],
		];
	}
}
