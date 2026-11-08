<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\FeaturedTag;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The hashtags an account pins to its profile.
 *
 * The list itself is public — it is drawn on a profile page, and
 * `GET /api/v1/accounts/:id/featured_tags` serves anybody's — so unlike the
 * lists next door the reads here are not scoped to an owner. The *writes* are:
 * every statement that creates or removes a row carries the owner's prim, so a
 * featured tag belonging to somebody else is a 404 and not a row that was read
 * and then rejected.
 */
class FeaturedTagsRequest extends FeaturedTagsRequestBuilder {
	/** The width of `social_featured_tag.hashtag`, which is `social_stream_tag`'s. */
	public const MAX_HASHTAG_LENGTH = 127;

	/**
	 * A hashtag as this app stores one, from whatever the client sent: no
	 * leading `#`, lowercased, and no wider than the column the posts are
	 * tagged in.
	 *
	 * Returns '' for something that is not a hashtag at all. A tag that did
	 * not fit `social_stream_tag.hashtag` could be featured and would count
	 * zero posts forever, because nothing it matched could have been stored.
	 */
	public static function normaliseHashtag(string $hashtag): string {
		$hashtag = ltrim(trim($hashtag), '#');
		$hashtag = mb_strtolower(mb_substr($hashtag, 0, self::MAX_HASHTAG_LENGTH, 'UTF-8'), 'UTF-8');

		// the character class `SearchService` and the tag routes already treat
		// as a hashtag; anything else is not one and is refused rather than
		// stored as something the user cannot post with
		return (preg_match('/^[\p{L}\p{N}_]+$/u', $hashtag) === 1) ? $hashtag : '';
	}

	/**
	 * Idempotent: featuring a tag that is already featured changes nothing and
	 * is not an error — Mastodon answers with the existing entity, and a
	 * client that lost the answer and retried must not get a failure for a
	 * request that succeeded.
	 */
	public function create(FeaturedTag $tag): FeaturedTag {
		$qb = $this->getFeaturedTagsInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($tag->getOwnerId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($tag->getOwnerId())))
			->setValue('hashtag', $qb->createNamedParameter($tag->getHashtag()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if (!$this->insertUnlessDuplicate($qb)) {
			return $this->getOwnedByHashtag($tag->getOwnerId(), $tag->getHashtag());
		}
		$tag->setId($qb->getLastInsertId());

		return $tag;
	}

	/**
	 * Every tag an account features, oldest first — the order a profile draws
	 * them in.
	 *
	 * Not paged: Mastodon does not page this either, and the instance
	 * advertises a ceiling (`accounts.max_featured_tags`) precisely so that a
	 * client knows the list is short.
	 *
	 * @return FeaturedTag[]
	 */
	public function getByActor(string $actorId): array {
		$qb = $this->getFeaturedTagsSelectSql();
		$qb->andWhere(
			$qb->expr()->eq('ft.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);
		$qb->orderBy('ft.id', 'asc');

		$tags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$tags[] = $this->parseFeaturedTagsSelectSql($data);
		}
		$cursor->closeCursor();

		return $tags;
	}

	public function countByActor(string $actorId): int {
		return count($this->getByActor($actorId));
	}

	/**
	 * @throws ItemNotFoundException a tag that is not there and a tag that is
	 *                               somebody else's are the same answer
	 */
	public function getOwnedById(string $actorId, int $id): FeaturedTag {
		$qb = $this->getFeaturedTagsSelectSql();
		$qb->andWhere($qb->expr()->eq('ft.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ft.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		return $this->firstOrFail($qb);
	}

	/**
	 * @throws ItemNotFoundException
	 */
	public function getOwnedByHashtag(string $actorId, string $hashtag): FeaturedTag {
		$qb = $this->getFeaturedTagsSelectSql();
		$qb->andWhere($qb->expr()->eq('ft.hashtag', $qb->createNamedParameter($hashtag)))
			->andWhere($qb->expr()->eq('ft.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		return $this->firstOrFail($qb);
	}

	/** The owner is part of the statement, so a foreign row is never touched. */
	public function delete(FeaturedTag $tag): void {
		$qb = $this->getFeaturedTagsDeleteSql();
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($tag->getId(), IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($tag->getOwnerId())))
		);

		$qb->executeStatement();
	}

	/**
	 * A featured tag is a thing on a profile, so it has nobody to outlive:
	 * this is what makes the rows go when the account does.
	 */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getFeaturedTagsDeleteSql();
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);

		$qb->executeStatement();
	}

	/**
	 * How often an account has used each of a set of hashtags, and when it
	 * last did.
	 *
	 * One grouped query for the whole profile rather than one per tag: a
	 * profile draws every featured tag at once, and the index this reads
	 * (`social_stream_tag` by hashtag, from
	 * `Version1000Date20260910000001`) answers all of them in one pass.
	 *
	 * Public and unlisted posts only. `statuses_count` is shown to anybody who
	 * opens the profile, and a count that included followers-only posts would
	 * be this instance reporting how much somebody posts privately.
	 *
	 * @param string[] $hashtags
	 *
	 * @return array<string, array{count: int, last: string}> by hashtag
	 */
	public function countUsage(string $actorId, array $hashtags): array {
		if ($hashtags === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$qb->select('st.hashtag')
			->from(self::TABLE_STREAM_TAGS, 'st');
		$qb->innerJoin(
			'st', self::TABLE_STREAM, 's',
			$expr->andX(
				$expr->eq('s.id_prim', 'st.stream_id'),
				$expr->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))),
				$expr->eq('s.type', $qb->createNamedParameter(Note::TYPE))
			)
		);
		$qb->andWhere(
			$expr->in('st.hashtag', $qb->createNamedParameter($hashtags, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$qb->andWhere(
			$expr->in('s.visibility', $qb->createNamedParameter(
				[Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], IQueryBuilder::PARAM_STR_ARRAY
			))
		);

		$qb->selectAlias($qb->createFunction('COUNT(st.hashtag)'), 'uses');
		$qb->selectAlias($qb->createFunction('MAX(s.published)'), 'last_used');
		$qb->groupBy('st.hashtag');

		$usage = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$usage[(string)$data['hashtag']] = [
				'count' => (int)$data['uses'],
				'last' => substr((string)($data['last_used'] ?? ''), 0, 10),
			];
		}
		$cursor->closeCursor();

		return $usage;
	}

	/**
	 * The hashtags an account posts with most, most used first — what
	 * `GET /api/v1/featured_tags/suggestions` offers.
	 *
	 * @return array<string, int> hashtag => how many posts used it
	 */
	public function mostUsed(string $actorId, int $limit): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$qb->select('st.hashtag')
			->from(self::TABLE_STREAM_TAGS, 'st');
		$qb->innerJoin(
			'st', self::TABLE_STREAM, 's',
			$expr->andX(
				$expr->eq('s.id_prim', 'st.stream_id'),
				$expr->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))),
				$expr->eq('s.type', $qb->createNamedParameter(Note::TYPE))
			)
		);
		$qb->andWhere(
			$expr->in('s.visibility', $qb->createNamedParameter(
				[Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], IQueryBuilder::PARAM_STR_ARRAY
			))
		);

		$qb->selectAlias($qb->createFunction('COUNT(st.hashtag)'), 'uses');
		$qb->groupBy('st.hashtag');
		$qb->orderBy('uses', 'desc');
		$qb->addOrderBy('st.hashtag', 'asc');
		$qb->setMaxResults($limit);

		$tags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$tags[(string)$data['hashtag']] = (int)$data['uses'];
		}
		$cursor->closeCursor();

		return $tags;
	}

	/**
	 * @throws ItemNotFoundException
	 */
	private function firstOrFail(SocialQueryBuilder $qb): FeaturedTag {
		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ItemNotFoundException('Record not found');
		}

		return $this->parseFeaturedTagsSelectSql($data);
	}
}
