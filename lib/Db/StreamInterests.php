<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
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
	 * `only_media`), and '' every post.
	 *
	 * @param string[] $tags normalised
	 * @param string[] $excludeNids the posts the reader hid
	 * @param string[] $languages empty for every language
	 * @param string $media '', 'photos', 'videos' or 'media'
	 *
	 * @return list<array{nid: string, idPrim: string, tag: string, author: string}>
	 */
	public function interestCandidates(
		array $tags, string $sinceNid, int $cap, array $excludeNids = [], array $languages = [], string $media = '',
	): array {
		if ($tags === [] || $this->viewer === null) {
			return [];
		}

		$qb = $this->getStreamNidsSelectSql(true);
		$qb->addSelect('s.id_prim', 's.attributed_to_prim', 'st.hashtag');
		$expr = $qb->expr();

		$qb->limitToStatusTypes();
		$qb->andWhere($expr->gt('s.nid', $qb->createNamedParameter($sinceNid)));
		if ($media === 'photos') {
			$qb->limitToMediaType('image');
		} elseif ($media === 'videos') {
			$qb->limitToVideo();
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
			];
		}
		$cursor->closeCursor();

		return $rows;
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
