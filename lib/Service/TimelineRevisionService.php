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
 * A number that moves whenever a reader changes what their own timeline shows.
 *
 * The home timeline's `ETag` is built from the newest post the reader can see,
 * which answers "has anything new arrived" and nothing else. Everything else
 * that decides what the page holds is a decision the reader made — following or
 * unfollowing an account, blocking or muting one, adding a keyword filter,
 * following a hashtag — and none of those move the newest id. Without this, a
 * reader who unfollows a noisy account is answered `304` on every poll and goes
 * on seeing it until somebody else happens to post: the page they are looking
 * at is the one they just asked to change.
 *
 * So the tag carries this alongside. It is one integer per Nextcloud account,
 * incremented where the rows that decide the page are written — in the request
 * classes that own them, so it moves whether the change came from the web app,
 * a Mastodon client or the inbox, and no caller has to remember to say so.
 *
 * Reading it is one user-config lookup, which Nextcloud has usually already
 * loaded for the session; a remote actor has no Nextcloud account behind it and
 * bumping for one costs a lookup and does nothing.
 *
 * The increment is read-modify-write and deliberately not locked: two changes
 * racing may end on the same number, which loses nothing that matters — the
 * number is still different from the one before either of them, which is the
 * only thing the tag is asked.
 *
 * What it does **not** cover is a post being deleted further down a page the
 * reader already holds: the newest id does not move, this number does not move
 * for a stranger's decision, and the page is revalidated when either next
 * changes. Making a deletion reach every follower's tag means writing to every
 * follower's row, which is the fan-out this whole design exists to avoid.
 */
class TimelineRevisionService {
	public const KEY = 'timeline_revision';

	/**
	 * The same kind of number for the counts on the badges, moved whenever a
	 * row they count is written: a notification for the account, a direct
	 * message to or from it — and for conversations also when one is marked
	 * read or dismissed. The newest id cannot say so alone: a nid orders by
	 * its second first and by chance within one, so a second row in the same
	 * second may sort below the first and move no newest id.
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
	 * The same number read from the database rather than from what this
	 * process loaded before: for the streaming daemon, which outlives the
	 * requests that bump it.
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
	 * The same, for the places that hold an actor rather than an account: the
	 * request classes write rows keyed by actor id, and a row written for a
	 * remote actor belongs to nobody here.
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
