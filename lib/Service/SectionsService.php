<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Exceptions\SettingRefusedException;
use OCP\IGroupManager;

/**
 * What this instance offers: the sections of the app, and the Nextcloud groups
 * that become lists. Separate from ServerSettingsService, which governs
 * federation. Turning a section off hides it; existing posts stay and show
 * again when it is turned back on.
 */
class SectionsService {
	/** The keys this card owns, in the order the card shows them. */
	public const KEYS = [
		ConfigService::SOCIAL_STORIES,
		ConfigService::SOCIAL_SECTION_PHOTOS,
		ConfigService::SOCIAL_SECTION_VIDEOS,
		ConfigService::SOCIAL_GROUP_LISTS,
		ConfigService::SOCIAL_GIF_PACK,
	];

	/**
	 * More than this many groups chosen and the list is not a choice any more.
	 * It is also the point past which every member's sidebar is unusable.
	 */
	public const MAX_GROUPS = 100;

	public function __construct(
		private ConfigService $configService,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * What stands now, typed the way the page and the endpoint hand it back.
	 *
	 * `gif_pack_source` and `has_internet_connection` are not settings of the
	 * card: they tell the administrator where the pack comes from, and that
	 * Nextcloud's own setting keeps it from being fetched.
	 *
	 * @return array{stories: bool, section_photos: bool, section_videos: bool,
	 *     group_lists: string[], gif_pack: bool, gif_pack_source: string,
	 *     has_internet_connection: bool}
	 */
	public function current(): array {
		return [
			ConfigService::SOCIAL_STORIES => $this->configService->getAppValueBool(ConfigService::SOCIAL_STORIES),
			ConfigService::SOCIAL_SECTION_PHOTOS
				=> $this->configService->getAppValueBool(ConfigService::SOCIAL_SECTION_PHOTOS),
			ConfigService::SOCIAL_SECTION_VIDEOS
				=> $this->configService->getAppValueBool(ConfigService::SOCIAL_SECTION_VIDEOS),
			ConfigService::SOCIAL_GROUP_LISTS => $this->groupLists(),
			ConfigService::SOCIAL_GIF_PACK => $this->configService->getAppValueBool(ConfigService::SOCIAL_GIF_PACK),
			'gif_pack_source' => GifPackService::sourceHost(),
			'has_internet_connection' => $this->configService->hasInternetConnection(),
		];
	}

	/**
	 * The groups that become lists, in the order chosen. Deleted groups are
	 * dropped on read but not from the stored JSON, as the administrator may
	 * recreate them.
	 *
	 * @return string[] group ids, in the order they were chosen
	 */
	public function groupLists(): array {
		$stored = json_decode((string)$this->configService->getAppValue(ConfigService::SOCIAL_GROUP_LISTS), true);
		if (!is_array($stored)) {
			return [];
		}

		$groups = [];
		foreach ($stored as $groupId) {
			if (is_string($groupId) && $this->groupManager->groupExists($groupId)) {
				$groups[] = $groupId;
			}
		}

		return $groups;
	}

	/**
	 * Whether a group's members get a list for it.
	 *
	 * @param string $groupId the group to ask about
	 */
	public function groupHasList(string $groupId): bool {
		return in_array($groupId, $this->groupLists(), true);
	}

	/** Whether stories are offered at all. */
	public function storiesEnabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_STORIES);
	}

	/**
	 * Validates every value and writes them all, or none, so a form is never
	 * half saved.
	 *
	 * @param bool $stories whether shorts (stories) are offered
	 * @param bool $photos whether the Photos timeline is offered
	 * @param bool $videos whether the Videos timeline is offered
	 * @param string[] $groupLists the Nextcloud groups that become lists
	 * @param bool|null $gifPack whether the composer offers the animated emoji pack; null leaves it
	 *
	 * @return array what current() answers afterwards
	 * @throws InvalidArgumentException naming the field that was refused; SettingRefusedException with its code
	 */
	public function save(bool $stories, bool $photos, bool $videos, array $groupLists, ?bool $gifPack = null): array {
		$groups = [];
		foreach ($groupLists as $groupId) {
			if (!is_string($groupId)) {
				throw new InvalidArgumentException('group_lists must be a list of group ids');
			}
			$groupId = trim($groupId);
			if ($groupId === '' || in_array($groupId, $groups, true)) {
				continue;
			}
			// refused rather than dropped: an id naming no group is a mistake,
			// and saving only part of the selection is what this avoids
			if (!$this->groupManager->groupExists($groupId)) {
				throw new SettingRefusedException('no such group: ' . $groupId, 'group-unknown', null, ['group' => $groupId]);
			}
			$groups[] = $groupId;
		}

		if (count($groups) > self::MAX_GROUPS) {
			throw new SettingRefusedException(
				'at most ' . self::MAX_GROUPS . ' groups may become lists', 'groups-too-many', null, ['max' => self::MAX_GROUPS]
			);
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_STORIES, $stories ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_SECTION_PHOTOS, $photos ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_SECTION_VIDEOS, $videos ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_GROUP_LISTS, json_encode($groups));
		if ($gifPack !== null) {
			$this->configService->setAppValue(ConfigService::SOCIAL_GIF_PACK, $gifPack ? '1' : '0');
		}

		return $this->current();
	}
}
