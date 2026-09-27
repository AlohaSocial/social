<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\External;

use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;

/**
 * Takes external users out of every people search that goes through core's
 * collaborator search: the share dialog, mentions, Talk.
 *
 * Registered in `info.xml` for user shares, so it runs after core's own user
 * plugins have filled the result, and removes what they found.
 */
class ExternalCollaboratorPlugin implements ISearchPlugin {
	public function __construct(
		private ExternalUserBackend $userBackend,
	) {
	}

	#[\Override]
	public function search($search, $limit, $offset, ISearchResult $searchResult): bool {
		$type = new SearchResultType('users');
		$found = $searchResult->asArray();

		$uids = [];
		foreach ([$found['users'] ?? [], $found['exact']['users'] ?? []] as $entries) {
			foreach ($entries as $entry) {
				$uid = (string)($entry['value']['shareWith'] ?? '');
				if ($uid !== '' && $this->userBackend->userExists($uid)) {
					$uids[$uid] = true;
				}
			}
		}

		foreach (array_keys($uids) as $uid) {
			$searchResult->removeCollaboratorResult($type, (string)$uid);
		}

		return false;
	}
}
