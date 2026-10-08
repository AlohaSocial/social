<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Atproto\Model\OAuthRequest;
use OCA\Social\Atproto\Model\OAuthSession;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * OAuth for Bluesky apps: authorization requests, sessions, and the
 * one-time values that may not be used twice.
 */
class AtprotoOAuthRequest extends CoreRequestBuilder {
	public function addRequest(string $requestId, string $clientId, array $clientAuth, array $params, string $dpopJkt, int $expires): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_OAUTH_REQUEST)
			->setValue('request_id', $qb->createNamedParameter($requestId))
			->setValue('client_id', $qb->createNamedParameter($clientId))
			->setValue('client_auth', $qb->createNamedParameter((string)json_encode($clientAuth)))
			->setValue('params', $qb->createNamedParameter((string)json_encode($params, JSON_UNESCAPED_SLASHES)))
			->setValue('dpop_jkt', $qb->createNamedParameter($dpopJkt))
			->setValue('code_challenge', $qb->createNamedParameter((string)($params['code_challenge'] ?? '')))
			->setValue('expires', $qb->createNamedParameter(new DateTime('@' . $expires), IQueryBuilder::PARAM_DATE))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	public function getRequest(string $requestId): ?OAuthRequest {
		return $this->oneRequest('request_id', $requestId);
	}

	public function getRequestByCode(string $codeHash): ?OAuthRequest {
		return $codeHash === '' ? null : $this->oneRequest('code_hash', $codeHash);
	}

	/** Whether a PKCE challenge was used by another request since a time. */
	public function challengeSeen(string $codeChallenge): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('id')->from(self::TABLE_ATPROTO_OAUTH_REQUEST)
			->where($qb->expr()->eq('code_challenge', $qb->createNamedParameter($codeChallenge)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$seen = $result->fetch() !== false;
		$result->closeCursor();

		return $seen;
	}

	/**
	 * Records the person's consent and the hash of the code handed out for it.
	 *
	 * @return bool whether the request was still waiting for it
	 */
	public function approveRequest(string $requestId, string $userId, string $did, string $codeHash, int $expires): bool {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_OAUTH_REQUEST)
			->set('user_id', $qb->createNamedParameter($userId))
			->set('did', $qb->createNamedParameter($did))
			->set('code_hash', $qb->createNamedParameter($codeHash))
			->set('expires', $qb->createNamedParameter(new DateTime('@' . $expires), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId)))
			->andWhere($qb->expr()->eq('code_hash', $qb->createNamedParameter('')));

		return $qb->executeStatement() === 1;
	}

	/**
	 * Marks a code as exchanged, naming the session it started, so a second
	 * use can end that session.
	 *
	 * @return bool whether this call was the first to exchange it
	 */
	public function exchangeCode(int $id, string $sessionId): bool {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_OAUTH_REQUEST)
			->set('session_id', $qb->createNamedParameter($sessionId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('session_id', $qb->createNamedParameter('')));

		return $qb->executeStatement() === 1;
	}

	public function removeRequest(string $requestId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_OAUTH_REQUEST)
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId)));
		$qb->executeStatement();
	}

	public function addSession(OAuthSession $session): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_OAUTH_SESSION)
			->setValue('session_id', $qb->createNamedParameter($session->sessionId))
			->setValue('user_id', $qb->createNamedParameter($session->userId))
			->setValue('did', $qb->createNamedParameter($session->did))
			->setValue('client_id', $qb->createNamedParameter($session->clientId))
			->setValue('client_auth', $qb->createNamedParameter((string)json_encode($session->clientAuth)))
			->setValue('scope', $qb->createNamedParameter($session->scope))
			->setValue('dpop_jkt', $qb->createNamedParameter($session->dpopJkt))
			->setValue('refresh_hash', $qb->createNamedParameter($session->refreshHash))
			->setValue('refresh_expires', $qb->createNamedParameter(new DateTime('@' . $session->refreshExpires), IQueryBuilder::PARAM_DATE))
			->setValue('expires', $qb->createNamedParameter($session->expires === 0 ? null : new DateTime('@' . $session->expires), IQueryBuilder::PARAM_DATE))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->setValue('last_used', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	public function getSession(string $sessionId): ?OAuthSession {
		return $sessionId === '' ? null : $this->oneSession('session_id', $sessionId);
	}

	public function getSessionByRefresh(string $refreshHash): ?OAuthSession {
		return $this->oneSession('refresh_hash', $refreshHash);
	}

	/** A session by a refresh token it has already replaced. */
	public function getSessionByPreviousRefresh(string $refreshHash): ?OAuthSession {
		return $this->oneSession('previous_hash', $refreshHash);
	}

	/**
	 * Replaces a session's refresh token, as long as the one presented is
	 * still the good one.
	 *
	 * @return bool whether this call replaced it
	 */
	public function rotateRefresh(string $sessionId, string $oldHash, string $newHash, int $refreshExpires): bool {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_OAUTH_SESSION)
			->set('refresh_hash', $qb->createNamedParameter($newHash))
			->set('previous_hash', $qb->createNamedParameter($oldHash))
			->set('refresh_expires', $qb->createNamedParameter(new DateTime('@' . $refreshExpires), IQueryBuilder::PARAM_DATE))
			->set('last_used', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId)))
			->andWhere($qb->expr()->eq('refresh_hash', $qb->createNamedParameter($oldHash)));

		return $qb->executeStatement() === 1;
	}

	public function sessionUsed(string $sessionId): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_OAUTH_SESSION)
			->set('last_used', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId)));
		$qb->executeStatement();
	}

	/**
	 * @return OAuthSession[] a person's signed-in apps, the latest first
	 */
	public function getSessionsOfUser(string $userId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_ATPROTO_OAUTH_SESSION)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('creation', 'desc');
		$sessions = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$sessions[] = self::session($row);
		}
		$result->closeCursor();

		return $sessions;
	}

	public function removeSession(string $sessionId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_OAUTH_SESSION)
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId)));
		$qb->executeStatement();
	}

	/**
	 * @return bool whether the session was the person's to remove
	 */
	public function removeSessionOfUser(string $userId, int $id): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_OAUTH_SESSION)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $qb->executeStatement() === 1;
	}

	public function deleteByUser(string $userId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_OAUTH_SESSION)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_OAUTH_REQUEST)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Records a one-time value, unless it was recorded already.
	 *
	 * @return bool whether this is its first use
	 */
	public function firstUse(string $hash, int $expires): bool {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_OAUTH_REPLAY)
			->setValue('hash', $qb->createNamedParameter($hash))
			->setValue('expires', $qb->createNamedParameter(new DateTime('@' . $expires), IQueryBuilder::PARAM_DATE));
		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}

		return true;
	}

	/**
	 * Removes what has expired: requests, sessions, one-time values. The
	 * requests are kept a day past their expiry, so a PKCE challenge cannot be
	 * used again within that time.
	 *
	 * @return int how many rows were removed
	 */
	public function prune(int $now): int {
		$removed = 0;
		foreach ([
			[self::TABLE_ATPROTO_OAUTH_REQUEST, 'expires', $now - 86400],
			[self::TABLE_ATPROTO_OAUTH_SESSION, 'refresh_expires', $now],
			[self::TABLE_ATPROTO_OAUTH_SESSION, 'expires', $now],
			[self::TABLE_ATPROTO_OAUTH_REPLAY, 'expires', $now],
		] as [$table, $column, $before]) {
			$qb = $this->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->isNotNull($column))
				->andWhere($qb->expr()->lt($column, $qb->createNamedParameter(new DateTime('@' . $before), IQueryBuilder::PARAM_DATE)));
			$removed += $qb->executeStatement();
		}

		return $removed;
	}

	private function oneRequest(string $column, string $value): ?OAuthRequest {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_ATPROTO_OAUTH_REQUEST)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row)) {
			return null;
		}

		return new OAuthRequest(
			(int)$row['id'],
			(string)$row['request_id'],
			(string)$row['client_id'],
			self::json($row['client_auth']),
			self::json($row['params']),
			(string)$row['dpop_jkt'],
			(string)$row['user_id'],
			(string)$row['did'],
			(string)$row['code_hash'],
			(string)$row['session_id'],
			AtprotoIdentityRequest::time($row['expires']),
		);
	}

	private function oneSession(string $column, string $value): ?OAuthSession {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_ATPROTO_OAUTH_SESSION)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return is_array($row) ? self::session($row) : null;
	}

	private static function session(array $row): OAuthSession {
		return new OAuthSession(
			(int)$row['id'],
			(string)$row['session_id'],
			(string)$row['user_id'],
			(string)$row['did'],
			(string)$row['client_id'],
			self::json($row['client_auth']),
			(string)$row['scope'],
			(string)$row['dpop_jkt'],
			(string)$row['refresh_hash'],
			AtprotoIdentityRequest::time($row['refresh_expires']),
			AtprotoIdentityRequest::time($row['expires']),
			AtprotoIdentityRequest::time($row['creation']),
			AtprotoIdentityRequest::time($row['last_used']),
		);
	}

	private static function json(mixed $value): array {
		$decoded = json_decode((string)$value, true);

		return is_array($decoded) ? $decoded : [];
	}
}
