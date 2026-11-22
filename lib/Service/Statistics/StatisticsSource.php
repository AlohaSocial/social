<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Statistics;

use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * What one network adds to the Statistics tab: where an account lives (for a
 * fediverse account, the host of its id) and where a post went, which
 * instance paths record only for servers it was delivered to.
 */
interface StatisticsSource {
	/**
	 * The server each of these accounts lives on, for the accounts this
	 * network holds; the others are left out.
	 *
	 * @param list<string> $actorIds
	 *
	 * @return array<string, string> host by actor id
	 */
	public function homeServers(array $actorIds): array;

	/**
	 * How many of an account's posts went out on this network as well, which
	 * no instance path records; null when the network is not in use here.
	 *
	 * @param list<string> $postIds the account's posts that were counted
	 *
	 * @return array{network: string, posts: int}|null
	 */
	public function reached(Person $actor, array $postIds): ?array;
}
