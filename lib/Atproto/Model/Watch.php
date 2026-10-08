<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

/**
 * A Bluesky account whose feed this instance reads: the author somebody
 * here follows, or a local account whose notifications are asked for. One
 * row per DID, with where the last read stopped and when the next is due.
 */
final class Watch {
	public function __construct(
		public readonly string $did,
		public readonly string $handle,
		public readonly string $cursor,
		public readonly int $lastSync,
		public readonly int $nextSync,
		public readonly int $failures,
		public readonly string $lastError,
		public readonly int $creation,
	) {
	}

	public function toArray(): array {
		return [
			'did' => $this->did,
			'handle' => $this->handle,
			'cursor' => $this->cursor,
			'last_sync' => $this->lastSync,
			'next_sync' => $this->nextSync,
			'failures' => $this->failures,
			'last_error' => $this->lastError,
			'creation' => $this->creation,
		];
	}
}
