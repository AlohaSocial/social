<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The instance's own decisions about accounts. Small by nature — a moderator
 * acts rarely — so the readers below are content to fetch the whole set.
 */
class ModerationRequest extends CoreRequestBuilder {
	/**
	 * How long a list of the accounts at one level is kept. It is dropped on
	 * every change; this bounds how long a reader that raced a change can
	 * keep serving the list from before it.
	 */
	private const LEVEL_TTL = 300;

	private ICache $cache;

	public function __construct(
		IDBConnection $connection,
		LoggerInterface $logger,
		IURLGenerator $urlGenerator,
		ConfigService $configService,
		MiscService $miscService,
		ICacheFactory $cacheFactory,
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService);
		$this->cache = $cacheFactory->createDistributed('social.moderation');
	}

	/**
	 * Records the decision about an account, replacing any earlier one. Insert,
	 * then update on conflict: deleting first would leave the account under no
	 * decision if the insert failed.
	 */
	public function save(Moderation $moderation): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_MODERATION)
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($moderation->getActorId())))
			->setValue('actor_id', $qb->createNamedParameter($moderation->getActorId()))
			->setValue('level', $qb->createNamedParameter($moderation->getLevel()))
			->setValue('comment', $qb->createNamedParameter($moderation->getComment()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		try {
			$inserted = $this->insertUnlessDuplicate($qb);
		} catch (DBException $e) {
			$this->logger->error('could not record a moderation decision', ['exception' => $e]);

			throw $e;
		}
		if ($inserted) {
			$this->forgetLevels();

			return;
		}

		$update = $this->getQueryBuilder();
		$update->update(self::TABLE_MODERATION)
			->set('actor_id', $update->createNamedParameter($moderation->getActorId()))
			->set('level', $update->createNamedParameter($moderation->getLevel()))
			->set('comment', $update->createNamedParameter($moderation->getComment()))
			->set('creation', $update->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($update->expr()->eq(
				'actor_id_prim', $update->createNamedParameter($update->prim($moderation->getActorId()))
			));

		$update->executeStatement();
		$this->forgetLevels();
	}

	public function delete(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_MODERATION)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
		$this->forgetLevels();
	}

	/**
	 * @return Moderation[] newest decision first
	 */
	public function getAll(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_id', 'level', 'comment', 'creation')
			->from(self::TABLE_MODERATION)
			->orderBy('creation', 'desc');

		$all = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$all[] = Moderation::fromRow($row);
		}
		$cursor->closeCursor();

		return $all;
	}

	/**
	 * @param string $level one of Moderation::LEVELS
	 *
	 * @return string[] the actor ids under that decision
	 */
	public function getActorIdsAt(string $level): array {
		$cached = $this->cache->get('level.' . $level);
		if (is_array($cached)) {
			return $cached;
		}

		$qb = $this->getQueryBuilder();
		$qb->select('actor_id')
			->from(self::TABLE_MODERATION)
			->where($qb->expr()->eq('level', $qb->createNamedParameter($level)));

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$ids[] = (string)$row['actor_id'];
		}
		$cursor->closeCursor();

		$this->cache->set('level.' . $level, $ids, self::LEVEL_TTL);

		return $ids;
	}

	/**
	 * Drops the cached lists of who is at each level, after a decision has
	 * been written: the timelines read them on every public page, and a
	 * decision is rare.
	 */
	private function forgetLevels(): void {
		foreach (Moderation::LEVELS as $level) {
			$this->cache->remove('level.' . $level);
		}
	}

	/**
	 * Whether every post by this account is to be marked sensitive: a decision
	 * apart from the levels, lighter than silencing (Pixelfed's `cw`).
	 */
	public function setForceSensitive(string $actorId, bool $sensitive): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_MODERATION)
			->set('force_sensitive', $qb->createNamedParameter($sensitive, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}

	/**
	 * The accounts every post of which is marked sensitive, read as a set since
	 * it is consulted for every stored post and almost always empty.
	 *
	 * @return string[] actor ids
	 */
	public function forcedSensitive(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_id')
			->from(self::TABLE_MODERATION)
			->where($qb->expr()->eq('force_sensitive', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$ids[] = (string)$row['actor_id'];
		}
		$cursor->closeCursor();

		return $ids;
	}

	/**
	 * Which of these accounts are under a decision at this level: one query
	 * per 500 of them, for a page of accounts.
	 *
	 * @param string[] $actorIds
	 * @return array<string, true> actor id => true
	 */
	public function amongAt(array $actorIds, string $level): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($actorIds)), 500) as $chunk) {
			$qb = $this->getQueryBuilder();
			$prims = array_values(array_filter(array_map(static fn (string $id): string => $qb->prim($id), $chunk)));
			if ($prims === []) {
				continue;
			}
			$qb->select('actor_id')
				->from(self::TABLE_MODERATION)
				->where($qb->expr()->in('actor_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->eq('level', $qb->createNamedParameter($level)));
			$cursor = $qb->executeQuery();
			while ($row = $cursor->fetch()) {
				if (in_array((string)$row['actor_id'], $chunk, true)) {
					$found[(string)$row['actor_id']] = true;
				}
			}
			$cursor->closeCursor();
		}

		return $found;
	}

	public function levelOf(string $actorId): string {
		$qb = $this->getQueryBuilder();
		$qb->select('level')
			->from(self::TABLE_MODERATION)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? '' : (string)$row['level'];
	}
}
