<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Settings\Service\AuthorizedGroupService;
use OCA\Social\Settings\AdminSection;
use OCA\Social\Settings\AdminSettings;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who may moderate here, and who has to be told about a report: a Nextcloud
 * administrator or whoever the Social settings section is delegated to, the
 * same rule the moderation routes enforce through `AuthorizedAdminSetting`.
 * Everything asking that question asks this, so there is one list.
 */
class ModeratorService {
	public function __construct(
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ISettingsManager $settingsManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	public function isModerator(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId)) {
			return true;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return false;
		}

		return $this->settingsManager->getAllowedAdminSettings(
			AdminSection::SECTION_ID, $user
		) !== [];
	}

	/**
	 * Every user who may act on a report, read from the groups that hold the
	 * right rather than by asking every account, since anything that reaches
	 * the inbox can file a report.
	 *
	 * @return string[] user ids, each once
	 */
	public function moderators(): array {
		$userIds = [];
		foreach (array_merge(['admin'], $this->delegatedGroups()) as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$userIds[$user->getUID()] = true;
			}
		}

		return array_keys($userIds);
	}

	/**
	 * The groups the Social settings section has been delegated to, from the
	 * settings app (no `OCP` interface answers this); if the lookup fails,
	 * only administrators are told.
	 *
	 * @return string[] group ids
	 */
	private function delegatedGroups(): array {
		try {
			/** @var AuthorizedGroupService $authorized */
			$authorized = $this->container->get(AuthorizedGroupService::class);
			$groups = [];
			foreach ($authorized->findExistingGroupsForClass(AdminSettings::class) as $group) {
				$groups[] = $group->getGroupId();
			}

			return $groups;
		} catch (Throwable $e) {
			$this->logger->debug('cannot read who the Social settings are delegated to', [
				'exception' => $e,
			]);

			return [];
		}
	}
}
