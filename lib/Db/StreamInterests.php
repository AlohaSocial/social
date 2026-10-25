<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\RecommendationFreshness;
use OCA\Social\Tools\Nid;
use OCA\Social\Tools\SearchTerms;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * What For you asks of the stream table.
 *
 * Every read here is as the viewer and through the same visibility filter the
 * timelines use, including the reader's blocks and mutes: a post the reader
 * could not open is neither something they can learn from nor something their
 * feed may show. That matters twice over for learning, because the interests
 * it produces are shown back to the reader — a signal naming a followers-only
 * post of a stranger would otherwise put that post's hashtags on their
 * settings page.
 */
trait StreamInterests {
	/** Maximum bounded newest-public scan used while the word index is backfilling. */
	private const RECOMMENDATION_FALLBACK_SCAN = 500;
	/** Maximum word-index results examined for one topic lookup. */
	private const RECOMMENDATION_INDEX_CANDIDATES = 300;
	/** Maximum alias lookups for a multi-topic For You page request. */
	private const RECOMMENDATION_MAX_TERMS = 20;

	/**
	 * The posts among these the viewer may see, each once.
	 *
	 * @param string[] $nids
	 *
	 * @return array<string, Stream> by nid
	 */
	public function getVisibleByNids(array $nids): array {
		$nids = array_values(array_unique(array_filter(array_map('strval', $nids), 'ctype_digit')));
		if ($nids === []) {
			return [];
		}

		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		$qb->leftJoinStreamAction('sa');

		$streams = [];
		foreach ($this->getStreamsFromRequest($qb) as $stream) {
			$streams[(string)$stream->getNid()] = $stream;
		}

		return $streams;
	}

	/**
	 * The posts that could go into the feed: newer than `$sinceNid`, carrying
	 * one of `$tags`, and neither the viewer's own nor from somebody silenced.
	 *
	 * One row per post and matching tag, newest first, at most `$cap` of them.
	 * The tags are compared as they stand, as the hashtag timeline compares
	 * them: `social_stream_tag` holds them normalised
	 * (`FollowedTagsRequest::normalise()`), and a comparison over `LOWER()` of
	 * the column is one its index cannot answer.
	 *
	 * `$media` narrows them to one kind, on the indexed `media_kind` column the
	 * Photos and Videos timelines read: `photos` is a post with a picture in
	 * it, `videos` one that is a video, `media` either (Mastodon's
	 * `only_media`), and '' every post. `shorts` is a short video, on
	 * `short_kind`, the column `only_short` reads.
	 *
	 * @param string[] $tags normalised
	 * @param string[] $excludeNids the posts the reader hid
	 * @param string[] $languages empty for every language
	 * @param string $media '', 'photos', 'videos', 'shorts' or 'media'
	 *
	 * @return list<array{nid: string, idPrim: string, tag: string, author: string, updated: string}>
	 */
	public function interestCandidates(
		array $tags, string $sinceNid, int $cap, array $excludeNids = [], array $languages = [], string $media = '',
	): array {
		if ($tags === [] || $this->viewer === null) {
			return [];
		}

		$qb = $this->getStreamNidsSelectSql(true);
		$qb->addSelect('s.id_prim', 's.attributed_to_prim', 's.updated', 'st.hashtag');
		$expr = $qb->expr();

		$qb->limitToStatusTypes();
		$qb->andWhere($expr->gt('s.nid', $qb->createNamedParameter($sinceNid)));
		if ($media === 'photos') {
			$qb->limitToMediaType('image');
		} elseif ($media === 'videos') {
			$qb->limitToVideo();
		} elseif ($media === 'shorts') {
			$qb->limitToShort();
		} elseif ($media === 'media') {
			$qb->limitToMedia();
		}

		$qb->joinCacheActors('ca', 's.attributed_to_prim');
		$qb->linkToStreamTags('st', 's.id_prim');
		$qb->andWhere($expr->in(
			'st.hashtag',
			$qb->createNamedParameter(array_values($tags), IQueryBuilder::PARAM_STR_ARRAY)
		));

		// `to`: the feed is a tag timeline, and an unlisted post stays off those
		$qb->limitToViewer('sd', 'f', true, false, SocialCoreQueryBuilder::HIDDEN_TIMELINE, 'to');
		$qb->andWhere($expr->neq(
			's.attributed_to_prim', $qb->createNamedParameter($qb->prim($this->viewer->getId()))
		));
		// the feed is part of the public square a silenced account loses
		$this->filterSilencedActors($qb);

		if ($excludeNids !== []) {
			$qb->andWhere($expr->notIn(
				's.nid', $qb->createNamedParameter(array_values($excludeNids), IQueryBuilder::PARAM_STR_ARRAY)
			));
		}

		if ($languages !== []) {
			// a post that does not say what language it is in is not one the
			// reader said they cannot read
			$qb->andWhere($expr->orX(
				$expr->in('s.language', $qb->createNamedParameter(array_values($languages), IQueryBuilder::PARAM_STR_ARRAY)),
				$expr->eq('s.language', $qb->createNamedParameter('')),
				$expr->isNull('s.language')
			));
		}

		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults(max(1, $cap));

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$rows[] = [
				'nid' => (string)$row['nid'],
				'idPrim' => (string)$row['id_prim'],
				'tag' => mb_strtolower((string)$row['hashtag'], 'UTF-8'),
				'author' => (string)$row['attributed_to_prim'],
				'updated' => is_string($row['updated'] ?? null) ? (string)$row['updated'] : '',
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * A bounded set of posts whose indexed words may describe a topic. The
	 * returned rows are filtered in SQL for current viewer access, public
	 * discoverability, hidden actors, duplicates, language and age before the
	 * caller extracts topic features from their content.
	 *
	 * When the search word index is still backfilling, scan only the newest
	 * eligible public window rather than pretending older posts have been
	 * searched or querying the whole stream table.
	 *
	 * @param list<string> $terms topic aliases to search
	 * @param list<string> $excludeNids posts hidden by the reader or already selected
	 * @param list<string> $languages empty means any language
	 * @return array<string, Stream> visible, public statuses keyed by nid
	 */
	public function recommendationCandidatesByTerms(
		array $terms,
		string $sinceNid,
		int $limit,
		array $excludeNids = [],
		array $languages = [],
		?int $now = null,
	): array {
		if ($this->viewer === null || $terms === [] || $limit < 1) {
			return [];
		}

		$sinceNid = Nid::normalize($sinceNid);
		$limit = min(100, $limit);
		if ($now === null || $now <= 0) {
			return $this->recommendationCandidatesInRange($terms, $sinceNid, '', $limit, $excludeNids, $languages);
		}

		$recentSinceNid = Nid::fromPublishedTime(
			max(0, $now - RecommendationFreshness::RECENT_WINDOW_DAYS * 86400), 0, StreamRequest::NID_LIMIT
		);
		$recentLimit = RecommendationFreshness::recentCandidateLimit($limit);
		$historicalLimit = RecommendationFreshness::historicalCandidateLimit($limit);
		$recent = $this->recommendationCandidatesInRange($terms, $recentSinceNid, '', $recentLimit, $excludeNids, $languages);
		if ($historicalLimit === 0) {
			return $recent;
		}

		$excluded = array_values(array_unique(array_merge($excludeNids, array_keys($recent))));
		$historical = $this->recommendationCandidatesInRange($terms, $sinceNid, $recentSinceNid, $historicalLimit, $excluded, $languages);
		$missingHistorical = $historicalLimit - count($historical);
		$unusedCapacity = $limit - count($recent) - count($historical);
		$missingRecent = $recentLimit - count($recent);
		$refillLimit = min($unusedCapacity, $missingHistorical + $missingRecent);
		if ($refillLimit > 0) {
			$refill = $this->recommendationCandidatesInRange(
				$terms,
				$recentSinceNid,
				'',
				$refillLimit,
				array_values(array_unique(array_merge($excluded, array_keys($historical)))),
				$languages
			);
			$recent += $refill;
		}

		return $recent + $historical;
	}

	/**
	 * Public, visible posts by accounts the viewer currently follows. This
	 * recall source is independent of the word index: followed authors can
	 * appear even when their post contains none of the selected topic aliases.
	 * Topic and hard-hide preferences are still applied by the ranker.
	 *
	 * @param list<string> $excludeNids posts already selected or hidden
	 * @param list<string> $languages empty means any language
	 * @return array<string, Stream> visible public statuses keyed by nid
	 */
	public function recommendationFollowCandidates(string $sinceNid, int $limit, array $excludeNids = [], array $languages = []): array {
		if ($this->viewer === null || $limit < 1) {
			return [];
		}

		$qb = $this->getStreamNidsSelectSql(true);
		$expr = $qb->expr();
		$qb->innerJoin('s', self::TABLE_FOLLOWS, 'rec_follow', $expr->andX(
			$expr->eq('rec_follow.object_id_prim', 's.attributed_to_prim'),
			$expr->eq('rec_follow.actor_id_prim', $qb->createNamedParameter($qb->prim($this->viewer->getId()))),
			$expr->eq('rec_follow.type', $qb->createNamedParameter(Follow::TYPE)),
			$expr->eq('rec_follow.accepted', $qb->createNamedParameter('1'))
		));
		$qb->limitToStatusTypes();
		$qb->andWhere($expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)));
		$qb->andWhere($expr->gt('s.nid', $qb->createNamedParameter(Nid::normalize($sinceNid))));
		$qb->limitToViewer('sd', 'f', true, false, SocialCoreQueryBuilder::HIDDEN_TIMELINE, 'to');
		$qb->filterDuplicate();
		$this->filterSilencedActors($qb);
		$qb->andWhere($expr->neq(
			's.attributed_to_prim', $qb->createNamedParameter($qb->prim($this->viewer->getId()))
		));
		if ($excludeNids !== []) {
			$qb->andWhere($expr->notIn(
				's.nid', $qb->createNamedParameter($excludeNids, IQueryBuilder::PARAM_STR_ARRAY)
			));
		}
		if ($languages !== []) {
			$qb->andWhere($expr->orX(
				$expr->in('s.language', $qb->createNamedParameter($languages, IQueryBuilder::PARAM_STR_ARRAY)),
				$expr->eq('s.language', $qb->createNamedParameter('')),
				$expr->isNull('s.language')
			));
		}
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults(min(100, $limit));

		$nids = $this->getNidsFromRequest($qb);
		$visible = $this->getVisibleByNids($nids);
		$ordered = [];
		foreach ($nids as $nid) {
			if (isset($visible[$nid])) {
				$ordered[$nid] = $visible[$nid];
			}
		}

		return $ordered;
	}

	/**
	 * Bounded public posts by accounts followed by the viewer's accepted follows.
	 * Both edges must be accepted ActivityPub follows; cached post visibility is
	 * checked again for the viewer before any candidate is returned.
	 *
	 * @param list<string> $excludeNids posts already selected or hidden
	 * @param list<string> $languages empty means any language
	 * @return array<string, Stream> visible public statuses keyed by nid
	 */
	public function recommendationSocialGraphCandidates(string $sinceNid, int $limit, array $excludeNids = [], array $languages = []): array {
		if ($this->viewer === null || $limit < 1) {
			return [];
		}

		$qb = $this->getStreamNidsSelectSql(true);
		$expr = $qb->expr();
		$viewerPrim = $qb->prim($this->viewer->getId());
		$qb->innerJoin('s', self::TABLE_FOLLOWS, 'rec_graph_author', $expr->andX(
			$expr->eq('rec_graph_author.object_id_prim', 's.attributed_to_prim'),
			$expr->eq('rec_graph_author.type', $qb->createNamedParameter(Follow::TYPE)),
			$expr->eq('rec_graph_author.accepted', $qb->createNamedParameter('1'))
		));
		$qb->innerJoin('rec_graph_author', self::TABLE_FOLLOWS, 'rec_graph_viewer', $expr->andX(
			$expr->eq('rec_graph_viewer.object_id_prim', 'rec_graph_author.actor_id_prim'),
			$expr->eq('rec_graph_viewer.actor_id_prim', $qb->createNamedParameter($viewerPrim)),
			$expr->eq('rec_graph_viewer.type', $qb->createNamedParameter(Follow::TYPE)),
			$expr->eq('rec_graph_viewer.accepted', $qb->createNamedParameter('1'))
		));
		$qb->limitToStatusTypes();
		$qb->andWhere($expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)));
		$qb->andWhere($expr->gt('s.nid', $qb->createNamedParameter(Nid::normalize($sinceNid))));
		$qb->limitToViewer('sd', 'f', true, false, SocialCoreQueryBuilder::HIDDEN_TIMELINE, 'to');
		$qb->filterDuplicate();
		$this->filterSilencedActors($qb);
		$qb->andWhere($expr->neq('s.attributed_to_prim', $qb->createNamedParameter($viewerPrim)));
		if ($excludeNids !== []) {
			$qb->andWhere($expr->notIn('s.nid', $qb->createNamedParameter($excludeNids, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		if ($languages !== []) {
			$qb->andWhere($expr->orX(
				$expr->in('s.language', $qb->createNamedParameter($languages, IQueryBuilder::PARAM_STR_ARRAY)),
				$expr->eq('s.language', $qb->createNamedParameter('')),
				$expr->isNull('s.language')
			));
		}
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults(min(100, $limit));

		$nids = $this->getNidsFromRequest($qb);
		$visible = $this->getVisibleByNids($nids);
		$ordered = [];
		foreach ($nids as $nid) {
			if (isset($visible[$nid])) {
				$ordered[$nid] = $visible[$nid];
			}
		}

		return $ordered;
	}

	/**
	 * A small deterministic sample from the recent public corpus. This gives
	 * the local classifier a chance to find posts missed by topic aliases or a
	 * lagging search index without scanning more than the bounded newest window.
	 *
	 * @param list<string> $excludeNids posts already selected or hidden
	 * @param list<string> $languages empty means any language
	 * @return array<string, Stream> visible public statuses keyed by nid
	 */
	public function recommendationExplorationCandidates(
		string $sinceNid,
		int $limit,
		int $rotation,
		array $excludeNids = [],
		array $languages = [],
	): array {
		if ($this->viewer === null || $limit < 1) {
			return [];
		}

		$qb = $this->getStreamNidsSelectSql(true);
		$expr = $qb->expr();
		$qb->limitToStatusTypes();
		$qb->andWhere($expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)));
		$qb->andWhere($expr->gt('s.nid', $qb->createNamedParameter(Nid::normalize($sinceNid))));
		$qb->limitToViewer('sd', 'f', true, false, SocialCoreQueryBuilder::HIDDEN_TIMELINE, 'to');
		$qb->filterDuplicate();
		$this->filterSilencedActors($qb);
		$qb->andWhere($expr->neq(
			's.attributed_to_prim', $qb->createNamedParameter($qb->prim($this->viewer->getId()))
		));
		if ($excludeNids !== []) {
			$qb->andWhere($expr->notIn(
				's.nid', $qb->createNamedParameter($excludeNids, IQueryBuilder::PARAM_STR_ARRAY)
			));
		}
		if ($languages !== []) {
			$qb->andWhere($expr->orX(
				$expr->in('s.language', $qb->createNamedParameter($languages, IQueryBuilder::PARAM_STR_ARRAY)),
				$expr->eq('s.language', $qb->createNamedParameter('')),
				$expr->isNull('s.language')
			));
		}
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults(self::RECOMMENDATION_FALLBACK_SCAN);
		$nids = $this->getNidsFromRequest($qb);
		usort($nids, fn (string $left, string $right): int => strcmp(
			hash('sha256', $this->viewer->getId() . ':' . $rotation . ':' . $left),
			hash('sha256', $this->viewer->getId() . ':' . $rotation . ':' . $right)
		));
		$nids = array_slice($nids, 0, min(100, $limit));
		$visible = $this->getVisibleByNids($nids);
		$ordered = [];
		foreach ($nids as $nid) {
			if (isset($visible[$nid])) {
				$ordered[$nid] = $visible[$nid];
			}
		}

		return $ordered;
	}

	/** @param list<string> $terms @param list<string> $excludeNids @param list<string> $languages @return array<string, Stream> */
	private function recommendationCandidatesInRange(array $terms, string $sinceNid, string $beforeNid, int $limit, array $excludeNids, array $languages): array {
		$sinceNid = Nid::normalize($sinceNid);
		$beforeNid = $beforeNid === '' ? '' : Nid::normalize($beforeNid);
		$wantedNids = [];
		if ($this->searchTermsRequest->isReady()) {
			foreach (array_slice(array_values(array_unique($terms)), 0, self::RECOMMENDATION_MAX_TERMS) as $term) {
				$whole = SearchTerms::ofText($term, 12, false);
				if ($whole === []) {
					continue;
				}
				foreach ($this->searchTermsRequest->candidateNids(
					$whole, '', $beforeNid === '' ? 0 : $beforeNid, 0, $sinceNid, '', self::RECOMMENDATION_INDEX_CANDIDATES
				) as $nid) {
					$wantedNids[$nid] = true;
					if (count($wantedNids) >= self::RECOMMENDATION_INDEX_CANDIDATES) {
						break 2;
					}
				}
			}
		}

		$qb = $this->getStreamNidsSelectSql(true);
		$qb->limitToStatusTypes();
		$expr = $qb->expr();
		$qb->andWhere($expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)));
		$qb->andWhere($expr->gt('s.nid', $qb->createNamedParameter($sinceNid)));
		if ($beforeNid !== '') {
			$qb->andWhere($expr->lt('s.nid', $qb->createNamedParameter($beforeNid)));
		}
		$qb->limitToViewer('sd', 'f', true, false, SocialCoreQueryBuilder::HIDDEN_TIMELINE, 'to');
		$qb->filterDuplicate();
		$this->filterSilencedActors($qb);
		$qb->andWhere($expr->neq(
			's.attributed_to_prim', $qb->createNamedParameter($qb->prim($this->viewer->getId()))
		));
		if ($wantedNids !== []) {
			$qb->andWhere($expr->in(
				's.nid', $qb->createNamedParameter(array_keys($wantedNids), IQueryBuilder::PARAM_STR_ARRAY)
			));
		} elseif ($this->searchTermsRequest->isReady()) {
			return [];
		}
		if ($excludeNids !== []) {
			$qb->andWhere($expr->notIn(
				's.nid', $qb->createNamedParameter($excludeNids, IQueryBuilder::PARAM_STR_ARRAY)
			));
		}
		if ($languages !== []) {
			$qb->andWhere($expr->orX(
				$expr->in('s.language', $qb->createNamedParameter($languages, IQueryBuilder::PARAM_STR_ARRAY)),
				$expr->eq('s.language', $qb->createNamedParameter('')),
				$expr->isNull('s.language')
			));
		}
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults($this->searchTermsRequest->isReady() ? $limit : self::RECOMMENDATION_FALLBACK_SCAN);

		$nids = $this->getNidsFromRequest($qb);
		$visible = $this->getVisibleByNids($nids);
		$ordered = [];
		foreach ($nids as $nid) {
			if (isset($visible[$nid])) {
				$ordered[$nid] = $visible[$nid];
			}
		}

		return $ordered;
	}

	/**
	 * Every hashtag each of these posts carries, lowered.
	 *
	 * @param string[] $idPrims
	 *
	 * @return array<string, string[]> id_prim => its tags
	 */
	public function hashtagsOfStreams(array $idPrims): array {
		$idPrims = array_values(array_unique($idPrims));
		if ($idPrims === []) {
			return [];
		}

		$tags = [];
		foreach (array_chunk($idPrims, 500) as $chunk) {
			$qb = $this->getQueryBuilder();
			$qb->select('stream_id', 'hashtag')
				->from(self::TABLE_STREAM_TAGS)
				->where($qb->expr()->in('stream_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));

			$cursor = $qb->executeQuery();
			while ($row = $cursor->fetch()) {
				$tags[(string)$row['stream_id']][] = mb_strtolower((string)$row['hashtag'], 'UTF-8');
			}
			$cursor->closeCursor();
		}

		return $tags;
	}
}
