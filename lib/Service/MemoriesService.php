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
 * The reader looking back at their own posting: `onThisDay()`, their posts
 * from this calendar day in earlier years, and `recap()`, the week just gone
 * (off until the reader asks for it).
 *
 * Only ever the caller's own posts, so followers-only and direct posts are
 * included without an audience filter; the controller must pass the viewer's
 * own actor. Each year back is its own calendar day in the reader's timezone.
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
	 * The user config key the weekly recap is switched on with; off unless it
	 * is `'1'`.
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
	 * How the reader's week went, and the one before for comparison, read off
	 * the profile's twelve-week chart so the two agree. Deliberately no
	 * streak, which would pressure people to post.
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

			// modify() on 29 February lands on 1 March in other years, so a
			// leap day is remembered on leap years only
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
