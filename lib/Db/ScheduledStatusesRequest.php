<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\ScheduledStatus;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The posts an account has asked to have published later.
 *
 * Every read a client can reach compares the owner's actor id in SQL, which
 * is the whole access control on unpublished text. Only the cron's
 * `getDue()` and `claim()` are unscoped, as the job runs for every account.
 */
class ScheduledStatusesRequest extends ScheduledStatusesRequestBuilder {
	/**
	 * @return int the id the post was stored under, which is the id the API
	 *             hands the client
	 */
	public function save(ScheduledStatus $scheduled): int {
		$qb = $this->getScheduledInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($scheduled->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($scheduled->getActorId())))
			->setValue(
				'scheduled_at',
				$qb->createNamedParameter($this->dateTime($scheduled->getScheduledAt()), IQueryBuilder::PARAM_DATE)
			)
			->setValue('params', $qb->createNamedParameter($scheduled->exportParams()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
		$id = $qb->getLastInsertId();
		$scheduled->setId($id);
		if ($scheduled->getCreation() === 0) {
			$scheduled->setCreation(time());
		}

		return $id;
	}

	/**
	 * Moves a waiting post to another time. The owner is in the statement, so
	 * a request naming somebody else's post changes no row.
	 */
	public function reschedule(int $id, string $actorId, int $scheduledAt): void {
		$qb = $this->getScheduledUpdateSql();
		$qb->set(
			'scheduled_at',
			$qb->createNamedParameter($this->dateTime($scheduledAt), IQueryBuilder::PARAM_DATE)
		);
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);

		$qb->executeStatement();
	}

	/**
	 * One waiting post of one account.
	 *
	 * @throws ItemNotFoundException there is no such post of this account,
	 *                               including one of somebody else's
	 */
	public function getById(int $id, string $actorId): ScheduledStatus {
		$qb = $this->getScheduledSelectSql();
		$qb->andWhere($qb->expr()->eq('ss.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ss.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ItemNotFoundException('Record not found');
		}

		return $this->parseScheduledSelectSql($data);
	}

	/**
	 * A page of the account's waiting posts, soonest first.
	 *
	 * The cursor is a row id, as the API promises, but ids follow creation and
	 * `reschedule()` moves posts in time, so the id is resolved to its
	 * `(scheduled_at, id)` pair and compared on that. `min_id` and `since_id`
	 * read forwards from it; `max_id` reads the `limit` rows before it
	 * backwards and turns them round, as Mastodon pages a descending list.
	 *
	 * @return ScheduledStatus[]
	 */
	public function getByActor(
		string $actorId,
		int $limit = 20,
		int $maxId = 0,
		int|string $minId = 0,
		int $sinceId = 0,
	): array {
		$qb = $this->getScheduledSelectSql();
		$qb->andWhere($qb->expr()->eq('ss.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		// Mastodon's `since_id` and `min_id` both ask for what follows.
		$after = ((int)$minId > 0) ? (int)$minId : $sinceId;
		$backwards = ($maxId > 0 && $after < 1);

		if ($maxId > 0) {
			$qb->andWhere($this->beyond($qb, $actorId, $maxId, false));
		}
		if ($after > 0) {
			$qb->andWhere($this->beyond($qb, $actorId, $after, true));
		}

		$qb->orderBy('ss.scheduled_at', $backwards ? 'desc' : 'asc');
		$qb->addOrderBy('ss.id', $backwards ? 'desc' : 'asc');
		$qb->setMaxResults($limit);

		$page = $this->getScheduledFromRequest($qb);

		// Read backwards to find the rows next to the cursor; returned in list order.
		return $backwards ? array_reverse($page) : $page;
	}

	/**
	 * Everything ordered after (or before) the row a cursor names.
	 *
	 * `(scheduled_at, id)` is compared as a pair spelled out, since SQLite does
	 * not index a row constructor. A cursor naming no row of this account (one
	 * published or cancelled between pages) falls back to comparing ids.
	 */
	private function beyond(
		SocialQueryBuilder $qb,
		string $actorId,
		int $cursor,
		bool $after,
	): ICompositeExpression|string {
		$id = $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT);
		$at = $this->scheduledAtOf($cursor, $actorId);

		if ($at === null) {
			return $after ? $qb->expr()->gt('ss.id', $id) : $qb->expr()->lt('ss.id', $id);
		}

		$time = $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATE);

		return $qb->expr()->orX(
			$after ? $qb->expr()->gt('ss.scheduled_at', $time) : $qb->expr()->lt('ss.scheduled_at', $time),
			$qb->expr()->andX(
				$qb->expr()->eq('ss.scheduled_at', $time),
				$after ? $qb->expr()->gt('ss.id', $id) : $qb->expr()->lt('ss.id', $id)
			)
		);
	}

	/** When the row a cursor names is due, or null where it names none. */
	private function scheduledAtOf(int $id, string $actorId): ?DateTime {
		$qb = $this->getQueryBuilder();
		$qb->select('scheduled_at')
			->from(self::TABLE_SCHEDULED)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$at = $cursor->fetchOne();
		$cursor->closeCursor();

		return ($at === false || $at === null || $at === '') ? null : new DateTime((string)$at, new DateTimeZone('UTC'));
	}

	/** How many posts the account has waiting, against Mastodon's total cap. */
	public function countByActor(string $actorId): int {
		$qb = $this->countScheduledSelectSql();
		$qb->andWhere($qb->expr()->eq('ss.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		return $this->countFromRequest($qb);
	}

	/**
	 * How many the account has waiting between two instants, against Mastodon's
	 * per-day cap. The day is bounded in PHP, since casting to a date in SQL
	 * differs between databases.
	 */
	public function countByActorBetween(string $actorId, int $from, int $until): int {
		$qb = $this->countScheduledSelectSql();
		$qb->andWhere($qb->expr()->eq('ss.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere(
				$qb->expr()->gte(
					'ss.scheduled_at',
					$qb->createNamedParameter($this->dateTime($from), IQueryBuilder::PARAM_DATE)
				)
			)
			->andWhere(
				$qb->expr()->lt(
					'ss.scheduled_at',
					$qb->createNamedParameter($this->dateTime($until), IQueryBuilder::PARAM_DATE)
				)
			);

		return $this->countFromRequest($qb);
	}

	/**
	 * The posts whose time has come, across every account, soonest first.
	 * Bounded, so a cron that was down for a week catches up over several runs.
	 *
	 * @param string[] $exceptActors the actor ids whose posts are held back
	 *                               and left out, so they do not fill the
	 *                               window run after run
	 *
	 * @return ScheduledStatus[]
	 */
	public function getDue(int $now, int $limit = 50, array $exceptActors = []): array {
		$qb = $this->getScheduledSelectSql();
		$qb->andWhere(
			$qb->expr()->lte(
				'ss.scheduled_at',
				$qb->createNamedParameter($this->dateTime($now), IQueryBuilder::PARAM_DATE)
			)
		);
		if ($exceptActors !== []) {
			$qb->andWhere($qb->expr()->notIn(
				'ss.actor_id_prim',
				$qb->createNamedParameter(array_map(fn (string $id): string => $qb->prim($id), $exceptActors), IQueryBuilder::PARAM_STR_ARRAY)
			));
		}

		$qb->orderBy('ss.scheduled_at', 'asc');
		$qb->addOrderBy('ss.id', 'asc');
		$qb->setMaxResults($limit);

		return $this->getScheduledFromRequest($qb);
	}

	/**
	 * The uploads every waiting post names, as nid => true, read in pages by id.
	 *
	 * @return array<int, true>
	 */
	public function mediaNids(int $page = 500): array {
		$nids = [];
		$after = 0;
		do {
			$qb = $this->getScheduledSelectSql();
			$qb->andWhere($qb->expr()->gt('ss.id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)));
			$qb->orderBy('ss.id', 'asc');
			$qb->setMaxResults($page);
			$rows = $this->getScheduledFromRequest($qb);
			foreach ($rows as $scheduled) {
				$after = max($after, $scheduled->getId());
				foreach ($scheduled->paramMediaIds() as $nid) {
					$nids[$nid] = true;
				}
			}
		} while (count($rows) >= $page);

		return $nids;
	}

	/**
	 * Takes a row out of the table for a publisher, and says whether this
	 * caller got it. The delete is the claim: of two cron workers only one
	 * affects the row, and nothing is left to clear if a worker dies.
	 */
	public function claim(int $id): bool {
		$qb = $this->getScheduledDeleteSql();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() === 1;
	}

	/**
	 * @return bool whether a row of this account's was deleted; false is both
	 *              "no such post" and "not yours", which are one answer
	 */
	public function delete(int $id, string $actorId): bool {
		$qb = $this->getScheduledDeleteSql();
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);

		return $qb->executeStatement() > 0;
	}

	/**
	 * Everything an account leaves behind here when it is deleted, so nothing
	 * is published under an account that no longer exists.
	 */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getScheduledDeleteSql();
		$qb->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}

	/**
	 * @return ScheduledStatus[]
	 */
	private function getScheduledFromRequest(SocialQueryBuilder $qb): array {
		$scheduled = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$scheduled[] = $this->parseScheduledSelectSql($data);
		}
		$cursor->closeCursor();

		return $scheduled;
	}

	private function countFromRequest(SocialQueryBuilder $qb): int {
		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false) ? 0 : $this->getInt('count', $data);
	}

	/**
	 * A timestamp as the date columns here hold one: in the server's timezone,
	 * like the `new DateTime('now')` the job compares it with, since a
	 * `DateTime('@…')` is UTC and would publish hours early off UTC.
	 */
	private function dateTime(int $timestamp): DateTime {
		return (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
	}
}
