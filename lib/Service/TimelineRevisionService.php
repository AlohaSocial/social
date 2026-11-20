<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActorsRequest;
use Throwable;

/**
 * A number that moves whenever a reader changes what their own timeline
 * shows, carried in the home timeline's `ETag` beside the newest visible
 * post: unfollowing, blocking, muting, filtering or following a hashtag
 * changes the page without moving the newest id, and must not be answered
 * `304`.
 *
 * One integer per Nextcloud account in user config, bumped by the request
 * classes that write the rows deciding the page, so every path moves it.
 * Not locked: racing bumps may land on the same number, which still differs
 * from the one before. A deletion further down a page does not move it;
 * reaching every follower's tag would be the fan-out this avoids.
 */
class TimelineRevisionService {
	public const KEY = 'timeline_revision';

	/**
	 * The same kind of number for the badge counts, moved by every row they
	 * count (a notification, a direct message, a conversation read or
	 * dismissed): rows within one second may sort below the newest id and move
	 * nothing.
	 */
	public const NOTIFICATIONS = 'notifications_revision';
	public const CONVERSATIONS = 'conversations_revision';
	public const NOTIFICATIONS_MERGED = 'notifications_merged_revision';

	public function __construct(
		private ActorsRequest $actorsRequest,
		private ConfigService $configService,
	) {
	}

	/**
	 * What the reader's timelines are at, for the tag.
	 *
	 * @param string $key `KEY`, or one of the badges' numbers
	 */
	public function of(string $userId, string $key = self::KEY): int {
		if ($userId === '') {
			return 0;
		}

		return (int)$this->configService->getValueForUser($userId, $key);
	}

	/**
	 * The same number read from the database rather than this process's cache,
	 * for the streaming daemon, which outlives the requests that bump it.
	 *
	 * @param string $key `KEY`, or one of the badges' numbers
	 */
	public function current(string $userId, string $key = self::KEY): int {
		$this->configService->forgetUserValues($userId);

		return $this->of($userId, $key);
	}

	/**
	 * Something this account decided has changed what its timelines show.
	 *
	 * @param string $key `KEY`, or one of the badges' numbers
	 */
	public function bump(string $userId, string $key = self::KEY): void {
		if ($userId === '') {
			return;
		}

		$this->configService->setValueForUser($userId, $key, (string)($this->of($userId, $key) + 1));
	}

	/**
	 * The same, for callers holding an actor rather than an account; a remote
	 * actor's rows belong to nobody here.
	 */
	public function bumpForActor(string $actorId, string $key = self::KEY): void {
		if ($actorId === '') {
			return;
		}

		try {
			$this->bump($this->actorsRequest->getFromId($actorId)->getUserId(), $key);
		} catch (Throwable $e) {
			// not a local account, or none any more: there is no timeline here
			// whose tag this would key, and a follow is not worth failing over
		}
	}
}
