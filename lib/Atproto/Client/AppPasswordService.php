<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use InvalidArgumentException;
use OCA\Social\Db\AtprotoClientRequest;
use OCP\DB\Exception as DBException;
use OCP\Security\ISecureRandom;

/**
 * App passwords for Bluesky apps (§6.3), as Bluesky makes them: four groups
 * of four, shown once, stored as a hash. They are this app's, not
 * Nextcloud's — a Nextcloud app password opens all of Nextcloud, files and
 * all, and a Bluesky app needs the Bluesky surface and nothing else.
 * Removing one ends every session it opened.
 */
class AppPasswordService {
	public const MAX_PER_USER = 25;
	private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

	public function __construct(
		private AtprotoClientRequest $request,
		private ISecureRandom $random,
	) {
	}

	/**
	 * @param bool $privileged whether an app signed in with it reaches the direct messages
	 * @return array{id: int, name: string, password: string, privileged: bool} the password, the one time it is seen
	 * @throws InvalidArgumentException for an empty or taken name, or too many passwords
	 */
	public function create(string $userId, string $name, bool $privileged = false): array {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 64) {
			throw new InvalidArgumentException('A name of 1 to 64 characters is needed');
		}
		if (count($this->request->getAppPasswords($userId)) >= self::MAX_PER_USER) {
			throw new InvalidArgumentException('There are ' . self::MAX_PER_USER . ' app passwords already');
		}
		$password = implode('-', array_map(fn (int $group): string => $this->random->generate(4, self::ALPHABET), range(1, 4)));
		try {
			$id = $this->request->addAppPassword($userId, $name, password_hash($password, PASSWORD_DEFAULT), $privileged);
		} catch (DBException $e) {
			if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new InvalidArgumentException('There is an app password with that name');
			}
			throw $e;
		}

		return ['id' => $id, 'name' => $name, 'password' => $password, 'privileged' => $privileged];
	}

	/**
	 * @return list<array{id: int, name: string, creation: int, last_used: int, privileged: bool}>
	 */
	public function list(string $userId): array {
		return array_map(static fn (array $row): array => [
			'id' => $row['id'], 'name' => $row['name'], 'creation' => $row['creation'], 'last_used' => $row['last_used'], 'privileged' => $row['privileged'],
		], $this->request->getAppPasswords($userId));
	}

	public function revoke(string $userId, int $id): bool {
		return $this->request->removeAppPassword($userId, $id);
	}

	/**
	 * The password the given one is, or null.
	 *
	 * @return int|null its id
	 */
	public function verify(string $userId, string $password): ?int {
		$password = strtolower(trim($password));
		if (preg_match('/^[a-z2-7]{4}(-[a-z2-7]{4}){3}$/', $password) !== 1) {
			return null;
		}
		foreach ($this->request->getAppPasswords($userId) as $row) {
			if (password_verify($password, $row['hash'])) {
				$this->request->appPasswordUsed($row['id']);

				return $row['id'];
			}
		}

		return null;
	}
}
