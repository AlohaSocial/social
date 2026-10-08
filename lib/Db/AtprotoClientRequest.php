<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * App passwords for Bluesky apps, and the sessions they opened.
 */
class AtprotoClientRequest extends CoreRequestBuilder {
	/**
	 * @return int the new password's id
	 */
	public function addAppPassword(string $userId, string $name, string $hash): int {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_APP_PASSWORD)
			->setValue('user_id', $qb->createNamedParameter($userId))
			->setValue('name', $qb->createNamedParameter($name))
			->setValue('hash', $qb->createNamedParameter($hash))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/**
	 * @return list<array{id: int, name: string, hash: string, creation: int, last_used: int}>
	 */
	public function getAppPasswords(string $userId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'name', 'hash', 'creation', 'last_used')->from(self::TABLE_ATPROTO_APP_PASSWORD)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('creation', 'asc');
		$rows = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$rows[] = [
				'id' => (int)$row['id'],
				'name' => (string)$row['name'],
				'hash' => (string)$row['hash'],
				'creation' => AtprotoIdentityRequest::time($row['creation']),
				'last_used' => AtprotoIdentityRequest::time($row['last_used']),
			];
		}
		$result->closeCursor();

		return $rows;
	}

	public function appPasswordUsed(int $id): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_APP_PASSWORD)
			->set('last_used', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Removes a password and every session it opened.
	 *
	 * @return bool whether the password was the user's to remove
	 */
	public function removeAppPassword(string $userId, int $id): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_APP_PASSWORD)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($qb->executeStatement() === 0) {
			return false;
		}
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_SESSION)
			->where($qb->expr()->eq('app_password_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		return true;
	}

	public function addSession(string $jti, string $userId, string $did, int $appPasswordId, int $expires): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_SESSION)
			->setValue('jti', $qb->createNamedParameter($jti))
			->setValue('user_id', $qb->createNamedParameter($userId))
			->setValue('did', $qb->createNamedParameter($did))
			->setValue('app_password_id', $qb->createNamedParameter($appPasswordId, IQueryBuilder::PARAM_INT))
			->setValue('expires', $qb->createNamedParameter(new DateTime('@' . $expires), IQueryBuilder::PARAM_DATE))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	/**
	 * @return array{jti: string, user_id: string, did: string, app_password_id: int, expires: int}|null
	 */
	public function getSession(string $jti): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select('jti', 'user_id', 'did', 'app_password_id', 'expires')->from(self::TABLE_ATPROTO_SESSION)
			->where($qb->expr()->eq('jti', $qb->createNamedParameter($jti)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}

		return [
			'jti' => (string)$row['jti'],
			'user_id' => (string)$row['user_id'],
			'did' => (string)$row['did'],
			'app_password_id' => (int)$row['app_password_id'],
			'expires' => AtprotoIdentityRequest::time($row['expires']),
		];
	}

	public function removeSession(string $jti): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_SESSION)->where($qb->expr()->eq('jti', $qb->createNamedParameter($jti)));
		$qb->executeStatement();
	}

	/**
	 * @return int how many expired sessions were removed
	 */
	public function pruneSessions(int $now): int {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_SESSION)
			->where($qb->expr()->lt('expires', $qb->createNamedParameter(new DateTime('@' . $now), IQueryBuilder::PARAM_DATE)));

		return $qb->executeStatement();
	}

	public function deleteByUser(string $userId): void {
		foreach ([self::TABLE_ATPROTO_SESSION, self::TABLE_ATPROTO_APP_PASSWORD] as $table) {
			$qb = $this->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		}
	}
}
