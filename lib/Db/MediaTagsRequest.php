<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Tools\Nid;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Who is tagged in which post's photos: the people in one post (or a page of
 * posts), and the posts one person is in.
 *
 * `stream_id` is the post's nid, so it is bound as its decimal string, never
 * as a PHP int: see `Nid`.
 *
 * @package OCA\Social\Db
 */
class MediaTagsRequest extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * How many people may be tagged in one post: enough for a group photo,
	 * and a bound on the notifications one post can send.
	 */
	public const MAX_PER_POST = 20;

	/** The most tag rows one page of `streamsFor()` reads. */
	public const MAX_PAGE = 40;

	/**
	 * Tags one account in one post. A repeat is not an error, so a client can
	 * resend its whole list on every edit.
	 *
	 * @return bool whether this was new
	 */
	public function tag(int|string $streamId, string $streamPrim, string $actorId, string $taggerId): bool {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_MEDIA_TAGS)
			->setValue('stream_id', $qb->createNamedParameter(Nid::normalize($streamId), IQueryBuilder::PARAM_STR))
			->setValue('stream_id_prim', $qb->createNamedParameter($streamPrim))
			->setValue('actor_id', $qb->createNamedParameter($actorId))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('tagger_id_prim', $qb->createNamedParameter($qb->prim($taggerId)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		return $this->insertUnlessDuplicate($qb);
	}

	/** Takes one name off one post. */
	public function untag(int|string $streamId, string $actorId): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_MEDIA_TAGS)
			->where($qb->expr()->eq('stream_id', $qb->createNamedParameter(Nid::normalize($streamId), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		return $qb->executeStatement() > 0;
	}

	/**
	 * The accounts tagged in each of a page of posts, in one query.
	 *
	 * @param string[] $streamIds
	 *
	 * @return array<string, string[]> keyed by the post id, actor ids in order
	 */
	public function forStreams(array $streamIds): array {
		if ($streamIds === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('stream_id', 'actor_id')
			->from(self::TABLE_MEDIA_TAGS)
			->where($qb->expr()->in('stream_id', $qb->createNamedParameter($streamIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'asc');

		$tags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$tags[(string)$data['stream_id']][] = (string)$data['actor_id'];
		}
		$cursor->closeCursor();

		return $tags;
	}

	/** Whether one account is named in one post. */
	public function isTagged(int|string $streamId, string $actorId): bool {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_MEDIA_TAGS)
			->where($qb->expr()->eq('stream_id', $qb->createNamedParameter(Nid::normalize($streamId), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0) > 0;
	}

	public function countForStream(int|string $streamId): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_MEDIA_TAGS)
			->where($qb->expr()->eq('stream_id', $qb->createNamedParameter(Nid::normalize($streamId), IQueryBuilder::PARAM_STR)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0);
	}

	/**
	 * The posts one account is tagged in, newest first. Ids only: visibility
	 * is checked by the query that reads the posts.
	 *
	 * @return string[]
	 */
	public function streamsFor(string $actorId, int $limit = 40, int|string $maxId = '0'): array {
		$qb = $this->getQueryBuilder();
		$qb->select('stream_id')
			->from(self::TABLE_MEDIA_TAGS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->orderBy('stream_id', 'desc')
			->setMaxResults(max(1, min($limit, self::MAX_PAGE)));

		// a cursor that is not a nid bounds nothing
		if (ctype_digit((string)$maxId) && Nid::compare($maxId, '0') > 0) {
			$qb->andWhere($qb->expr()->lt('stream_id', $qb->createNamedParameter(Nid::normalize($maxId), IQueryBuilder::PARAM_STR)));
		}

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$ids[] = Nid::normalize((string)$data['stream_id']);
		}
		$cursor->closeCursor();

		return $ids;
	}

	/** Every name on one post, for a deletion. */
	public function deleteByStream(int|string $streamId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_MEDIA_TAGS)
			->where($qb->expr()->eq('stream_id', $qb->createNamedParameter(Nid::normalize($streamId), IQueryBuilder::PARAM_STR)));

		$qb->executeStatement();
	}

	/** Every tag naming one account, for a deletion or a suspension. */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_MEDIA_TAGS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}

	/** The tags one account put on pictures, whoever is named in them. */
	public function deleteByTagger(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_MEDIA_TAGS)
			->where($qb->expr()->eq('tagger_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}
}
