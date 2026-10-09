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
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Who is named in which post's pictures.
 *
 * Two reads, and an index for each: the people in one post (or in a page of
 * them, which is the same query over a list), and the posts one person is in.
 *
 * `stream_id` is the post's nid, so it is bound as its decimal string, never
 * as a PHP int: see `Nid`.
 *
 * @package OCA\Social\Db
 */
class MediaTagsRequest extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * How many people may be named in one post.
	 *
	 * A photograph of a party is the case this has to hold; a list longer than
	 * this is not naming people, it is addressing a mailing list, and every
	 * name on it is a notification somebody did not ask for.
	 */
	public const MAX_PER_POST = 20;

	/** The most tag rows one page of `streamsFor()` reads. */
	public const MAX_PAGE = 40;

	/**
	 * Names one account in one post.
	 *
	 * Writing the same pair twice is not an error: a client that sends its
	 * whole list again on every edit would otherwise fail on everything it had
	 * already sent, and the pair is the whole of the fact being recorded.
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

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			return false;
		}

		return true;
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
	 * The accounts named in each of a page of posts.
	 *
	 * One query for the page rather than one per post: a timeline is forty
	 * statuses, and forty queries to put names under them is the kind of thing
	 * that is invisible in development and is the whole page in production.
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
	 * The posts one account is named in, newest first.
	 *
	 * Ids only: which of them the reader may actually see is a question about
	 * posts, and it is answered by the query that reads them.
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
