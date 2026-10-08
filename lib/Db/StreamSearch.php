<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tools\Nid;
use OCA\Social\Tools\SearchTerms;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Full-text search over the posts the viewer may see: through the word index
 * once `Cron\SearchIndex` has completed it, and by a bounded scan until then.
 *
 * A trait used by `StreamRequest` for the reason `StreamTimelines` gives: it
 * separates the file, not the object.
 */
trait StreamSearch {
	/**
	 * Full-text search over the statuses the viewer is allowed to see: their
	 * own posts, public/unlisted content, and what is addressed to them.
	 *
	 * Words, looked up in `social_search_term`: every word of the query must
	 * be in the post, and the last one may be the beginning of a word, so a
	 * search typed a letter at a time finds as it goes. Newest first, by nid.
	 *
	 * The index hands over candidates in nid order and the viewer's
	 * visibility decides which are answers, so a page is read in rounds: a
	 * round that the visibility emptied is followed by the candidates below
	 * its last one, up to SEARCH_ROUNDS of them.
	 *
	 * Until `Cron\SearchIndex` has put every stored post in the index the
	 * older posts are not in it, and the search keeps the scan it had before
	 * — see `scanContent()`.
	 *
	 * `$authorId` narrows the answers to one account's posts, still within
	 * what the viewer may see. `$offset` skips that many answers, up to
	 * SEARCH_MAX_OFFSET; `$maxId` and `$minId` bound the status nid, leaving
	 * the newest-first order as it is.
	 *
	 * @return Stream[]
	 */
	public function searchContent(
		string $term, int $limit = 20, int $offset = 0, string $authorId = '',
		int|string $maxId = 0, int|string $minId = 0,
	): array {
		$offset = max(0, $offset);
		if ($limit < 1 || $offset > self::SEARCH_MAX_OFFSET) {
			return [];
		}

		if (!$this->searchTermsRequest->isReady()) {
			return $this->scanContent($term, $limit, $offset, $authorId, $maxId, $minId);
		}

		['whole' => $whole, 'prefix' => $prefix] = SearchTerms::ofQuery($term);
		if ($whole === [] && $prefix === '') {
			return [];
		}

		// a lone prefix is read through its head, and a head as common as
		// `the` is nearly every post: it is bounded the way the scan was. A
		// whole word is not
		$since = ($whole === []) ? $this->searchWindowStart() : 0;
		$sinceNid = ($since > 0) ? Nid::fromPublishedTime($since, 0, self::NID_LIMIT) : '0';
		$authorPrim = ($authorId === '') ? '' : $this->getQueryBuilder()->prim($authorId);
		if ($authorId !== '' && $authorPrim === '') {
			return [];
		}

		$want = $offset + $limit;
		$window = min(self::SEARCH_OVERREAD_MAX, max($want, $limit * self::SEARCH_OVERREAD));
		$answers = [];
		$before = $maxId;
		for ($round = 0; $round < self::SEARCH_ROUNDS; $round++) {
			$nids = $this->searchTermsRequest->candidateNids(
				$whole, ($whole === []) ? $prefix : '', $before, $minId, $sinceNid, $authorPrim, $window
			);
			if ($nids === []) {
				break;
			}

			$answers = array_merge($answers, $this->searchHits($nids, ($whole === []) ? '' : $prefix));
			if (count($answers) >= $want || count($nids) < $window) {
				break;
			}

			$before = $nids[count($nids) - 1];
		}

		return array_slice($answers, $offset, $limit);
	}

	/**
	 * The candidates the viewer may see, newest first, hydrated — and, when
	 * the query ended in a word beginning, only those holding a word that
	 * begins so.
	 *
	 * @param list<string> $nids
	 *
	 * @return Stream[]
	 */
	protected function searchHits(array $nids, string $prefix): array {
		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToStatusTypes();
		$qb->andWhere($qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		$qb->leftJoinStreamAction();
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->orderBy('s.nid', 'desc');

		$hits = $this->getStreamsFromRequest($qb);
		if ($prefix === '') {
			return $hits;
		}

		return array_values(array_filter($hits, static function (Stream $post) use ($prefix): bool {
			foreach (SearchTerms::ofHtml($post->getContent()) as $word) {
				if (str_starts_with($word, $prefix)) {
					return true;
				}
			}

			return false;
		}));
	}

	/**
	 * The search as it was before the word index: a case-insensitive
	 * substring match on the stored markup, bounded to the recent past.
	 *
	 * What a search does while `Cron\SearchIndex` has not yet reached the
	 * newest post, and only then.
	 *
	 * @return Stream[]
	 */
	protected function scanContent(
		string $term, int $limit, int $offset, string $authorId, int|string $maxId, int|string $minId,
	): array {
		if (strlen($term) < 3) {
			return [];
		}

		// candidates, not answers, are what the database is asked for, so the
		// rows an offset skips are read on top of the over-read page
		$window = min(self::SEARCH_OVERREAD_MAX, max($limit, $limit * self::SEARCH_OVERREAD)) + $offset;

		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToStatusTypes();
		$expr = $qb->expr();
		$qb->andWhere($expr->iLike(
			's.content',
			$qb->createNamedParameter('%' . $this->dbConnection->escapeLikeParameter($term) . '%')
		));

		if ($authorId !== '') {
			$qb->andWhere($expr->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($authorId))));
		}
		if (Nid::compare($maxId, 0) > 0) {
			$qb->andWhere($expr->lt('s.nid', $qb->createNamedParameter(Nid::fromStorage($maxId))));
		}
		if (Nid::compare($minId, 0) > 0) {
			$qb->andWhere($expr->gt('s.nid', $qb->createNamedParameter(Nid::fromStorage($minId))));
		}

		$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		$qb->leftJoinStreamAction();
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->orderBy('s.published_time', 'desc');
		$qb->setMaxResults($window);

		// The window is what keeps this from being a full scan. `content
		// ILIKE '%term%'` cannot use an index — a leading wildcard never
		// can — so without a bound the database reads every post the instance
		// has ever stored, joined to seven other tables, on every search. At
		// ten million rows that is a table scan per keystroke, and the rate
		// limit is the only thing between it and the CPU.
		//
		// `published_time` is indexed and is what the result is ordered by, so
		// a range on it is both the narrowing and the ordering. Searching the
		// recent past and saying so is a different promise from searching
		// everything and timing out; the word index, once complete, is what
		// lets `searchContent()` promise more.
		$since = $this->searchWindowStart();
		if ($since > 0) {
			$qb->andWhere($expr->gt(
				's.published_time',
				$qb->createNamedParameter($this->dateTime($since), IQueryBuilder::PARAM_DATE)
			));
		}

		return array_slice($this->whoseTextCarries($this->getStreamsFromRequest($qb), $term), $offset, $limit);
	}

	/**
	 * The posts whose *text* carries the term.
	 *
	 * `content` is stored as markup, so the `LIKE` above matches the markup as
	 * well as the words: `span`, `href`, `class` and `http` each answered with
	 * very nearly every post the instance holds, and a search for any of them
	 * was a page of unrelated posts. The database cannot be asked to ignore
	 * the tags without a column to search, so the rows it offers are
	 * candidates and the flattened text decides which of them are answers.
	 *
	 * @param Stream[] $posts
	 *
	 * @return Stream[]
	 */
	private function whoseTextCarries(array $posts, string $term): array {
		return array_values(array_filter($posts, static function (Stream $post) use ($term): bool {
			$text = html_entity_decode(
				ACore::withoutMarkup($post->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8'
			);

			return mb_stripos($text, $term) !== false;
		}));
	}

	/**
	 * How far back a content search looks, as a timestamp, or 0 for "all of
	 * it".
	 *
	 * An administrator can widen it — an instance with a hundred thousand
	 * posts can afford to search all of them, and one with ten million cannot.
	 * The default is a year, which covers what anybody is actually looking for
	 * and bounds the scan at roughly the instance's yearly output rather than
	 * its whole history.
	 */
	private function searchWindowStart(): int {
		$days = $this->configService->getAppValueInt(ConfigService::SOCIAL_SEARCH_WINDOW_DAYS);

		return ($days > 0) ? time() - ($days * 86400) : 0;
	}

	/** A timestamp as the DateTime the date parameters take. */
	private function dateTime(int $timestamp): DateTime {
		$date = new DateTime();
		$date->setTimestamp($timestamp);

		return $date;
	}
}
