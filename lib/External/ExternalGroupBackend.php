<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\External;

use OCA\Social\Db\ExternalUsersRequest;
use OCP\Group\Backend\ABackend;
use OCP\Group\Backend\ICountUsersBackend;
use OCP\Group\Backend\IGetDisplayNameBackend;
use OCP\Group\Backend\IHideFromCollaborationBackend;
use OCP\Group\Backend\INamedBackend;

/**
 * One read-only group holding every self-registered external user.
 *
 * Core's mandatory two-factor setting is group-based, and an administrator
 * restricting the Social app to groups has to be able to name the externals.
 * Nobody can add or remove members: membership is having a row in
 * `social_ext_user`. Hidden from the share dialog.
 */
class ExternalGroupBackend extends ABackend implements
	ICountUsersBackend,
	IGetDisplayNameBackend,
	IHideFromCollaborationBackend,
	INamedBackend {
	public const GROUP_ID = 'social-external';

	public function __construct(
		private ExternalUserBackend $userBackend,
		private ExternalUsersRequest $usersRequest,
	) {
	}

	#[\Override]
	public function getBackendName(): string {
		return ExternalUserBackend::BACKEND_NAME;
	}

	#[\Override]
	public function inGroup($uid, $gid): bool {
		return $gid === self::GROUP_ID && $this->userBackend->userExists((string)$uid);
	}

	#[\Override]
	public function getUserGroups($uid): array {
		return $this->userBackend->userExists((string)$uid) ? [self::GROUP_ID] : [];
	}

	#[\Override]
	public function getGroups(string $search = '', int $limit = -1, int $offset = 0): array {
		if ($offset > 0) {
			return [];
		}
		if ($search !== '' && stripos(self::GROUP_ID . ' ' . $this->getDisplayName(self::GROUP_ID), $search) === false) {
			return [];
		}

		return [self::GROUP_ID];
	}

	#[\Override]
	public function groupExists($gid): bool {
		return $gid === self::GROUP_ID;
	}

	#[\Override]
	public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0): array {
		if ($gid !== self::GROUP_ID) {
			return [];
		}

		return array_map(
			static fn (array $row): string => $row['uid'],
			$this->usersRequest->search((string)$search, ($limit > 0) ? (int)$limit : null, ($offset > 0) ? (int)$offset : null)
		);
	}

	#[\Override]
	public function countUsersInGroup(string $gid, string $search = ''): int {
		if ($gid !== self::GROUP_ID) {
			return 0;
		}

		return ($search === '') ? $this->usersRequest->count() : count($this->usersRequest->search($search));
	}

	#[\Override]
	public function getDisplayName(string $gid): string {
		return ($gid === self::GROUP_ID) ? 'Aloha Social external users' : $gid;
	}

	#[\Override]
	public function hideGroup(string $groupId): bool {
		return $groupId === self::GROUP_ID;
	}
}
