<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\Model\Identity;

/**
 * A Bluesky app signed in as a local account: who, through which session.
 */
final class ClientSession {
	public function __construct(
		public readonly string $userId,
		public readonly Identity $identity,
		public readonly string $sessionId,
	) {
	}
}
