<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Nid;
use OCA\Social\Tools\SearchTerms;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The word index behind the content search: one row per (word, post) in
 * `social_search_term`.
 *
 * A search used to be `content ILIKE '%term%'`, which no index can answer —
 * a leading wildcard never can — so every search read every post in its
 * window. A word is now an indexed equality on (term, nid), read newest-first
 * off the index and cut at the page; see `StreamRequest::searchContent()`.
 *
 * The rows are written with the post (`StreamRequest::save()`), rewritten
 * with it (`update()`) and deleted with it (`deleteRelatedTo()`), and the
 * posts stored before the table existed are added by `Cron\SearchIndex`.
 * Until that has reached the newest post, the search keeps its bounded scan.
 */
class SearchTermsRequest extends CoreRequestBuilder {
	/** App setting: '1' once every stored post is in the index. */
	public const READY = 'search_index_ready';

	/** App setting: the nid the backfill has reached. */
	public const CURSOR = 'search_index_cursor';

	/** The kinds of status a content search returns, and so the only ones indexed. */
	public const TYPES = [Note::TYPE, Question::TYPE];

	/**
	 * Indexes a post that was just stored.
	 *
	 * Every row is new unless the post is being stored again, which the
	 * unique (term, nid) index makes harmless.
	 *
	 * @throws DBException
	 */
	public function index(Stream $stream): void {
		if (!$this->indexable($stream)) {
			return;
		}

		$prim = $this->getQueryBuilder()->prim($stream->getId());
		$this->insert(
			$prim, (string)$stream->getNid(), SearchTerms::ofHtml($stream->getContent())
		);
	}

	/**
	 * Brings a post's rows in line with what it says now.
	 *
	 * Most updates of a post change a count or a quote and none of its words,
	 * so the stored words are read and only the difference is written: an
	 * update that changed no word writes nothing.
	 *
	 * @throws DBException
	 */
	public function reindex(Stream $stream): void {
		if (!$this->indexable($stream)) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$prim = $qb->prim($stream->getId());
		if ($prim === '') {
			return;
		}

		$stored = [];
		$nid = '';
		$qb->select('term', 'nid')
			->from(self::TABLE_SEARCH_TERMS)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($prim)));
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$stored[(string)$row['term']] = true;
			$nid = (string)$row['nid'];
		}
		$cursor->closeCursor();

		$wanted = SearchTerms::ofHtml($stream->getContent());
		$gone = array_values(array_diff(array_map('strval', array_keys($stored)), $wanted));
		$added = array_values(array_filter($wanted, static fn (string $term): bool => !isset($stored[$term])));

		foreach (array_chunk($gone, self::INSERT_IGNORE_CHUNK) as $chunk) {
			$delete = $this->getQueryBuilder();
			$delete->delete(self::TABLE_SEARCH_TERMS)
				->where($delete->expr()->eq('stream_id_prim', $delete->createNamedParameter($prim)))
				->andWhere($delete->expr()->in(
					'term', $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)
				));
			$delete->executeStatement();
		}

		if ($added === []) {
			return;
		}

		if ($nid === '') {
			// an Update does not carry the nid this instance stored the post
			// under; with no row to copy it from, it is read off the post
			$nid = $this->nidOf($prim, (string)$stream->getNid());
		}
		$this->insert($prim, $nid, $added);
	}

	/**
	 * The posts that hold every one of `$whole`, newest first: the candidates
	 * of one page of a search, before the viewer's visibility is applied.
	 *
	 * The longest word drives — the rarest, as a rule — read off the
	 * (term, nid) index in nid order and cut at `$limit`; each other word is
	 * a point lookup on the same index. With no whole word, the prefix
	 * drives instead, through its head: an equality on (head, nid), in nid
	 * order, with the `LIKE` sifting the words of that head. A head as common
	 * as `the` holds nearly every post, so those reads are bounded by
	 * `$sinceNid`.
	 *
	 * A prefix next to whole words is not asked here; the caller checks it
	 * against the few posts the whole words already narrowed to.
	 *
	 * @param list<string> $whole normalised words, every one required
	 * @param string $prefix a normalised word beginning, '' for none
	 * @param string $authorPrim '' for every author
	 *
	 * @return list<string> nids
	 */
	public function candidateNids(
		array $whole, string $prefix, int|string $maxNid, int|string $minNid, int|string $sinceNid,
		string $authorPrim, int $limit,
	): array {
		if (($whole === [] && $prefix === '') || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->selectDistinct('sx.nid')->from(self::TABLE_SEARCH_TERMS, 'sx');

		if ($whole !== []) {
			usort($whole, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
			$qb->where($expr->eq('sx.term', $qb->createNamedParameter(array_shift($whole))));
			foreach ($whole as $i => $term) {
				$alias = 'sx' . $i;
				$qb->innerJoin('sx', self::TABLE_SEARCH_TERMS, $alias, $expr->andX(
					$expr->eq($alias . '.nid', 'sx.nid'),
					$expr->eq($alias . '.term', $qb->createNamedParameter($term))
				));
			}
		} else {
			$qb->where($expr->eq('sx.head', $qb->createNamedParameter(SearchTerms::head($prefix))));
			$qb->andWhere($expr->like(
				'sx.term', $qb->createNamedParameter($this->dbConnection->escapeLikeParameter($prefix) . '%')
			));
			if (Nid::compare($sinceNid, 0) > 0) {
				$qb->andWhere($expr->gt('sx.nid', $qb->createNamedParameter(Nid::normalize($sinceNid))));
			}
		}

		if (Nid::compare($maxNid, 0) > 0) {
			$qb->andWhere($expr->lt('sx.nid', $qb->createNamedParameter(Nid::normalize($maxNid))));
		}
		if (Nid::compare($minNid, 0) > 0) {
			$qb->andWhere($expr->gt('sx.nid', $qb->createNamedParameter(Nid::normalize($minNid))));
		}

		// the post itself, by its primary key: a status of a kind the search
		// returns, by the author asked for, and still there
		$on = [
			$expr->eq('s.nid', 'sx.nid'),
			$expr->in('s.type', $qb->createNamedParameter(self::TYPES, IQueryBuilder::PARAM_STR_ARRAY)),
		];
		if ($authorPrim !== '') {
			$on[] = $expr->eq('s.attributed_to_prim', $qb->createNamedParameter($authorPrim));
		}
		$qb->innerJoin('sx', self::TABLE_STREAM, 's', $expr->andX(...$on));

		$qb->orderBy('sx.nid', 'desc');
		$qb->setMaxResults($limit);

		$nids = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$nids[] = (string)$row['nid'];
		}
		$cursor->closeCursor();

		return $nids;
	}

	/** Whether every stored post is in the index, so a search may rely on it alone. */
	public function isReady(): bool {
		return $this->configService->getAppValue(self::READY) === '1';
	}

	/**
	 * Indexes the next posts after `$afterNid`, oldest first, and says where
	 * it got to.
	 *
	 * @return array{last: string, read: int} the nid of the last post read
	 *                                        (`$afterNid` when none was), and how many were
	 *
	 * @throws DBException
	 */
	public function backfill(int|string $afterNid, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('nid', 'id_prim', 'content')
			->from(self::TABLE_STREAM)
			->where($qb->expr()->gt('nid', $qb->createNamedParameter(Nid::normalize($afterNid))))
			->andWhere($qb->expr()->in(
				'type', $qb->createNamedParameter(self::TYPES, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->orderBy('nid', 'asc')
			->setMaxResults($limit);

		$last = Nid::normalize($afterNid);
		$read = 0;
		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$last = (string)$row['nid'];
			$read++;
			foreach (SearchTerms::ofHtml((string)$row['content']) as $term) {
				$rows[] = self::row($term, $last, (string)$row['id_prim']);
			}
		}
		$cursor->closeCursor();

		$this->insertIgnoringConflicts(self::TABLE_SEARCH_TERMS, $rows);

		return ['last' => $last, 'read' => $read];
	}

	private function indexable(Stream $stream): bool {
		return $stream instanceof Note && in_array($stream->getType(), self::TYPES, true);
	}

	/**
	 * @param list<string> $terms
	 *
	 * @throws DBException
	 */
	private function insert(string $prim, string $nid, array $terms): void {
		if ($prim === '' || $terms === [] || $nid === '' || $nid === '0') {
			return;
		}

		$this->insertIgnoringConflicts(self::TABLE_SEARCH_TERMS, array_map(
			static fn (string $term): array => self::row($term, $nid, $prim), $terms
		));
	}

	/** @return array{term: string, head: string, nid: string, stream_id_prim: string} */
	private static function row(string $term, string $nid, string $prim): array {
		return ['term' => $term, 'head' => SearchTerms::head($term), 'nid' => $nid, 'stream_id_prim' => $prim];
	}

	/** The nid a post is stored under, or `$known` when it carries one. */
	private function nidOf(string $prim, string $known): string {
		if ($known !== '' && Nid::compare($known, 0) > 0) {
			return $known;
		}

		$qb = $this->getQueryBuilder();
		$qb->select('nid')
			->from(self::TABLE_STREAM)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($prim)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return is_array($row) ? (string)$row['nid'] : '';
	}
}
