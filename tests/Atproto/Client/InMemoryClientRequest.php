<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Db\AtprotoClientRequest;

/**
 * App passwords and sessions in memory, as the tables keep them.
 */
class InMemoryClientRequest extends AtprotoClientRequest {
	/** @var array<int, array{id: int, user_id: string, name: string, hash: string, creation: int, last_used: int, privileged: bool}> */
	public array $passwords = [];
	/** @var array<string, array{jti: string, user_id: string, did: string, app_password_id: int, expires: int}> */
	public array $sessions = [];

	public function addAppPassword(string $userId, string $name, string $hash, bool $privileged = false): int {
		foreach ($this->passwords as $row) {
			if ($row['user_id'] === $userId && $row['name'] === $name) {
				throw new class('taken') extends \OCP\DB\Exception {
					public function getReason(): ?int {
						return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
					}
				};
			}
		}
		$id = count($this->passwords) + 1;
		$this->passwords[$id] = ['id' => $id, 'user_id' => $userId, 'name' => $name, 'hash' => $hash, 'creation' => 1, 'last_used' => 0, 'privileged' => $privileged];

		return $id;
	}

	public function getAppPasswords(string $userId): array {
		return array_values(array_map(static fn (array $row): array => array_diff_key($row, ['user_id' => 1]), array_filter($this->passwords, static fn (array $row): bool => $row['user_id'] === $userId)));
	}

	public function isPrivileged(int $id): bool {
		return $this->passwords[$id]['privileged'] ?? false;
	}

	public function appPasswordUsed(int $id): void {
		$this->passwords[$id]['last_used'] = 2;
	}

	public function removeAppPassword(string $userId, int $id): bool {
		if (($this->passwords[$id]['user_id'] ?? '') !== $userId) {
			return false;
		}
		unset($this->passwords[$id]);
		$this->sessions = array_filter($this->sessions, static fn (array $s): bool => $s['app_password_id'] !== $id);

		return true;
	}

	public function addSession(string $jti, string $userId, string $did, int $appPasswordId, int $expires): void {
		$this->sessions[$jti] = ['jti' => $jti, 'user_id' => $userId, 'did' => $did, 'app_password_id' => $appPasswordId, 'expires' => $expires];
	}

	public function getSession(string $jti): ?array {
		return $this->sessions[$jti] ?? null;
	}

	public function removeSession(string $jti): void {
		unset($this->sessions[$jti]);
	}

	public function removeSessionsOfUser(string $userId): void {
		$this->sessions = array_filter($this->sessions, static fn (array $s): bool => $s['user_id'] !== $userId);
	}

	public function pruneSessions(int $now): int {
		$before = count($this->sessions);
		$this->sessions = array_filter($this->sessions, static fn (array $s): bool => $s['expires'] >= $now);

		return $before - count($this->sessions);
	}
}
