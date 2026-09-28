<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\External;

use OCA\Social\Db\ExternalUsersRequest;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use OCP\Security\IHasher;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IPasswordHashBackend;
use OCP\User\Backend\ISetDisplayNameBackend;
use OCP\User\Backend\ISetPasswordBackend;

/**
 * The Nextcloud user backend of self-registered external users.
 *
 * Externals are real Nextcloud users, so the login form, sessions, two-factor
 * authentication, password reset and the Users page work for them unchanged.
 * Accounts are only created by the signup flow (`ExternalUserService`), never
 * through the Users page: this backend deliberately does not implement
 * `ICreateUserBackend`.
 *
 * Built on every request before login, so it holds nothing but the table and
 * a per-request cache of the rows it has looked up, misses included.
 */
class ExternalUserBackend extends ABackend implements
	ICheckPasswordBackend,
	ISetPasswordBackend,
	IPasswordHashBackend,
	IGetDisplayNameBackend,
	ISetDisplayNameBackend,
	ICountUsersBackend {
	public const BACKEND_NAME = 'Social';

	/** @var array<string, array{uid: string, password: string, displayname: string, origin: string, creation: int, emailVerified: ?bool}|null> */
	private array $cache = [];

	public function __construct(
		private ExternalUsersRequest $usersRequest,
		private IHasher $hasher,
		private IEventDispatcher $eventDispatcher,
	) {
	}

	/** Whether this user is a self-registered external user. */
	public static function isExternal(?IUser $user): bool {
		return $user !== null && $user->getBackend() instanceof self;
	}

	#[\Override]
	public function getBackendName(): string {
		return self::BACKEND_NAME;
	}

	/**
	 * @return array{uid: string, password: string, displayname: string, origin: string, creation: int, emailVerified: ?bool}|null
	 */
	private function row(string $uid): ?array {
		$key = mb_strtolower($uid);
		if (!array_key_exists($key, $this->cache)) {
			$this->cache[$key] = ($uid === '') ? null : $this->usersRequest->get($uid);
		}

		return $this->cache[$key];
	}

	/** Forgets what this request has looked up, after a write elsewhere. */
	public function forget(string $uid): void {
		unset($this->cache[mb_strtolower($uid)]);
	}

	#[\Override]
	public function userExists($uid): bool {
		return $this->row((string)$uid) !== null;
	}

	/**
	 * The stored spelling of a user id, or null for somebody who is not an
	 * external user.
	 */
	public function canonicalUid(string $uid): ?string {
		return $this->row($uid)['uid'] ?? null;
	}

	#[\Override]
	public function checkPassword(string $loginName, string $password) {
		$row = $this->row($loginName);
		if ($row === null || $row['password'] === '') {
			return false;
		}

		$newHash = '';
		if (!$this->hasher->verify($password, $row['password'], $newHash)) {
			return false;
		}

		if ($newHash !== '') {
			$this->usersRequest->setPasswordHash($row['uid'], $newHash);
			$this->forget($row['uid']);
		}

		return $row['uid'];
	}

	#[\Override]
	public function setPassword(string $uid, string $password): bool {
		if (!$this->userExists($uid)) {
			return false;
		}

		$this->eventDispatcher->dispatchTyped(new ValidatePasswordPolicyEvent($password));

		return $this->setPasswordHash($uid, $this->hasher->hash($password));
	}

	#[\Override]
	public function getPasswordHash(string $userId): ?string {
		$hash = $this->row($userId)['password'] ?? '';

		return ($hash === '') ? null : $hash;
	}

	#[\Override]
	public function setPasswordHash(string $userId, string $passwordHash): bool {
		if (!$this->hasher->validate($passwordHash)) {
			throw new \InvalidArgumentException('not a password hash this server can verify');
		}

		$changed = $this->usersRequest->setPasswordHash($userId, $passwordHash);
		$this->forget($userId);

		return $changed;
	}

	#[\Override]
	public function getDisplayName($uid): string {
		$row = $this->row((string)$uid);
		if ($row === null) {
			return (string)$uid;
		}

		return ($row['displayname'] === '') ? $row['uid'] : $row['displayname'];
	}

	#[\Override]
	public function setDisplayName(string $uid, string $displayName): bool {
		$displayName = trim($displayName);
		if ($displayName === '' || mb_strlen($displayName) > 64 || !$this->userExists($uid)) {
			return false;
		}

		$this->usersRequest->setDisplayName($uid, $displayName);
		$this->forget($uid);

		return true;
	}

	#[\Override]
	public function deleteUser($uid): bool {
		$deleted = $this->usersRequest->delete((string)$uid);
		$this->forget((string)$uid);

		return $deleted;
	}

	#[\Override]
	public function getUsers($search = '', $limit = null, $offset = null): array {
		return array_map(
			static fn (array $row): string => $row['uid'],
			$this->usersRequest->search((string)$search, $limit, $offset)
		);
	}

	#[\Override]
	public function getDisplayNames($search = '', $limit = null, $offset = null): array {
		$names = [];
		foreach ($this->usersRequest->search((string)$search, $limit, $offset) as $row) {
			$names[$row['uid']] = ($row['displayname'] === '') ? $row['uid'] : $row['displayname'];
		}

		return $names;
	}

	#[\Override]
	public function hasUserListings(): bool {
		return true;
	}

	#[\Override]
	public function countUsers(): int {
		return $this->usersRequest->count();
	}
}
