<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Helper;

use OCP\IUserManager;
use OCP\Server;

/**
 * Real Nextcloud users for a test that issues credentials to them: a token
 * or a session works only for a user who exists and is enabled.
 * `removeCreatedUsers()` deletes the ones this made and must run in
 * tearDown; a user that existed already is left alone.
 */
trait CreatesUsers {
	/** @var string[] */
	private array $createdUsers = [];

	protected function ensureUsers(string ...$userIds): void {
		$users = Server::get(IUserManager::class);
		foreach ($userIds as $userId) {
			if (!$users->userExists($userId)) {
				$users->createUser($userId, 'Itest-' . bin2hex(random_bytes(16)) . 'Aa1!');
				$this->createdUsers[] = $userId;
			}
		}
	}

	protected function removeCreatedUsers(): void {
		$users = Server::get(IUserManager::class);
		foreach ($this->createdUsers as $userId) {
			$users->get($userId)?->delete();
		}
		$this->createdUsers = [];
	}
}
