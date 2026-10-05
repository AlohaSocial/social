<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Atproto;

use JsonSerializable;

/**
 * One Bluesky actor whose posts this instance reads, as held in
 * `social_atproto_watch`.
 *
 * The set is exactly the actors local accounts follow: there is nothing to
 * read otherwise, and an actor nobody follows any more stops being read the
 * moment its last local follower goes. The cursor is the pagination token the
 * last read ended at, so a pass resumes rather than re-reading the head of the
 * feed; `next_sync` is when it may run again — pushed forward on a failure so
 * a PDS that is down costs a request per backoff, not a request per pass.
 */
class AtprotoWatch implements JsonSerializable {
	/** A failure this pass did not wait for; the pass skips the row. */
	public const MIN_INTERVAL = 60;

	private int $nid = 0;
	private string $did = '';
	private string $handle = '';
	private string $cursor = '';
	private int $lastSync = 0;
	private int $nextSync = 0;
	private int $failures = 0;
	private int $imported = 0;
	private string $lastError = '';
	private ?string $creation = null;

	public function getNid(): int {
		return $this->nid;
	}

	public function setNid(int $nid): AtprotoWatch {
		$this->nid = $nid;

		return $this;
	}

	public function getDid(): string {
		return $this->did;
	}

	public function setDid(string $did): AtprotoWatch {
		$this->did = $did;

		return $this;
	}

	public function getHandle(): string {
		return $this->handle;
	}

	public function setHandle(string $handle): AtprotoWatch {
		$this->handle = $handle;

		return $this;
	}

	public function getCursor(): string {
		return $this->cursor;
	}

	public function setCursor(string $cursor): AtprotoWatch {
		$this->cursor = $cursor;

		return $this;
	}

	public function getLastSync(): int {
		return $this->lastSync;
	}

	public function setLastSync(int $lastSync): AtprotoWatch {
		$this->lastSync = $lastSync;

		return $this;
	}

	public function getNextSync(): int {
		return $this->nextSync;
	}

	public function setNextSync(int $nextSync): AtprotoWatch {
		$this->nextSync = $nextSync;

		return $this;
	}

	public function getFailures(): int {
		return $this->failures;
	}

	public function setFailures(int $failures): AtprotoWatch {
		$this->failures = $failures;

		return $this;
	}

	public function getImported(): int {
		return $this->imported;
	}

	public function setImported(int $imported): AtprotoWatch {
		$this->imported = $imported;

		return $this;
	}

	public function getLastError(): string {
		return $this->lastError;
	}

	public function setLastError(string $lastError): AtprotoWatch {
		$this->lastError = $lastError;

		return $this;
	}

	public function getCreation(): ?string {
		return $this->creation;
	}

	public function setCreation(?string $creation): AtprotoWatch {
		$this->creation = $creation;

		return $this;
	}

	/**
	 * When a failed pass may run again: one minute, doubling per consecutive
	 * failure to a day — the same shape as a remote actor's refresh backoff,
	 * for the same reason (a server that is down is not asked again every
	 * twelve minutes).
	 */
	public function backoff(int $interval): int {
		if ($this->failures < 1) {
			return $interval;
		}

		return min(86400, 60 << min($this->failures, 10));
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'did' => $this->did,
			'handle' => $this->handle,
			'last_sync' => $this->lastSync,
			'next_sync' => $this->nextSync,
			'failures' => $this->failures,
			'imported' => $this->imported,
			'last_error' => $this->lastError,
		];
	}
}
