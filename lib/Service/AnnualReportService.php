<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Details;

/**
 * The year an account had, as Mastodon's `#Wrapstodon` reports it.
 *
 * Five routes and one entity, and a client that has the feature — the official
 * apps do — shows a card in December that says nothing at all on an instance
 * that does not serve them. What it holds is a year in twelve rows (what was
 * posted, who arrived), the hashtags that were used most, the three posts that
 * travelled furthest, and a one-word description of how the account is used.
 *
 * **Computed on demand and never stored.** Mastodon generates these with a
 * background job and keeps the result, which is why its API has a `generating`
 * state and a `generate` route. Here the same answer comes out of the posts
 * that are already in the database, in one read of the year, so there is
 * nothing to generate and nothing to keep in step: `generate` is a no-op that
 * answers 200 because a client will call it, and the state is `available` or
 * `ineligible` and never `generating`. A report that is a query cannot go
 * stale, and an instance that never runs its cron still has one.
 *
 * **`share_url` is null.** Mastodon's points at a public page of its own; this
 * app has none, and inventing an address would be a link that 404s in
 * somebody's post.
 */
class AnnualReportService {
	/** The shape of `data`, as Mastodon 4.3 defined it. */
	public const SCHEMA_VERSION = 1;

	/** How many posts one page of the year's walk reads. */
	private const PAGE = 100;

	/**
	 * The most posts one report walks.
	 *
	 * A ceiling rather than a promise: the walk is one account's own year, and
	 * an account that wrote more than this in a year gets a report over its
	 * most recent ten thousand posts rather than a request that never returns.
	 */
	private const MAX_POSTS = 10000;

	/** The most followers the "who arrived" series is built from. */
	private const MAX_FOLLOWERS = 5000;

	private const TOP_HASHTAGS = 10;

	/** Where the years an account has marked read are kept. */
	private const READ_KEY = 'annual_reports_read';

	/**
	 * How the account is used, in one word — Mastodon's five.
	 *
	 * The thresholds are this app's own and are deliberately plain: what a
	 * reader wants from the word is to recognise themselves, not to be able to
	 * reproduce the arithmetic.
	 */
	public const ARCHETYPE_LURKER = 'lurker';
	public const ARCHETYPE_BOOSTER = 'booster';
	public const ARCHETYPE_POLLSTER = 'pollster';
	public const ARCHETYPE_REPLIER = 'replier';
	public const ARCHETYPE_ORACLE = 'oracle';

	public function __construct(
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private ConfigService $configService,
	) {
	}

	/**
	 * The years this account could have a report for: the ones it wrote
	 * something in, newest first, and never a year that has not ended for an
	 * account that has written nothing in it.
	 *
	 * @return int[]
	 */
	public function years(Person $actor): array {
		$years = [];
		foreach ($this->posts($actor) as $post) {
			$published = $post->getPublishedTime();
			if ($published > 0) {
				$years[(int)gmdate('Y', $published)] = true;
			}
		}

		$years = array_keys($years);
		rsort($years);

		return $years;
	}

	/**
	 * One year's report.
	 *
	 * @return array<string, mixed> the AnnualReport entity
	 */
	public function forYear(Person $actor, int $year): array {
		[$from, $until] = $this->bounds($year);

		$months = $this->emptyMonths();
		$hashtags = [];
		$best = ['by_reblogs' => null, 'by_replies' => null, 'by_favourites' => null];
		$most = ['by_reblogs' => 0, 'by_replies' => 0, 'by_favourites' => 0];
		$written = 0;
		$boosts = 0;
		$replies = 0;
		$polls = 0;

		foreach ($this->posts($actor) as $post) {
			$published = $post->getPublishedTime();
			if ($published < $from) {
				// the walk is newest first, so the first post before the year
				// began is where the year ends
				break;
			}
			if ($published >= $until) {
				continue;
			}

			$month = (int)gmdate('n', $published);
			$months[$month]['statuses']++;
			$written++;

			if ($post->getType() === 'Announce' || $post->getSubType() === 'Announce') {
				// a boost is something the account did rather than something it
				// wrote: counted, and then left out of everything below,
				// because its hashtags and its likes belong to whoever wrote it
				$boosts++;
				continue;
			}

			if ($post->getInReplyTo() !== '') {
				$replies++;
			}
			if ($post->getType() === 'Question' || $post->getSubType() === 'Question') {
				$polls++;
			}

			foreach ($post->getHashtags() as $hashtag) {
				$tag = ltrim(strtolower((string)$hashtag), '#');
				if ($tag !== '') {
					$hashtags[$tag] = ($hashtags[$tag] ?? 0) + 1;
				}
			}

			foreach ([
				'by_reblogs' => $post->getDetailInt(Details::BOOSTS),
				'by_replies' => $post->getDetailInt(Details::REPLIES),
				'by_favourites' => $post->getDetailInt(Details::LIKES),
			] as $key => $count) {
				if ($count > $most[$key]) {
					$most[$key] = $count;
					// the id a client addresses a status with, which is what
					// the entity's `statuses` list is keyed by
					$best[$key] = (string)$post->getNid();
				}
			}
		}

		foreach ($this->followersGained($actor, $from, $until) as $month => $count) {
			$months[$month]['followers'] = $count;
		}

		arsort($hashtags);
		$top = [];
		foreach (array_slice($hashtags, 0, self::TOP_HASHTAGS, true) as $name => $count) {
			$top[] = ['name' => $name, 'count' => $count];
		}

		return [
			'year' => $year,
			'data' => [
				'archetype' => $this->archetype($written, $boosts, $replies, $polls),
				'time_series' => array_values($months),
				'top_hashtags' => $top,
				'top_statuses' => $best,
			],
			'schema_version' => self::SCHEMA_VERSION,
			// Mastodon's points at a public page of its own; this app has none,
			// and inventing an address would be a link that 404s in a post
			'share_url' => null,
			'account_id' => (string)$actor->getNid(),
		];
	}

	/**
	 * Whether a year has a report, in the four words Mastodon uses.
	 *
	 * `generating` never comes back: the report is a query rather than a job,
	 * so there is nothing to wait for. A year the account wrote nothing in is
	 * `ineligible` — a report of twelve empty months is worse than the client
	 * showing nothing.
	 */
	public function state(Person $actor, int $year): string {
		if ($year > (int)gmdate('Y')) {
			return 'ineligible';
		}

		return in_array($year, $this->years($actor), true) ? 'available' : 'ineligible';
	}

	/**
	 * Marks a year read, so a client stops offering it.
	 *
	 * A per-account preference rather than a column, because that is all it
	 * is: a note this account keeps about a report that is not stored either.
	 */
	public function markRead(string $userId, int $year): void {
		$years = $this->readYears($userId);
		if (in_array($year, $years, true)) {
			return;
		}

		$years[] = $year;
		sort($years);
		$this->configService->setUserValue(self::READ_KEY, implode(',', $years));
	}

	/** @return int[] the years this account has marked read */
	public function readYears(string $userId): array {
		$stored = $this->configService->getUserValue(self::READ_KEY, $userId);
		$years = [];
		foreach (explode(',', $stored) as $year) {
			$year = (int)trim($year);
			if ($year > 0) {
				$years[] = $year;
			}
		}

		return $years;
	}

	/**
	 * How the account is used, in one word.
	 *
	 * Order matters: the first rule that fits wins, and they are arranged from
	 * the most specific to the most general. Somebody who wrote almost nothing
	 * is a lurker whatever the little they wrote was; after that it is whether
	 * most of what they did was boosting, asking, or answering; and what is
	 * left — somebody who mostly writes their own posts — is the oracle.
	 */
	private function archetype(int $written, int $boosts, int $replies, int $polls): string {
		if ($written < 10) {
			return self::ARCHETYPE_LURKER;
		}

		if ($boosts * 2 > $written) {
			return self::ARCHETYPE_BOOSTER;
		}

		if ($polls > 0 && $polls * 10 >= $written) {
			return self::ARCHETYPE_POLLSTER;
		}

		if ($replies * 2 > $written) {
			return self::ARCHETYPE_REPLIER;
		}

		return self::ARCHETYPE_ORACLE;
	}

	/**
	 * How many followers arrived in each month of the year.
	 *
	 * Read from the follow rows' own dates. Capped, like every other read that
	 * cannot be paged by date: an account with more followers than the cap
	 * gets the most recent ones, which for a report about one year is the ones
	 * the year is about.
	 *
	 * @return array<int, int> month (1-12) to the number who arrived
	 */
	private function followersGained(Person $actor, int $from, int $until): array {
		$byMonth = [];
		foreach ($this->followsRequest->getFollowerOrigins($actor->getId(), self::MAX_FOLLOWERS) as $row) {
			$when = strtotime((string)($row['creation'] ?? ''));
			if ($when === false || $when < $from || $when >= $until) {
				continue;
			}

			$month = (int)gmdate('n', $when);
			$byMonth[$month] = ($byMonth[$month] ?? 0) + 1;
		}

		return $byMonth;
	}

	/**
	 * The account's own posts, newest first.
	 *
	 * The same paged walk the statistics page uses, and for the same reason: a
	 * generator so the caller stops where its year does, rather than a query
	 * that returns everything to be filtered afterwards.
	 *
	 * @return iterable<Stream>
	 */
	private function posts(Person $actor): iterable {
		$this->streamRequest->setViewer($actor);
		$maxId = 0;
		$seen = 0;

		while ($seen < self::MAX_POSTS) {
			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
				->setProbe(ProbeOptions::ACCOUNT)
				->setAccountId($actor->getId())
				->setLimit(min(self::PAGE, self::MAX_POSTS - $seen));
			if ($maxId > 0) {
				$options->setMaxId($maxId);
			}

			$page = $this->streamRequest->getTimeline($options);
			if ($page === []) {
				return;
			}

			foreach ($page as $post) {
				$seen++;
				yield $post;
			}

			$last = end($page);
			$nid = ($last === false) ? 0 : $last->getNid();
			if ($nid <= 0 || ($maxId > 0 && $nid >= $maxId)) {
				// a row that cannot be paged on: stop rather than ask for the
				// same page for ever
				return;
			}

			$maxId = $nid;
		}
	}

	/**
	 * The year in seconds since the epoch, in UTC.
	 *
	 * UTC and not the reader's zone, because the months a report draws have to
	 * be the same months whoever opens it and wherever from — and because
	 * every timestamp it is comparing against is stored in UTC.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function bounds(int $year): array {
		return [
			(int)gmmktime(0, 0, 0, 1, 1, $year),
			(int)gmmktime(0, 0, 0, 1, 1, $year + 1),
		];
	}

	/**
	 * @return array<int, array{month: int, statuses: int, followers: int}>
	 */
	private function emptyMonths(): array {
		$months = [];
		for ($month = 1; $month <= 12; $month++) {
			$months[$month] = ['month' => $month, 'statuses' => 0, 'followers' => 0];
		}

		return $months;
	}
}
