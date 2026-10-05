<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Atproto;

use JsonSerializable;

/**
 * A Nextcloud account's linked Bluesky account, as held in
 * `social_atproto_account`.
 *
 * The row is what makes a write leave for AT-Proto: the PDS endpoint the
 * record is committed to and the app password the request is authenticated
 * with. The password is sealed with the instance secret before it is stored
 * (see `OCA\Social\Security\PrivateKeyCipher`) and is never part of what
 * serialises — not into a JSON response, not into a log line.
 */
class AtprotoAccount implements JsonSerializable {
	public const STATE_LINKED = 'linked';
	public const STATE_BROKEN = 'broken';

	private int $nid = 0;
	private string $userId = '';
	private string $handle = '';
	private string $did = '';
	private string $pds = '';
	private string $appPassword = '';
	private string $state = self::STATE_LINKED;
	private string $lastError = '';
	private int $lastSync = 0;
	private ?string $creation = null;

	public function getNid(): int {
		return $this->nid;
	}

	public function setNid(int $nid): AtprotoAccount {
		$this->nid = $nid;

		return $this;
	}

	public function getUserId(): string {
		return $this->userId;
	}

	public function setUserId(string $userId): AtprotoAccount {
		$this->userId = $userId;

		return $this;
	}

	public function getHandle(): string {
		return $this->handle;
	}

	public function setHandle(string $handle): AtprotoAccount {
		$this->handle = $handle;

		return $this;
	}

	public function getDid(): string {
		return $this->did;
	}

	public function setDid(string $did): AtprotoAccount {
		$this->did = $did;

		return $this;
	}

	public function getPds(): string {
		return $this->pds;
	}

	public function setPds(string $pds): AtprotoAccount {
		$this->pds = $pds;

		return $this;
	}

	public function getAppPassword(): string {
		return $this->appPassword;
	}

	public function setAppPassword(string $appPassword): AtprotoAccount {
		$this->appPassword = $appPassword;

		return $this;
	}

	public function getState(): string {
		return $this->state;
	}

	public function setState(string $state): AtprotoAccount {
		$this->state = $state;

		return $this;
	}

	public function getLastError(): string {
		return $this->lastError;
	}

	public function setLastError(string $lastError): AtprotoAccount {
		$this->lastError = $lastError;

		return $this;
	}

	public function getLastSync(): int {
		return $this->lastSync;
	}

	public function setLastSync(int $lastSync): AtprotoAccount {
		$this->lastSync = $lastSync;

		return $this;
	}

	public function getCreation(): ?string {
		return $this->creation;
	}

	public function setCreation(?string $creation): AtprotoAccount {
		$this->creation = $creation;

		return $this;
	}

	/**
	 * What the API hands out: everything but the app password, which is a
	 * credential and has no place in a response body.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'user_id' => $this->userId,
			'handle' => $this->handle,
			'did' => $this->did,
			'pds' => $this->pds,
			'state' => $this->state,
			'last_error' => $this->lastError,
			'last_sync' => $this->lastSync,
			'creation' => $this->creation,
		];
	}
}
