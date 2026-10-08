<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

/**
 * A move of a Bluesky account between this server and another PDS (§13):
 * where to, the step it has reached, and how it ended.
 *
 * Moving away goes through the steps in order: the account is made on the
 * other PDS when the move starts, then its repository, its blobs and its
 * preferences are copied there, the DID is pointed there, the account is
 * activated there and switched off here.
 */
final class Move {
	public const AWAY = 'away';

	public const STEP_REPO = 'repo';
	public const STEP_BLOBS = 'blobs';
	public const STEP_PREFERENCES = 'prefs';
	public const STEP_IDENTITY = 'identity';
	public const STEP_ACTIVATE = 'activate';
	public const STEP_DONE = 'done';

	public const RUNNING = 'running';
	public const FAILED = 'failed';
	public const DONE = 'done';

	/**
	 * @param array{blobs?: int, blobs_total?: int} $progress
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $did,
		public readonly string $userId,
		public readonly string $direction,
		public readonly string $pds,
		public readonly string $pdsDid,
		public readonly string $handle,
		public string $step,
		public string $state,
		public string $session = '',
		public array $progress = [],
		public string $error = '',
		public readonly int $creation = 0,
		public readonly int $updated = 0,
	) {
	}

	/** The step after one; the last is done. */
	public static function next(string $step): string {
		return match ($step) {
			self::STEP_REPO => self::STEP_BLOBS,
			self::STEP_BLOBS => self::STEP_PREFERENCES,
			self::STEP_PREFERENCES => self::STEP_IDENTITY,
			self::STEP_IDENTITY => self::STEP_ACTIVATE,
			default => self::STEP_DONE,
		};
	}

	/** what the settings show of it */
	public function export(): array {
		return [
			'id' => $this->id,
			'direction' => $this->direction,
			'pds' => $this->pds,
			'handle' => $this->handle,
			'step' => $this->step,
			'state' => $this->state,
			'progress' => $this->progress,
			'error' => $this->error,
			'created' => $this->creation,
			'updated' => $this->updated,
		];
	}
}
