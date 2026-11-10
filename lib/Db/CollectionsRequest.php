<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Collection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The albums an account curates out of its own posts.
 *
 * Every route that changes a collection reads it with the owner as a SQL
 * predicate rather than reading the row and comparing afterwards -- the same
 * shape `ListsRequest` uses, and for the same reason: a check that is part of
 * the query cannot be forgotten by a later caller.
 *
 * @package OCA\Social\Db
 */
class CollectionsRequest extends CollectionsRequestBuilder {
	/** How many collections one account may keep. */
	public const MAX_PER_ACTOR = 200;
	/** How many posts one collection may hold, which is Pixelfed's own ceiling. */
	public const MAX_ITEMS = 100;

	public function save(Collection $collection): Collection {
		$now = new DateTime('now');

		$qb = $this->getCollectionsInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($collection->getOwnerId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($collection->getOwnerId())))
			->setValue('title', $qb->createNamedParameter($collection->getTitle()))
			->setValue('description', $qb->createNamedParameter($collection->getDescription()))
			->setValue('visibility', $qb->createNamedParameter($collection->getVisibility()))
			->setValue('creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->setValue('updated', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();

		$collection->setId($qb->getLastInsertId());
		$collection->setCreation($now->getTimestamp());
		$collection->setUpdated($now->getTimestamp());

		return $collection;
	}

	/**
	 * One collection of one owner.
	 *
	 * @throws ItemNotFoundException when the id is unknown or belongs elsewhere
	 */
	public function getOwnedById(string $actorId, int $id): Collection {
		$qb = $this->getCollectionsSelectSql();
		$qb->andWhere($qb->expr()->eq('c.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$collections = $this->getCollectionsFromRequest($qb);
		if (count($collections) !== 1) {
			throw new ItemNotFoundException('unknown collection');
		}

		return $this->withSize($collections[0]);
	}

	/**
	 * One collection by id, whoever owns it, for a reader that may not be the
	 * owner. The caller applies the visibility.
	 *
	 * @throws ItemNotFoundException
	 */
	public function getById(int $id): Collection {
		$qb = $this->getCollectionsSelectSql();
		$qb->andWhere($qb->expr()->eq('c.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$collections = $this->getCollectionsFromRequest($qb);
		if (count($collections) !== 1) {
			throw new ItemNotFoundException('unknown collection');
		}

		return $this->withSize($collections[0]);
	}

	/**
	 * Every collection of one account, newest first.
	 *
	 * @return Collection[]
	 */
	public function getByActor(string $actorId, bool $publicOnly = false): array {
		$qb = $this->getCollectionsSelectSql();
		$qb->andWhere($qb->expr()->eq('c.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		if ($publicOnly) {
			$qb->andWhere(
				$qb->expr()->eq('c.visibility', $qb->createNamedParameter(Collection::VISIBILITY_PUBLIC))
			);
		}
		$qb->orderBy('c.id', 'desc');
		$qb->setMaxResults(self::MAX_PER_ACTOR);

		return array_map(fn (Collection $c): Collection => $this->withSize($c), $this->getCollectionsFromRequest($qb));
	}

	public function update(Collection $collection): void {
		$qb = $this->getCollectionsUpdateSql();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($collection->getOwnerId())))
			);

		$qb->set('title', $qb->createNamedParameter($collection->getTitle()))
			->set('description', $qb->createNamedParameter($collection->getDescription()))
			->set('visibility', $qb->createNamedParameter($collection->getVisibility()))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
	}

	/** Removes the collection and everything in it. */
	public function delete(Collection $collection): void {
		$qb = $this->getCollectionItemsDeleteSql();
		$qb->where(
			$qb->expr()->eq('collection_id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT))
		);
		$qb->executeStatement();

		$qb = $this->getCollectionsDeleteSql();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($collection->getOwnerId())))
			);
		$qb->executeStatement();
	}

	/**
	 * Everything an account owns, for a suspension or a deletion.
	 *
	 * Named as every other side table names it, so the fan-out in
	 * `ModerationService` and `PersonInterface` reads the same for all of them.
	 */
	public function deleteRelatedId(string $actorId): void {
		foreach ($this->getByActor($actorId) as $collection) {
			$this->delete($collection);
		}
	}

	/**
	 * Adds a post, at the end unless a position is given.
	 *
	 * A post that is already in the collection is left where it is: the unique
	 * index refuses the second row and that is the whole of the handling, which
	 * is what makes the route safe to retry.
	 */
	public function addItem(Collection $collection, string $streamId, int $position = -1): void {
		if ($position < 0) {
			$position = $this->nextPosition($collection);
		}

		$qb = $this->getCollectionItemsInsertSql();
		$qb->setValue('collection_id', $qb->createNamedParameter($collection->getId()))
			->setValue('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId)))
			->setValue('position', $qb->createNamedParameter($position))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$this->insertUnlessDuplicate($qb);
	}

	public function removeItem(Collection $collection, string $streamId): void {
		$qb = $this->getCollectionItemsDeleteSql();
		$qb->where(
			$qb->expr()->eq('collection_id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT))
		)
			->andWhere($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))));

		$qb->executeStatement();
	}

	/**
	 * Empties a collection.
	 *
	 * For the one caller that rebuilds rather than edits: a playlist that
	 * arrived from somewhere else is somebody else's document, and the document
	 * is the whole truth about it — a video taken out there has to go from here
	 * too, which a merge cannot express.
	 */
	public function clearItems(Collection $collection): void {
		$qb = $this->getCollectionItemsDeleteSql();
		$qb->where(
			$qb->expr()->eq('collection_id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT))
		);

		$qb->executeStatement();
	}

	/** Takes a post out of every collection it is in, when the post is deleted. */
	public function removeStream(string $streamId): void {
		$qb = $this->getCollectionItemsDeleteSql();
		$qb->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))));

		$qb->executeStatement();
	}

	/**
	 * The prims of the posts in a collection, in the owner's order.
	 *
	 * Ordered by `position` and then by `id`, so that items sharing a position
	 * -- which nothing writes, but which a hand-edited row could produce --
	 * still come back in a stable order rather than whatever the database
	 * happens to return.
	 *
	 * @return string[]
	 */
	public function getItemPrims(Collection $collection, int $limit = self::MAX_ITEMS, int $offset = 0): array {
		$qb = $this->getCollectionItemsSelectSql();
		$qb->andWhere(
			$qb->expr()->eq('ci.collection_id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT))
		);
		$qb->orderBy('ci.position', 'asc');
		$qb->addOrderBy('ci.id', 'asc');
		$qb->setMaxResults(max(1, min($limit, self::MAX_ITEMS)));
		if ($offset > 0) {
			$qb->setFirstResult($offset);
		}

		$prims = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$prim = (string)($data['stream_id_prim'] ?? '');
			if ($prim !== '') {
				$prims[] = $prim;
			}
		}
		$cursor->closeCursor();

		return $prims;
	}

	/**
	 * The posts of a collection, in the owner's order.
	 *
	 * Two queries, as every timeline here is: the first decides which posts are
	 * on the page and in what order, the second reads them with their author
	 * and the viewer's own actions on them. The order is the collection's, not
	 * the stream's, so it is re-applied to the rows after they come back --
	 * `WHERE id_prim IN (...)` does not preserve the order of the list.
	 *
	 * @return Stream[]
	 */
	public function getItems(Collection $collection, int $limit = self::MAX_ITEMS, int $offset = 0): array {
		$prims = $this->getItemPrims($collection, $limit, $offset);
		if ($prims === []) {
			return [];
		}

		$qb = $this->getStreamSelectSql();
		$qb->andWhere(
			$qb->expr()->in('s.id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
		);
		// A collection says which posts its owner grouped, not who may read
		// them: a followers-only post put in a public album was served to
		// anybody who opened it, and the two routes that read this are
		// unauthenticated. The same predicate every other read of a post
		// applies — with no viewer it answers with the public ones, which is
		// what a visitor should see.
		$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction('sa');
		$qb->leftJoinObjectStatus();

		$byPrim = [];
		foreach ($this->getStreamsFromRequest($qb) as $stream) {
			$byPrim[md5($stream->getId())] = $stream;
		}

		// back into the owner's order, and silently past any post that has since
		// been deleted or that the predicate above kept out
		$ordered = [];
		foreach ($prims as $prim) {
			if (array_key_exists($prim, $byPrim)) {
				$ordered[] = $byPrim[$prim];
			}
		}

		return $ordered;
	}

	public function countItems(Collection $collection): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'size')
			->from(self::TABLE_COLLECTION_ITEMS, 'ci')
			->where($qb->expr()->eq('ci.collection_id', $qb->createNamedParameter($collection->getId())));

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['size'] ?? 0);
	}

	/** Whether the post is already in the collection. */
	public function hasItem(Collection $collection, string $streamId): bool {
		$qb = $this->getCollectionItemsSelectSql();
		$qb->andWhere(
			$qb->expr()->eq('ci.collection_id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT))
		)
			->andWhere($qb->expr()->eq('ci.stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))));
		$qb->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $data !== false;
	}

	private function nextPosition(Collection $collection): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->max('ci.position'), 'top')
			->from(self::TABLE_COLLECTION_ITEMS, 'ci')
			->where($qb->expr()->eq('ci.collection_id', $qb->createNamedParameter($collection->getId())));

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['top'] ?? 0) + 1;
	}

	private function withSize(Collection $collection): Collection {
		return $collection->setSize($this->countItems($collection));
	}
}
