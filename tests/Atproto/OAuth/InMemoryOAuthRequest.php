<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use OCA\Social\Atproto\Model\OAuthRequest;
use OCA\Social\Atproto\Model\OAuthSession;
use OCA\Social\Db\AtprotoOAuthRequest;

/**
 * OAuth requests, sessions and one-time values in memory, as the tables
 * keep them.
 */
class InMemoryOAuthRequest extends AtprotoOAuthRequest {
	/** @var array<string, OAuthRequest> by request id */
	public array $requests = [];
	/** @var array<string, OAuthSession> by session id */
	public array $sessions = [];
	/** @var array<string, string> session id => the refresh hash it replaced */
	public array $previous = [];
	/** @var array<string, int> */
	public array $used = [];

	public function addRequest(string $requestId, string $clientId, array $clientAuth, array $params, string $dpopJkt, int $expires): void {
		$this->requests[$requestId] = new OAuthRequest(count($this->requests) + 1, $requestId, $clientId, $clientAuth, $params, $dpopJkt, expires: $expires);
	}

	public function getRequest(string $requestId): ?OAuthRequest {
		return $this->requests[$requestId] ?? null;
	}

	public function getRequestByCode(string $codeHash): ?OAuthRequest {
		foreach ($this->requests as $request) {
			if ($codeHash !== '' && $request->codeHash === $codeHash) {
				return $request;
			}
		}

		return null;
	}

	public function challengeSeen(string $codeChallenge): bool {
		foreach ($this->requests as $request) {
			if (($request->params['code_challenge'] ?? '') === $codeChallenge) {
				return true;
			}
		}

		return false;
	}

	public function approveRequest(string $requestId, string $userId, string $did, string $codeHash, int $expires): bool {
		$r = $this->requests[$requestId] ?? null;
		if ($r === null || $r->codeHash !== '') {
			return false;
		}
		$this->requests[$requestId] = new OAuthRequest($r->id, $r->requestId, $r->clientId, $r->clientAuth, $r->params, $r->dpopJkt, $userId, $did, $codeHash, '', $expires);

		return true;
	}

	public function exchangeCode(int $id, string $sessionId): bool {
		foreach ($this->requests as $key => $r) {
			if ($r->id === $id) {
				if ($r->sessionId !== '') {
					return false;
				}
				$this->requests[$key] = new OAuthRequest($r->id, $r->requestId, $r->clientId, $r->clientAuth, $r->params, $r->dpopJkt, $r->userId, $r->did, $r->codeHash, $sessionId, $r->expires);

				return true;
			}
		}

		return false;
	}

	public function removeRequest(string $requestId): void {
		unset($this->requests[$requestId]);
	}

	public function addSession(OAuthSession $session): void {
		$this->sessions[$session->sessionId] = new OAuthSession(
			count($this->sessions) + 1, $session->sessionId, $session->userId, $session->did, $session->clientId, $session->clientAuth,
			$session->scope, $session->dpopJkt, $session->refreshHash, $session->refreshExpires, $session->expires, 1, 1,
		);
	}

	public function getSession(string $sessionId): ?OAuthSession {
		return $this->sessions[$sessionId] ?? null;
	}

	public function getSessionByRefresh(string $refreshHash): ?OAuthSession {
		foreach ($this->sessions as $session) {
			if ($session->refreshHash === $refreshHash) {
				return $session;
			}
		}

		return null;
	}

	public function getSessionByPreviousRefresh(string $refreshHash): ?OAuthSession {
		$sessionId = array_search($refreshHash, $this->previous, true);

		return $sessionId === false ? null : ($this->sessions[$sessionId] ?? null);
	}

	public function rotateRefresh(string $sessionId, string $oldHash, string $newHash, int $refreshExpires): bool {
		$s = $this->sessions[$sessionId] ?? null;
		if ($s === null || $s->refreshHash !== $oldHash) {
			return false;
		}
		$this->previous[$sessionId] = $oldHash;
		$this->sessions[$sessionId] = new OAuthSession($s->id, $s->sessionId, $s->userId, $s->did, $s->clientId, $s->clientAuth, $s->scope, $s->dpopJkt, $newHash, $refreshExpires, $s->expires, $s->creation, 2);

		return true;
	}

	public function sessionUsed(string $sessionId): void {
	}

	public function getSessionsOfUser(string $userId): array {
		return array_values(array_filter($this->sessions, static fn (OAuthSession $s): bool => $s->userId === $userId));
	}

	public function removeSession(string $sessionId): void {
		unset($this->sessions[$sessionId], $this->previous[$sessionId]);
	}

	public function removeSessionOfUser(string $userId, int $id): bool {
		foreach ($this->sessions as $key => $s) {
			if ($s->id === $id && $s->userId === $userId) {
				unset($this->sessions[$key]);

				return true;
			}
		}

		return false;
	}

	public function firstUse(string $hash, int $expires): bool {
		if (isset($this->used[$hash])) {
			return false;
		}
		$this->used[$hash] = $expires;

		return true;
	}
}
