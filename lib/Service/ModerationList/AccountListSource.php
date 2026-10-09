<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\ModerationList;

use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * Where a published list of accounts to mute or block comes from
 * (`ModerationListService`): today Bluesky's moderation lists, the one
 * network that has them.
 */
interface AccountListSource {
	/**
	 * The list a link or an address names: its address and its name, or null
	 * when this source does not know it.
	 *
	 * @return array{uri: string, name: string}|null
	 */
	public function describe(string $reference): ?array;

	/**
	 * The accounts on the list, as cached actors' ids, at most `$limit`.
	 *
	 * @return list<string>
	 */
	public function members(string $uri, int $limit): array;

	/**
	 * Tells the network the person subscribes to the list, or no longer
	 * does, where it keeps that.
	 */
	public function subscribed(Person $viewer, string $uri, string $kind, bool $on): void;
}
