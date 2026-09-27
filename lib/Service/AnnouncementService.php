<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\AnnouncementsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Announcement;

/**
 * Mastodon's announcements: the notice an admin posts to the whole instance,
 * and the dismissal that makes it read for one account.
 *
 * Two surfaces meet here and they are not the same read. A client is answered
 * with the announcements that apply *now* — the window is a predicate of the
 * query, so nothing has to run for one to start or stop — each carrying
 * whether that account has dismissed it. The administration page is answered
 * with every announcement there is, including one that has not started and one
 * that has run out, because those are exactly the ones an admin needs to see
 * to act on.
 *
 * Dismissal is per account and stored as such: `read` is filled in from the
 * account being answered and from nothing else, so one account's read state is
 * never another's. Dismissing does not hide the announcement — Mastodon keeps
 * serving it and flips `read`, which is what lets a client show the notice
 * with its unread mark gone rather than have it vanish mid-read.
 *
 * There is no edit route. An announcement is a thing people have already read;
 * changing one under them would leave some accounts having dismissed a notice
 * that now says something else, and Mastodon's own answer to that — bumping
 * `updated_at` and leaving the dismissals — is worse than posting a new one.
 * `updated_at` is therefore always `published_at`, which is what the column
 * holds.
 */
class AnnouncementService {
	/**
	 * How many distinct emoji one account may put on one announcement.
	 *
	 * Mastodon's own ceiling. Without one, a notice is a free row generator
	 * for whoever wants to make the reaction list unreadable for everybody.
	 */
	public const MAX_REACTIONS_PER_ACCOUNT = 8;

	/**
	 * Code points an emoji is built from but that are not emoji themselves:
	 * the digits and symbols a keycap is written on, the joiner, the two
	 * variation selectors, the tag characters of a subdivision flag, and the
	 * five skin tones.
	 */
	private const EMOJI_PART = '\x{0023}\x{002A}\x{0030}-\x{0039}\x{200D}\x{FE0E}\x{FE0F}'
		. '\x{E0020}-\x{E007F}\x{1F3FB}-\x{1F3FF}';

	/** Code points that are an emoji on their own. */
	private const EMOJI_PICTOGRAPHIC = '\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}'
		. '\x{2194}-\x{21AA}\x{231A}-\x{23FA}\x{24C2}\x{25AA}-\x{27BF}'
		. '\x{2934}-\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{20E3}'
		. '\x{1F000}-\x{1FAFF}';

	public function __construct(
		private AnnouncementsRequest $announcementsRequest,
		private EmojiService $emojiService,
	) {
	}

	/**
	 * The announcements that apply, oldest window first, each carrying whether
	 * this account has dismissed it.
	 *
	 * Ordered the way Mastodon orders them — by when the announcement takes
	 * effect, which is its start when it has one and its publication when it
	 * does not. That is a COALESCE of two columns and it is done here rather
	 * than in SQL: the set is the handful of announcements that are live at
	 * this moment, and a sort of a handful of rows is not worth an expression
	 * four databases have to agree on.
	 *
	 * @return Announcement[]
	 */
	public function active(string $actorId, ?int $now = null): array {
		$announcements = $this->announcementsRequest->getActive($now);
		if ($announcements === []) {
			return [];
		}

		$dismissed = $this->announcementsRequest->dismissedBy(
			$actorId,
			array_map(static fn (Announcement $announcement): int => $announcement->getId(), $announcements)
		);

		$reactions = $this->announcementsRequest->reactionsOn(
			$actorId,
			array_map(static fn (Announcement $announcement): int => $announcement->getId(), $announcements)
		);

		foreach ($announcements as $announcement) {
			$announcement->setRead(in_array($announcement->getId(), $dismissed, true));
			$announcement->setReactions(
				$this->withPictures($reactions[$announcement->getId()] ?? [])
			);
		}

		usort(
			$announcements,
			static fn (Announcement $a, Announcement $b): int
				=> [self::effectiveStart($a), $a->getId()] <=> [self::effectiveStart($b), $b->getId()]
		);

		return $announcements;
	}

	/**
	 * Marks it read for that account.
	 *
	 * The announcement is read first so that an id that names nothing is a 404
	 * rather than a dismissal row pointing at nothing — and an announcement
	 * outside its window can still be dismissed, because a client that was
	 * showing it when it ran out must be able to put it away.
	 *
	 * @throws ItemNotFoundException
	 */
	public function dismiss(int $id, string $actorId): void {
		$announcement = $this->announcementsRequest->getById($id);
		$this->announcementsRequest->dismiss($announcement->getId(), $actorId);
	}

	/**
	 * Puts an emoji on an announcement, for one account.
	 *
	 * The only thing an account can say back about an instance-wide notice.
	 * Without it the only thing anybody could do with one was put it away, and
	 * an admin who posted one had no way of telling whether it had landed.
	 *
	 * The announcement is read first, so an id that names nothing is a 404
	 * rather than a row pointing at nothing — and one outside its window can
	 * still be reacted to, because a client that was showing it when it ran
	 * out must be able to finish what the reader started.
	 *
	 * @throws ItemNotFoundException the announcement
	 * @throws InvalidResourceException the emoji, or too many of them
	 */
	public function react(int $id, string $actorId, string $name): void {
		$announcement = $this->announcementsRequest->getById($id);
		$name = $this->assertReactable($name);

		// asked before the write rather than after: the unique index makes
		// re-reacting a no-op, so an account at the ceiling can still take one
		// back and put the same one on again
		if ($this->announcementsRequest->countReactionsBy($announcement->getId(), $actorId)
			>= self::MAX_REACTIONS_PER_ACCOUNT) {
			throw new InvalidResourceException(
				'an account may put at most ' . self::MAX_REACTIONS_PER_ACCOUNT
				. ' reactions on one announcement'
			);
		}

		$this->announcementsRequest->react($announcement->getId(), $actorId, $name);
	}

	/**
	 * Takes one back.
	 *
	 * Taking back one that was never there succeeds: Mastodon answers the same
	 * way, and a client that has lost track of what it sent should not be told
	 * the announcement does not exist.
	 *
	 * @throws ItemNotFoundException the announcement
	 */
	public function unreact(int $id, string $actorId, string $name): void {
		$announcement = $this->announcementsRequest->getById($id);
		$this->announcementsRequest->unreact($announcement->getId(), $actorId, trim($name));
	}

	/**
	 * What may be reacted with: one Unicode emoji, or the shortcode of one
	 * this instance publishes.
	 *
	 * The same two things Mastodon accepts. Anything else would be a label an
	 * account wrote on an instance-wide notice, shown to everybody who reads
	 * it — which is not a reaction, it is a second announcement.
	 *
	 * @throws InvalidResourceException
	 */
	private function assertReactable(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidResourceException('no emoji given');
		}

		if ($this->emojiService->byShortcode($name) !== null) {
			return strtolower($name);
		}

		if (!self::isEmoji($name)) {
			throw new InvalidResourceException(
				'a reaction is one emoji, or the shortcode of one this instance publishes'
			);
		}

		return $name;
	}

	/**
	 * Whether a string is a single emoji.
	 *
	 * Written out as code-point ranges rather than asked of `intl`, which this
	 * app does not otherwise need and does not declare.
	 *
	 * One emoji is often several code points — a flag is two regional
	 * indicators, a family is several people joined by ZWJ, a keycap is a
	 * digit and an enclosing mark, a waving hand may carry a skin tone — so
	 * this does two things. Every code point has to be one an emoji is built
	 * from, which keeps out letters and words; and the pictographic ones have
	 * to add up to exactly one emoji, which keeps out `😀😀` and two flags.
	 * A label somebody wrote on an instance-wide notice, shown to everybody
	 * who reads it, is not a reaction — it is a second announcement.
	 */
	public static function isEmoji(string $name): bool {
		// long enough for a ZWJ family with skin tones, and no longer
		if ($name === '' || mb_strlen($name) > 12) {
			return false;
		}

		if (preg_match('/^[' . self::EMOJI_PART . self::EMOJI_PICTOGRAPHIC . ']+$/u', $name) !== 1) {
			return false;
		}

		$bases = 0;
		$regional = 0;
		$joined = false;
		foreach (preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $point) {
			if ($point === "\u{200D}") {
				$joined = true;

				continue;
			}

			// a flag is two of these and nothing else, which is why they are
			// counted apart from everything else pictographic
			if (preg_match('/^[\x{1F1E6}-\x{1F1FF}]$/u', $point) === 1) {
				$regional++;
			} elseif (preg_match('/^[' . self::EMOJI_PART . ']$/u', $point) === 1) {
				// a skin tone and a variation selector modify the emoji before
				// them rather than being one; tested first because the tone
				// modifiers also fall inside the pictographic block, and a
				// waving hand with a skin tone is one wave
				$joined = false;

				continue;
			} elseif (preg_match('/^[' . self::EMOJI_PICTOGRAPHIC . ']$/u', $point) === 1 && !$joined) {
				$bases++;
			}

			$joined = false;
		}

		return ($regional > 0) ? ($bases === 0 && $regional === 2) : ($bases === 1);
	}

	/**
	 * The reaction counts, with the picture behind any that names a custom
	 * emoji this instance publishes.
	 *
	 * A client reads a reaction with no `url` as a character to render and one
	 * with a `url` as a picture to fetch, so a shortcode without it renders as
	 * the literal text `blobcat`.
	 *
	 * @param array<string, array{count: int, me: bool}> $reactions
	 *
	 * @return array<string, array{count: int, me: bool, url?: string}>
	 */
	private function withPictures(array $reactions): array {
		foreach (array_keys($reactions) as $name) {
			$emoji = $this->emojiService->byShortcode($name);
			if ($emoji !== null) {
				$reactions[$name]['url'] = $emoji->getUrl();
			}
		}

		return $reactions;
	}

	/**
	 * Every announcement, newest first, as the administration page shows one:
	 * the text as it was typed, its window, and whether it is being served at
	 * this moment.
	 *
	 * Not the client entity — an admin is looking at what they wrote, not at
	 * the HTML a client renders, and `read` would be the admin's own.
	 *
	 * @return array<int, array<string, string|bool|null>>
	 */
	public function adminList(?int $now = null): array {
		$now ??= time();

		$rows = [];
		foreach ($this->announcementsRequest->getAll() as $announcement) {
			$rows[] = [
				'id' => (string)$announcement->getId(),
				'text' => $announcement->getText(),
				'starts_at' => Announcement::datetime($announcement->getStartsAt()),
				'ends_at' => Announcement::datetime($announcement->getEndsAt()),
				'all_day' => $announcement->hasRange() && $announcement->isAllDay(),
				'published_at' => Announcement::datetime($announcement->getPublishedAt()),
				'active' => $announcement->isActiveAt($now),
			];
		}

		return $rows;
	}

	/**
	 * Posts an announcement.
	 *
	 * @param string $startsAt when it starts applying, as a date or datetime
	 *                         the server can read; '' for no bound
	 * @param string $endsAt the same, for when it stops
	 *
	 * @throws InvalidResourceException nothing was typed, it is longer than
	 *                                  the column takes, a date cannot be
	 *                                  read, only one bound was given, or the
	 *                                  window ends before it starts
	 */
	public function create(string $text, string $startsAt = '', string $endsAt = '', bool $allDay = false): Announcement {
		$text = trim($text);
		if ($text === '') {
			throw new InvalidResourceException('Text cannot be blank');
		}

		if (mb_strlen($text, 'UTF-8') > Announcement::MAX_TEXT) {
			throw new InvalidResourceException(
				'Text is longer than ' . Announcement::MAX_TEXT . ' characters'
			);
		}

		$starts = $this->timestamp($startsAt, 'starts_at');
		$ends = $this->timestamp($endsAt, 'ends_at');

		// Mastodon's own rule: a range is both of its bounds. One bound alone
		// describes no window, and the pair is what `all_day` is about
		if (($starts === 0) !== ($ends === 0)) {
			throw new InvalidResourceException('starts_at and ends_at are given together or not at all');
		}

		if ($allDay && $starts > 0) {
			// whole days rather than the minute the admin happened to pick:
			// the window opens at the start of its first day and closes at the
			// start of the day after its last, which is the moment the read
			// stops matching
			$starts = (int)strtotime('midnight', $starts);
			$ends = (int)strtotime('midnight +1 day', $ends);
		}

		if ($ends > 0 && $ends <= $starts) {
			throw new InvalidResourceException('ends_at is not after starts_at');
		}

		$announcement = new Announcement();
		$announcement->setText($text)
			->setStartsAt($starts)
			->setEndsAt($ends)
			->setAllDay($allDay && $starts > 0);

		$this->announcementsRequest->save($announcement);

		return $announcement;
	}

	/**
	 * Removes an announcement and every dismissal of it.
	 *
	 * @throws ItemNotFoundException there is no such announcement
	 */
	public function delete(int $id): void {
		$this->announcementsRequest->delete($id);
	}

	/** When an announcement takes effect: its start, or its publication. */
	private static function effectiveStart(Announcement $announcement): int {
		return ($announcement->getStartsAt() > 0)
			? $announcement->getStartsAt()
			: $announcement->getPublishedAt();
	}

	/**
	 * @throws InvalidResourceException the field is not a date this server can
	 *                                  read — refused rather than stored as
	 *                                  "no bound", which would publish to
	 *                                  everybody an announcement the admin
	 *                                  scheduled
	 */
	private function timestamp(string $date, string $field): int {
		$date = trim($date);
		if ($date === '') {
			return 0;
		}

		$timestamp = strtotime($date);
		if ($timestamp === false) {
			throw new InvalidResourceException($field . ' is not a date');
		}

		return $timestamp;
	}
}
