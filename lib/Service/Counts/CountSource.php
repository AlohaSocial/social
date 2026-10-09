<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Counts;

use OCA\Social\Model\ActivityPub\Stream;

/**
 * How many likes, boosts and replies a post has where it lives, as the
 * network it is on knows it now (`CountService`).
 */
interface CountSource {
	public function supports(Stream $post): bool;

	/**
	 * Asks the network about these posts and stores what it says
	 * (`CountWriter`); each post is stamped as asked whether or not it was
	 * answered, so a post that is not is asked again one interval later
	 * rather than on every look.
	 *
	 * @param list<Stream> $posts posts this source supports
	 * @return int how many were answered
	 */
	public function refresh(array $posts): int;
}
