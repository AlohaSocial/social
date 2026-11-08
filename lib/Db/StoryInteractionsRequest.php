<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Client\StoryInteraction;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The reactions and replies a story has been answered with.
 *
 * Every one of these rows belongs to a story, and a story lives a day — so
 * there is no sweep of its own here. What deletes a story deletes what was
 * said about it, in the same call, which is why `deleteByStory()` and
 * `deleteByStories()` exist rather than an expiry column.
 *
 * @package OCA\Social\Db
 */
class StoryInteractionsRequest extends CoreRequestBuilder {
	use TArrayTools;

	/** How many answers to one story are read at once. */
	public const PAGE = 100;

	private function getInteractionsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select(
			'sr.id', 'sr.story_id', 'sr.actor_id', 'sr.actor_id_prim',
			'sr.type', 'sr.content', 'sr.source_id', 'sr.creation'
		)
			->from(self::TABLE_STORY_REACTS, 'sr');

		$this->defaultSelectAlias = 'sr';
		$qb->setDefaultSelectAlias('sr');

		return $qb;
	}

	/** @return StoryInteraction[] */
	private function getInteractionsFromRequest(SocialQueryBuilder $qb): array {
		$interactions = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$interaction = new StoryInteraction();
			$interaction->importFromDatabase($data);
			$interactions[] = $interaction;
		}
		$cursor->closeCursor();

		return $interactions;
	}

	/**
	 * Writes one, or does nothing if that exact activity is already here.
	 *
	 * An inbox is retried, so the same reaction arrives more than once; a
	 * unique index on the activity's own id is what makes the second delivery
	 * nothing rather than a second reaction. The violation is caught rather
	 * than avoided with a read first, because between the read and the write
	 * is exactly where the second delivery lands.
	 *
	 * @return bool whether this was the first time it was seen
	 */
	public function save(StoryInteraction $interaction): bool {
		$now = new DateTime('@' . (
			($interaction->getCreation() > 0) ? $interaction->getCreation() : time()
		));

		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STORY_REACTS)
			->setValue('story_id', $qb->createNamedParameter($interaction->getStoryId(), IQueryBuilder::PARAM_INT))
			->setValue('actor_id', $qb->createNamedParameter($interaction->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($interaction->getActorId())))
			->setValue('type', $qb->createNamedParameter($interaction->getType()))
			->setValue('content', $qb->createNamedParameter($interaction->getContent()))
			->setValue('source_id', $qb->createNamedParameter($interaction->getSourceId()))
			->setValue('source_id_prim', $qb->createNamedParameter(md5($interaction->getSourceId())))
			->setValue('creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE));

		if (!$this->insertUnlessDuplicate($qb)) {
			return false;
		}

		$interaction->setId($qb->getLastInsertId())
			->setCreation($now->getTimestamp());

		return true;
	}

	/**
	 * Everything said about one story, oldest first.
	 *
	 * @return StoryInteraction[]
	 */
	public function forStory(int $storyId, int $limit = self::PAGE): array {
		$qb = $this->getInteractionsSelectSql();
		$qb->andWhere($qb->expr()->eq('sr.story_id', $qb->createNamedParameter($storyId, IQueryBuilder::PARAM_INT)));
		$qb->orderBy('sr.id', 'asc');
		$qb->setMaxResults(max(1, min($limit, self::PAGE)));

		return $this->getInteractionsFromRequest($qb);
	}

	/** How many times one account has already answered one story. */
	public function countByActor(int $storyId, string $actorId): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STORY_REACTS)
			->where($qb->expr()->eq('story_id', $qb->createNamedParameter($storyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0);
	}

	/** What was said about one story goes when the story does. */
	public function deleteByStory(int $storyId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STORY_REACTS)
			->where($qb->expr()->eq('story_id', $qb->createNamedParameter($storyId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * The same, for the set of stories a sweep or a suspension takes at once.
	 *
	 * @param int[] $storyIds
	 */
	public function deleteByStories(array $storyIds): void {
		if ($storyIds === []) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STORY_REACTS)
			->where($qb->expr()->in('story_id', $qb->createNamedParameter($storyIds, IQueryBuilder::PARAM_INT_ARRAY)));

		$qb->executeStatement();
	}

	/** Everything one account has said about anybody's story. */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STORY_REACTS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}
}
