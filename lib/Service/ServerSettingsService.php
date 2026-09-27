<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;

/**
 * The instance-wide settings an administrator can change from the settings
 * page, and the bounds each one is held to.
 *
 * Every one of these existed as an app config key and could be set with `occ
 * config:app:set`, which is where they stayed: nothing on the page read or
 * wrote them, so an administrator learnt of `secure_mode` from a peer's
 * complaint and of `max_size` from a user's failed upload. Read and written
 * here in one place so the page, the endpoint and the tests agree on what a
 * value may be.
 */
class ServerSettingsService {
	/** The longest an instance description may be; Mastodon's own ceiling. */
	public const MAX_DESCRIPTION = 10000;

	/** Megabytes; past this a picture is a video, and past ten gigabytes a mistake. */
	public const MAX_SIZE_MB = 10240;

	/** Megabytes; a hundred gigabytes is past anything a peer will accept. */
	public const MAX_VIDEO_SIZE_MB = 102400;

	/** Inbox requests per origin host per minute; 0 disables the throttle. */
	public const MAX_INBOX_THROTTLE = 100000;

	/**
	 * The longest edge a stored picture may be held to. Below the minimum it
	 * is a thumbnail rather than a photograph; above the maximum it is not a
	 * ceiling anybody's camera reaches.
	 */
	public const MIN_IMAGE_EDGE = 480;
	public const MAX_IMAGE_EDGE = 16384;

	/** Past this far down, a re-encoded photograph is visibly worse. */
	public const MIN_IMAGE_QUALITY = 40;

	/** The keys this page owns, in the order the page shows them. */
	public const KEYS = [
		ConfigService::CONTACT_EMAIL,
		ConfigService::SOCIAL_CONTACT_ACCOUNT,
		ConfigService::SOCIAL_EXTENDED_DESCRIPTION,
		ConfigService::SOCIAL_MAX_SIZE,
		ConfigService::SOCIAL_MAX_VIDEO_SIZE,
		ConfigService::SOCIAL_IMAGE_MAX_EDGE,
		ConfigService::SOCIAL_IMAGE_QUALITY,
		ConfigService::SOCIAL_VIDEO_TRANSCODE,
		ConfigService::SOCIAL_VIDEO_MAX_HEIGHT,
		ConfigService::SOCIAL_VIDEO_LADDER,
		ConfigService::SOCIAL_VIDEO_LADDER_HEIGHTS,
		ConfigService::SOCIAL_VIDEO_QUOTA,
		ConfigService::SOCIAL_NSFW_POLICY,
		ConfigService::SOCIAL_INBOX_THROTTLE,
		ConfigService::SOCIAL_SECURE_MODE,
		ConfigService::SOCIAL_PUBLISH_BLOCKS,
		ConfigService::SOCIAL_SELF_SIGNED,
	];

	/** 480p is the floor worth converting to; 4K is the ceiling worth storing. */
	private const MIN_VIDEO_HEIGHT = 240;
	private const MAX_VIDEO_HEIGHT = 2160;

	public function __construct(
		private ConfigService $configService,
		private ActorsRequest $actorsRequest,
	) {
	}

	/**
	 * What stands now, typed the way the page and the endpoint hand it back.
	 *
	 * @return array{
	 *     contact_email: string,
	 *     contact_account: string,
	 *     extended_description: string,
	 *     max_size: int,
	 *     max_video_size: int,
	 *     image_max_edge: int,
	 *     image_quality: int,
	 *     video_transcode: bool,
	 *     video_max_height: int,
	 *     video_ladder: bool,
	 *     video_ladder_heights: string,
	 *     video_quota: int,
	 *     nsfw_policy: string,
	 *     inbox_throttle: int,
	 *     secure_mode: bool,
	 *     publish_blocks: bool,
	 *     allow_self_signed: bool
	 * }
	 */
	public function current(): array {
		$contactAccount = '';
		$contactUserId = (string)$this->configService->getAppValue(ConfigService::SOCIAL_CONTACT_ACCOUNT);
		if ($contactUserId !== '') {
			try {
				$actor = $this->actorsRequest->getFromUserId($contactUserId);
				if ($actor->isLocal()) {
					$contactAccount = $actor->getPreferredUsername();
				}
			} catch (ActorDoesNotExistException|SocialAppConfigException $e) {
				// An account removed since the setting was saved is no longer a
				// contact. The administrator can choose another one in the form.
			}
		}

		return [
			ConfigService::CONTACT_EMAIL => (string)$this->configService->getAppValue(ConfigService::CONTACT_EMAIL),
			ConfigService::SOCIAL_CONTACT_ACCOUNT => $contactAccount,
			ConfigService::SOCIAL_EXTENDED_DESCRIPTION
				=> (string)$this->configService->getAppValue(ConfigService::SOCIAL_EXTENDED_DESCRIPTION),
			ConfigService::SOCIAL_MAX_SIZE => $this->configService->getAppValueInt(ConfigService::SOCIAL_MAX_SIZE),
			ConfigService::SOCIAL_MAX_VIDEO_SIZE
				=> $this->configService->getAppValueInt(ConfigService::SOCIAL_MAX_VIDEO_SIZE),
			ConfigService::SOCIAL_IMAGE_MAX_EDGE
				=> $this->configService->getAppValueInt(ConfigService::SOCIAL_IMAGE_MAX_EDGE),
			ConfigService::SOCIAL_IMAGE_QUALITY
				=> $this->configService->getAppValueInt(ConfigService::SOCIAL_IMAGE_QUALITY),
			ConfigService::SOCIAL_VIDEO_TRANSCODE
				=> $this->configService->getAppValueBool(ConfigService::SOCIAL_VIDEO_TRANSCODE),
			ConfigService::SOCIAL_VIDEO_MAX_HEIGHT
				=> $this->configService->getAppValueInt(ConfigService::SOCIAL_VIDEO_MAX_HEIGHT),
			ConfigService::SOCIAL_VIDEO_LADDER
				=> $this->configService->getAppValueBool(ConfigService::SOCIAL_VIDEO_LADDER),
			ConfigService::SOCIAL_VIDEO_LADDER_HEIGHTS
				=> (string)$this->configService->getAppValue(ConfigService::SOCIAL_VIDEO_LADDER_HEIGHTS),
			ConfigService::SOCIAL_VIDEO_QUOTA
				=> $this->configService->getAppValueInt(ConfigService::SOCIAL_VIDEO_QUOTA),
			ConfigService::SOCIAL_NSFW_POLICY
				=> (string)$this->configService->getAppValue(ConfigService::SOCIAL_NSFW_POLICY),
			ConfigService::SOCIAL_INBOX_THROTTLE
				=> $this->configService->getAppValueInt(ConfigService::SOCIAL_INBOX_THROTTLE),
			// the two switches other code reads as `=== '1'`, and the one it
			// reads leniently, all answer through the same lenient reader here
			ConfigService::SOCIAL_SECURE_MODE => $this->configService->getAppValueBool(ConfigService::SOCIAL_SECURE_MODE),
			ConfigService::SOCIAL_PUBLISH_BLOCKS
				=> $this->configService->getAppValueBool(ConfigService::SOCIAL_PUBLISH_BLOCKS),
			ConfigService::SOCIAL_SELF_SIGNED => $this->configService->getAppValueBool(ConfigService::SOCIAL_SELF_SIGNED),
		];
	}

	/**
	 * Validates every value and writes them all, or writes none.
	 *
	 * All-or-nothing on purpose: a form that saved six of its eight fields
	 * and refused two leaves the administrator guessing which took.
	 *
	 * @param string $contactEmail empty, or an address
	 * @param int $maxSize megabytes, 1 to MAX_SIZE_MB
	 * @param int $maxVideoSize megabytes, 1 to MAX_VIDEO_SIZE_MB
	 * @param int $videoQuota megabytes of video one account may keep; 0 is no quota
	 * @param string $nsfwPolicy what to do with sensitive media for readers who have not chosen
	 * @param int $inboxThrottle requests per host per minute, 0 to MAX_INBOX_THROTTLE
	 *
	 * @return array what current() answers afterwards
	 * @throws InvalidArgumentException naming the field that was refused
	 */
	public function save(
		string $contactEmail,
		string $extendedDescription,
		int $maxSize,
		int $maxVideoSize,
		int $imageMaxEdge,
		int $imageQuality,
		bool $videoTranscode,
		int $videoMaxHeight,
		bool $videoLadder,
		string $videoLadderHeights,
		int $videoQuota,
		string $nsfwPolicy,
		int $inboxThrottle,
		bool $secureMode,
		bool $publishBlocks,
		bool $allowSelfSigned,
		string $contactAccount = '',
	): array {
		$contactEmail = trim($contactEmail);
		if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false) {
			throw new InvalidArgumentException('contact_email is not an email address');
		}
		if (strlen($contactEmail) > 255) {
			throw new InvalidArgumentException('contact_email is too long');
		}

		$contactAccount = trim(ltrim(trim($contactAccount), '@'));
		$contactUserId = '';
		if ($contactAccount !== '') {
			try {
				$actor = $this->actorsRequest->getFromUsername($contactAccount);
			} catch (ActorDoesNotExistException|SocialAppConfigException $e) {
				throw new InvalidArgumentException('contact_account must be a local Social account', 0, $e);
			}
			if (!$actor->isLocal() || $actor->getUserId() === '') {
				throw new InvalidArgumentException('contact_account must be a local Social account');
			}
			$contactUserId = $actor->getUserId();
		}

		$extendedDescription = trim($extendedDescription);
		if (mb_strlen($extendedDescription) > self::MAX_DESCRIPTION) {
			throw new InvalidArgumentException(
				'extended_description is longer than ' . self::MAX_DESCRIPTION . ' characters'
			);
		}

		if ($maxSize < 1 || $maxSize > self::MAX_SIZE_MB) {
			throw new InvalidArgumentException('max_size must be between 1 and ' . self::MAX_SIZE_MB . ' MB');
		}
		if ($maxVideoSize < 1 || $maxVideoSize > self::MAX_VIDEO_SIZE_MB) {
			throw new InvalidArgumentException(
				'max_video_size must be between 1 and ' . self::MAX_VIDEO_SIZE_MB . ' MB'
			);
		}
		// 0 is "store every upload exactly as it arrived", which is the default
		// and the only value that loses nothing
		if ($imageMaxEdge !== 0 && ($imageMaxEdge < self::MIN_IMAGE_EDGE || $imageMaxEdge > self::MAX_IMAGE_EDGE)) {
			throw new InvalidArgumentException(
				'image_max_edge must be 0, or between ' . self::MIN_IMAGE_EDGE . ' and ' . self::MAX_IMAGE_EDGE . ' pixels'
			);
		}

		if ($imageQuality < self::MIN_IMAGE_QUALITY || $imageQuality > 100) {
			throw new InvalidArgumentException(
				'image_quality must be between ' . self::MIN_IMAGE_QUALITY . ' and 100'
			);
		}

		if ($videoMaxHeight < self::MIN_VIDEO_HEIGHT || $videoMaxHeight > self::MAX_VIDEO_HEIGHT) {
			throw new InvalidArgumentException(
				'video_max_height must be between ' . self::MIN_VIDEO_HEIGHT
				. ' and ' . self::MAX_VIDEO_HEIGHT . ' pixels'
			);
		}

		// Cleaned rather than refused, because what an administrator types
		// here is a list and the mistakes are commas: an entry that is not a
		// height in range is dropped, and a list with nothing usable left in
		// it is the one thing worth refusing, since silently falling back to
		// the default would be a page that says one thing and a server that
		// does another.
		$heights = [];
		foreach (explode(',', $videoLadderHeights) as $part) {
			$height = (int)trim($part);
			if ($height >= VideoLadderService::MIN_HEIGHT && $height <= VideoLadderService::MAX_HEIGHT) {
				$heights[] = $height;
			}
		}
		$heights = array_values(array_unique($heights));
		sort($heights);
		if ($heights === []) {
			throw new InvalidArgumentException(
				'video_ladder_heights must be a comma-separated list of heights between '
				. VideoLadderService::MIN_HEIGHT . ' and ' . VideoLadderService::MAX_HEIGHT
			);
		}

		// 0 is "no quota", which is the default and what every instance
		// running today has in effect
		if ($videoQuota < 0 || $videoQuota > VideoQuotaService::MAX_QUOTA_MB) {
			throw new InvalidArgumentException(
				'video_quota must be 0, or a size in MB up to ' . VideoQuotaService::MAX_QUOTA_MB
			);
		}

		// Refused rather than cleaned, unlike the ladder heights: this is a
		// choice from three and not a list somebody types, so a value that is
		// not one of the three is a client sending something else and not a
		// person mistyping.
		if (!in_array($nsfwPolicy, SensitiveMediaService::POLICIES, true)) {
			throw new InvalidArgumentException(
				'nsfw_policy must be one of ' . implode(', ', SensitiveMediaService::POLICIES)
			);
		}

		if ($inboxThrottle < 0 || $inboxThrottle > self::MAX_INBOX_THROTTLE) {
			throw new InvalidArgumentException(
				'inbox_throttle must be between 0 and ' . self::MAX_INBOX_THROTTLE
			);
		}

		$this->configService->setAppValue(ConfigService::CONTACT_EMAIL, $contactEmail);
		$this->configService->setAppValue(ConfigService::SOCIAL_CONTACT_ACCOUNT, $contactUserId);
		$this->configService->setAppValue(ConfigService::SOCIAL_EXTENDED_DESCRIPTION, $extendedDescription);
		$this->configService->setAppValue(ConfigService::SOCIAL_MAX_SIZE, (string)$maxSize);
		$this->configService->setAppValue(ConfigService::SOCIAL_MAX_VIDEO_SIZE, (string)$maxVideoSize);
		$this->configService->setAppValue(ConfigService::SOCIAL_IMAGE_MAX_EDGE, (string)$imageMaxEdge);
		$this->configService->setAppValue(ConfigService::SOCIAL_IMAGE_QUALITY, (string)$imageQuality);
		$this->configService->setAppValue(
			ConfigService::SOCIAL_VIDEO_TRANSCODE, $videoTranscode ? '1' : '0'
		);
		$this->configService->setAppValue(ConfigService::SOCIAL_VIDEO_MAX_HEIGHT, (string)$videoMaxHeight);
		$this->configService->setAppValue(ConfigService::SOCIAL_VIDEO_LADDER, $videoLadder ? '1' : '0');
		$this->configService->setAppValue(
			ConfigService::SOCIAL_VIDEO_LADDER_HEIGHTS, implode(',', $heights)
		);
		$this->configService->setAppValue(ConfigService::SOCIAL_VIDEO_QUOTA, (string)$videoQuota);
		$this->configService->setAppValue(ConfigService::SOCIAL_NSFW_POLICY, $nsfwPolicy);
		$this->configService->setAppValue(ConfigService::SOCIAL_INBOX_THROTTLE, (string)$inboxThrottle);
		// written as the literal `1`/`0`: AuthorizedFetchService and the
		// domain-blocks route compare against '1', not against "truthy"
		$this->configService->setAppValue(ConfigService::SOCIAL_SECURE_MODE, $secureMode ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_PUBLISH_BLOCKS, $publishBlocks ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_SELF_SIGNED, $allowSelfSigned ? '1' : '0');

		return $this->current();
	}
}
