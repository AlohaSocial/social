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
use OCA\Social\Model\Client\Options\ProbeOptions;
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
	 * Full-text search over the statuses the viewer may see: their own posts,
	 * public/unlisted content, and what is addressed to them.
	 *
	 * Every word of the query must be in the post (`social_search_term`), the
	 * last one possibly as a prefix, newest first by nid. Candidates come in
	 * nid order and visibility filters them, so a page is read in up to
	 * SEARCH_ROUNDS rounds. Until `Cron\SearchIndex` has indexed every post,
	 * older posts are found by `scanContent()`.
	 *
	 * `$authorId` narrows to one account's posts. `$offset` skips that many
	 * answers, up to SEARCH_MAX_OFFSET; `$maxId` and `$minId` bound the nid.
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

		// function words are not in the index: beside other words they ask
		// nothing of it, and a query of nothing else is answered by the scan
		$whole = array_values(array_filter($whole, static fn (string $word): bool => !SearchTerms::isStopWord($word)));
		if (SearchTerms::isStopWord($prefix)) {
			$prefix = '';
		}
		if ($whole === [] && $prefix === '') {
			return $this->scanContent($term, $limit, $offset, $authorId, $maxId, $minId);
		}

		// a lone prefix as common as `the` matches nearly every post, so it
		// is bounded like the scan; a whole word is not
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
		$page = $this->getStreamNidsSelectSql();
		$page->limitToStatusTypes();
		$page->andWhere($page->expr()->in('s.nid', $page->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY)));
		$page->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		$page->orderBy('s.nid', 'desc');

		$hits = $this->searchedPosts($this->getNidsFromRequest($page));
		if ($prefix === '') {
			return $hits;
		}

		return array_values(array_filter($hits, static function (Stream $post) use ($prefix): bool {
			foreach (SearchTerms::ofHtml($post->getContent(), true) as $word) {
				if (str_starts_with($word, $prefix)) {
					return true;
				}
			}

			return false;
		}));
	}

	/**
	 * The posts a search decided on, in the order it decided on them.
	 * Visibility is asked over the nid alone and the posts read afterwards by
	 * primary key; as one query, the `DISTINCT` over the follow join sorted
	 * every full row (0.6 s on PostgreSQL for an account following 300).
	 *
	 * @param list<string> $nids
	 *
	 * @return Stream[]
	 */
	private function searchedPosts(array $nids): array {
		if ($nids === []) {
			return [];
		}

		$posts = $this->streamsByNids($nids, (new ProbeOptions())->setFormat(ACore::FORMAT_LOCAL));
		$order = array_flip($nids);
		usort($posts, static fn (Stream $a, Stream $b): int => ($order[(string)$a->getNid()] ?? PHP_INT_MAX) <=> ($order[(string)$b->getNid()] ?? PHP_INT_MAX));

		return $posts;
	}

	/**
	 * A case-insensitive substring match on the stored markup, bounded to the
	 * recent past, used while `Cron\SearchIndex` has not reached the newest post.
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

		// the page over the nid, the posts read afterwards: see searchedPosts()
		$qb = $this->getStreamNidsSelectSql();
		// a `DISTINCT` ordered by a column has to select it
		$qb->addSelect('s.published_time');
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
		$qb->orderBy('s.published_time', 'desc');
		$qb->setMaxResults($window);

		// The window keeps this from being a full scan: `ILIKE '%term%'`
		// cannot use an index. `published_time` is indexed and is the order,
		// so the range both narrows and orders.
		$since = $this->searchWindowStart();
		if ($since > 0) {
			$qb->andWhere($expr->gt(
				's.published_time',
				$qb->createNamedParameter($this->dateTime($since), IQueryBuilder::PARAM_DATE)
			));
		}

		return array_slice($this->whoseTextCarries($this->searchedPosts($this->getNidsFromRequest($qb)), $term), $offset, $limit);
	}

	/**
	 * The posts whose text, not markup, carries the term: the `LIKE` above
	 * also matches tags and attributes (`span`, `href`), so the flattened
	 * text decides.
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
	 * it". Configurable by an administrator; the default is a year, bounding
	 * the scan at about a year's output.
	 */
	private function searchWindowStart(): int {
		$days = $this->configService->getAppValueInt(ConfigService::SOCIAL_SEARCH_WINDOW_DAYS);

		return ($days > 0) ? time() - ($days * 86400) : 0;
	}

	/** A timestamp as the DateTime the date parameters take. */
	private function dateTime(int $timestamp): DateTime {
		$date = new DateTime('@' . $timestamp);

		return $date;
	}
}
