<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Interaction;

use OCA\Social\Model\ActivityPub\Stream;

/**
 * Who liked, boosted or quoted a post, as the network it lives on knows it
 * (`InteractionService`).
 */
interface InteractionSource {
	public function supports(Stream $post): bool;

	/**
	 * The accounts that liked (`Like::TYPE`) or boosted (`Announce::TYPE`)
	 * the post, newest first, as actor ids; the ones this server can show
	 * are cached as they are found.
	 *
	 * @return list<string>
	 */
	public function actors(Stream $post, string $type, int $limit): array;

	/**
	 * Stores the posts that quote it, which this server does not hold yet.
	 *
	 * @return int how many were stored
	 */
	public function quotes(Stream $post, int $limit): int;
}
