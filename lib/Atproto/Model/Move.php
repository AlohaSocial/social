<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

/**
 * A move of a Bluesky account between this server and another PDS (§13):
 * where to or from, the step it has reached, and how it ended.
 *
 * Moving away goes through the steps in order: the account is made on the
 * other PDS when the move starts, then its repository, its blobs and its
 * preferences are copied there, the DID is pointed there, the account is
 * activated there and switched off here.
 *
 * Moving here: the repository, the blobs, the preferences and the follows
 * are copied from the old PDS, the old PDS e-mails the person a code, and
 * with it signs the operation that points the DID here; the account then
 * takes the DID, and is switched off there; its posts become posts here.
 *
 * Moving here inbound, the other side drives — Bridgy Fed for a bridged
 * account, or a migration tool: the person invites the DID with a one-time
 * code, the other side makes the account here with it, sends the
 * repository and the blobs, points the DID here and activates the account;
 * then its follows become follows here, and its posts posts here.
 */
final class Move {
	public const AWAY = 'away';
	public const IN = 'in';
	/** moving here, driven by the other side */
	public const INBOUND = 'inbound';

	public const STEP_REPO = 'repo';
	public const STEP_BLOBS = 'blobs';
	public const STEP_PREFERENCES = 'prefs';
	public const STEP_IDENTITY = 'identity';
	public const STEP_ACTIVATE = 'activate';
	public const STEP_DONE = 'done';
	/** moving here: the account's follows become follows here */
	public const STEP_FOLLOWS = 'follows';
	/** moving here: the old PDS e-mailed a code, and the person enters it */
	public const STEP_CODE = 'code';
	/** moving here: the account's posts become posts in its timeline here */
	public const STEP_POSTS = 'posts';
	/** moving here inbound: the account is not made here yet */
	public const STEP_INVITED = 'invited';

	public const RUNNING = 'running';
	/** waiting for the person: the code the old PDS e-mailed them */
	public const WAITING = 'waiting';
	public const FAILED = 'failed';
	public const DONE = 'done';

	/**
	 * @param array{blobs?: int, records?: int, follows?: int, expectedBlobs?: int, posts?: int, likes?: int, reposts?: int, lists?: int} $progress counts of what was copied
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

	/** The step after one when moving here; the last is done. */
	public static function nextIn(string $step): string {
		return match ($step) {
			self::STEP_REPO => self::STEP_BLOBS,
			self::STEP_BLOBS => self::STEP_PREFERENCES,
			self::STEP_PREFERENCES => self::STEP_FOLLOWS,
			self::STEP_FOLLOWS => self::STEP_CODE,
			self::STEP_CODE => self::STEP_IDENTITY,
			self::STEP_IDENTITY => self::STEP_ACTIVATE,
			self::STEP_ACTIVATE => self::STEP_POSTS,
			default => self::STEP_DONE,
		};
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
