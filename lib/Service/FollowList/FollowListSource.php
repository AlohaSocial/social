<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\FollowList;

use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * Who follows an account on another server, and whom it follows, as the
 * network it lives on knows it (`FollowListService`).
 */
interface FollowListSource {
	public function supports(Person $account): bool;

	/**
	 * The accounts that follow it (`FollowListService::FOLLOWERS`) or that it
	 * follows (`FollowListService::FOLLOWING`), as actor ids in the order its
	 * network lists them; the ones this server can show are cached as they
	 * are found, or fetched in the background.
	 *
	 * @return list<string>|null null when the account keeps the list to itself
	 */
	public function accounts(Person $account, string $direction, int $limit): ?array;
}
