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
 * and the per-account dismissal that marks it read.
 *
 * Clients get the announcements live now (the window is a query predicate),
 * each with that account's `read`; dismissed ones are still served, as on
 * Mastodon. The administration page gets all of them, scheduled and expired
 * included. There is no edit, so `updated_at` is always `published_at`.
 */
class AnnouncementService {
	/**
	 * How many distinct emoji one account may put on one announcement:
	 * Mastodon's ceiling.
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
	 * The announcements that apply, each with whether this account dismissed
	 * them, ordered as Mastodon does by start (or publication when unset);
	 * sorted in PHP, as the live set is a handful of rows.
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

	/** Whether any announcement applies now, read or not: what `active()` would list. */
	public function hasActive(?int $now = null): bool {
		return $this->announcementsRequest->getActive($now) !== [];
	}

	/**
	 * Marks it read for that account. An unknown id is a 404; one outside its
	 * window can still be dismissed by a client that was showing it.
	 *
	 * @throws ItemNotFoundException
	 */
	public function dismiss(int $id, string $actorId): void {
		$announcement = $this->announcementsRequest->getById($id);
		$this->announcementsRequest->dismiss($announcement->getId(), $actorId);
	}

	/**
	 * Puts an emoji on an announcement, for one account. An unknown id is a
	 * 404; one outside its window can still be reacted to.
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
	 * Takes one back. Taking back one that was never there succeeds, as on
	 * Mastodon.
	 *
	 * @throws ItemNotFoundException the announcement
	 */
	public function unreact(int $id, string $actorId, string $name): void {
		$announcement = $this->announcementsRequest->getById($id);
		$this->announcementsRequest->unreact($announcement->getId(), $actorId, trim($name));
	}

	/**
	 * What may be reacted with: one Unicode emoji, or the shortcode of one
	 * this instance publishes, as on Mastodon. Free text would be a second
	 * announcement, not a reaction.
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
	 * Whether a string is a single emoji, by code-point ranges rather than the
	 * undeclared `intl`. Every code point must be an emoji building block, and
	 * the pictographic ones must make exactly one emoji (flags, ZWJ sequences,
	 * keycaps and skin tones included).
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
				// skin tones and variation selectors modify the emoji before
				// them; tested first because the tones also fall inside the
				// pictographic block
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
	 * The reaction counts, with the image URL of any custom emoji this
	 * instance publishes; without a `url`, clients render the shortcode as
	 * text.
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
	 * Every announcement, newest first, for the administration page: the text
	 * as typed, its window, and whether it is live now.
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
			// whole days: the window opens at the start of its first day and
			// closes at the start of the day after its last
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
	 *                                  read; refused rather than treated as no
	 *                                  bound, which would publish it at once
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
