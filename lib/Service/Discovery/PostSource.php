<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Discovery;

use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * Where posts with a hashtag, or matching a search, can be found beyond
 * this server (`PostDiscoveryService`). What a source finds it stores, so
 * the hashtag timeline and the search read it the way they read any post.
 */
interface PostSource {
	/**
	 * Stores the newest posts with the hashtag this server does not hold.
	 *
	 * @param int $since a Unix time: posts written before it are left out;
	 *                   0 for no bound
	 * @return int how many were stored
	 */
	public function tagged(string $tag, int $limit, ?Person $viewer, int $since = 0): int;

	/**
	 * Stores the posts matching the search this server does not hold.
	 *
	 * @return int how many were stored
	 */
	public function matching(string $query, int $limit, ?Person $viewer): int;
}
