<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * The reader looking back at their own posting: what they wrote on this day in
 * years gone by, and how the last week went.
 *
 * An internal social feed is a company's memory, and almost all of it is
 * unreachable the moment it scrolls off the timeline: a post is found again
 * only by somebody who remembers enough of it to search for it. `onThisDay()`
 * is the one view that surfaces old posts without being asked — the same day,
 * a year or more ago. `recap()` is the other direction, the week just gone,
 * and it is off until the reader asks for it.
 *
 * **Only ever the caller's own posts.** Not a privacy convenience but the
 * whole design: because it is the reader's own, it can include their
 * followers-only and direct posts without asking who is allowed to see what.
 * The controller must pass the viewer's own actor and nothing else, and the
 * query it uses does not filter by audience.
 *
 * **Anniversaries, not a window.** A post from "about a year ago" is not a
 * memory, it is a random old post. Each year back is looked up as its own
 * calendar day in the reader's own timezone, so the 4th of March finds the 4th
 * of March and a post written at 23:50 does not belong to the next day.
 */
class MemoriesService {
	/** How many years back to look. */
	private const YEARS = 5;

	/**
	 * How old a post has to be, in seconds, to be a memory today somewhere: a
	 * year less two days, which covers the reader's day in every timezone.
	 */
	private const OLD_ENOUGH = 363 * 86400;

	/** How many posts one year may contribute. */
	private const PER_YEAR = 3;

	/** How many come back in all, however many years had something. */
	private const TOTAL = 6;

	/**
	 * The user config key the weekly recap is switched on with.
	 *
	 * Off unless it says `'1'`: a card that appears in somebody's feed
	 * commenting on how much they have posted is something to ask for, not
	 * something to be given.
	 */
	public const RECAP_KEY = 'weekly_recap';

	public function __construct(
		private StreamRequest $streamRequest,
		private ConfigService $configService,
		private ProfileHighlightsService $profileHighlightsService,
	) {
	}

	/** @return bool whether this reader asked for the weekly recap */
	public function recapEnabled(string $userId): bool {
		return $this->configService->getValueForUser($userId, self::RECAP_KEY) === '1';
	}

	/** Turns the weekly recap on or off for one reader. */
	public function setRecapEnabled(string $userId, bool $enabled): void {
		$this->configService->setValueForUser($userId, self::RECAP_KEY, $enabled ? '1' : '0');
	}

	/**
	 * How the reader's week went, and the one before it for comparison.
	 *
	 * Read off the same twelve-week chart the profile draws, rather than
	 * counted again: one place decides what a week is and what counts as a
	 * post, and the number here cannot disagree with the number on the
	 * reader's own profile.
	 *
	 * No streak, deliberately. A count of consecutive weeks turns posting into
	 * something that can be lost, and a feed that tells somebody they have
	 * broken a run is asking for posts rather than offering anything.
	 *
	 * @return array{enabled: bool, this_week: int, last_week: int}
	 */
	public function recap(Person $actor, string $userId): array {
		$enabled = $this->recapEnabled($userId);
		if (!$enabled) {
			return ['enabled' => false, 'this_week' => 0, 'last_week' => 0];
		}

		$weeks = $this->profileHighlightsService->forActor($actor)['weeks'];
		$count = count($weeks);

		return [
			'enabled' => true,
			'this_week' => ($count > 0) ? $weeks[$count - 1] : 0,
			'last_week' => ($count > 1) ? $weeks[$count - 2] : 0,
		];
	}

	/**
	 * Whether `onThisDay()` can find anything at all: whether the reader has a
	 * post (not a reply) old enough to be from a year ago in any timezone.
	 * One row is read; most accounts are younger than a year.
	 */
	public function mayHaveMemories(Person $actor, ?int $now = null): bool {
		$until = ($now ?? time()) - self::OLD_ENOUGH;

		return $actor->getId() !== '' && $until > 0
			&& $this->streamRequest->getByAuthorBetween($actor->getId(), 0, $until, 1, ACore::FORMAT_LOCAL) !== [];
	}

	/**
	 * The reader's own posts from this day in previous years, newest first.
	 *
	 * @param Person $actor the caller's own actor, never anybody else's
	 * @param DateTimeZone $timezone the reader's timezone; a "day" is theirs,
	 *                               not the server's
	 * @param DateTimeImmutable|null $today injectable so this can be tested
	 * @return Stream[]
	 */
	public function onThisDay(Person $actor, DateTimeZone $timezone, ?DateTimeImmutable $today = null): array {
		$actorId = $actor->getId();
		if ($actorId === '') {
			return [];
		}

		$today ??= new DateTimeImmutable('now', $timezone);
		$today = $today->setTimezone($timezone);

		$memories = [];
		for ($years = 1; $years <= self::YEARS; $years++) {
			$from = $today->modify(sprintf('-%d years', $years))->setTime(0, 0, 0);

			// modify() on the 29th of February lands on the 1st of March in a
			// year that has no 29th, which is a different day's posts. A leap
			// day is remembered on leap years only.
			if ($from->format('m-d') !== $today->format('m-d')) {
				continue;
			}

			$until = $from->modify('+1 day');

			foreach ($this->streamRequest->getByAuthorBetween(
				$actorId,
				$from->getTimestamp(),
				$until->getTimestamp(),
				self::PER_YEAR,
				ACore::FORMAT_LOCAL
			) as $post) {
				$memories[] = $post;
			}
		}

		usort(
			$memories,
			static fn (Stream $a, Stream $b): int => $b->getPublishedTime() <=> $a->getPublishedTime()
		);

		return array_slice($memories, 0, self::TOTAL);
	}
}
