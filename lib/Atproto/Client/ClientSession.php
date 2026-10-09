<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\OAuth\Permissions;

/**
 * A Bluesky app signed in as a local account: who, through which session,
 * and what it may do. An app password grants what the transitional scopes
 * name; an OAuth session what the person agreed to, the permission sets it
 * includes expanded.
 */
final class ClientSession {
	public const GENERIC = 'transition:generic';
	public const EMAIL = 'transition:email';
	/** direct messages, for an app password made privileged or an OAuth app given it */
	public const CHAT = 'transition:chat.bsky';

	/**
	 * @param string[] $scopes
	 */
	public function __construct(
		public readonly string $userId,
		public readonly Identity $identity,
		public readonly string $sessionId,
		public readonly array $scopes = ['atproto', self::GENERIC, self::EMAIL],
		private ?Permissions $permissions = null,
	) {
	}

	public function may(string $scope): bool {
		return in_array($scope, $this->scopes, true);
	}

	public function permissions(): Permissions {
		return $this->permissions ??= Permissions::of($this->scopes);
	}
}
