<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\BlockedBy;

use OCA\Social\Model\ActivityPub\Stream;

/**
 * Whether an account has blocked a local one, as the network the account
 * is on can be asked (`BlockedByService`). A network that tells of a block
 * itself, as the Fediverse does with a `Block` activity, needs none.
 */
interface BlockedBySource {
	public function supports(string $actorId): bool;

	/**
	 * Whether each account has blocked the local one, as the network says
	 * now; an account it could not be asked about is left out.
	 *
	 * @param list<string> $actorIds
	 * @return array<string, bool> by actor id
	 */
	public function ask(string $localId, array $actorIds): array;

	/**
	 * The accounts besides the post's author whose block keeps a reply to
	 * it out, as the network holds the thread.
	 *
	 * @return list<string> actor ids
	 */
	public function threadAuthors(Stream $post): array;
}
