<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Thread;

use OCA\Social\Model\ActivityPub\Stream;

/**
 * Where the rest of a conversation can be read from, for a post this server
 * holds: whatever network the post lives on (`ThreadService`).
 */
interface ThreadSource {
	/**
	 * Whether the post's conversation can be read from here.
	 */
	public function supports(Stream $post): bool;

	/**
	 * Stores the replies under the post that this server does not hold yet,
	 * at most `$budget` of them.
	 *
	 * @return int how many were stored
	 */
	public function fill(Stream $post, int $budget): int;
}
