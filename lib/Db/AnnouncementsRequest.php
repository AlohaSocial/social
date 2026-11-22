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
use OCA\Social\Model\Client\Announcement;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The instance's announcements, and which account has dismissed which.
 *
 * Announcements belong to the instance, so there is no owner check here;
 * the admin requirement is on the routes. `getActive()` applies the window
 * in SQL so nothing has to delete expired ones. Dismissals are one row per
 * (account, announcement).
 */
class AnnouncementsRequest extends AnnouncementsRequestBuilder {
	/**
	 * @return int the id the announcement was stored under
	 */
	public function save(Announcement $announcement): int {
		$now = new DateTime('now');

		$qb = $this->getAnnouncementsInsertSql();
		$qb->setValue('content', $qb->createNamedParameter($announcement->getText()))
			->setValue(
				'starts_at',
				$qb->createNamedParameter($this->dateTime($announcement->getStartsAt()), IQueryBuilder::PARAM_DATE)
			)
			->setValue(
				'ends_at',
				$qb->createNamedParameter($this->dateTime($announcement->getEndsAt()), IQueryBuilder::PARAM_DATE)
			)
			->setValue('all_day', $qb->createNamedParameter($announcement->isAllDay() ? 1 : 0, IQueryBuilder::PARAM_INT))
			->setValue('creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->setValue('last_update', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
		$id = $qb->getLastInsertId();

		$announcement->setId($id);
		if ($announcement->getPublishedAt() === 0) {
			$announcement->setPublishedAt(time());
		}
		$announcement->setUpdatedAt($announcement->getPublishedAt());

		return $id;
	}

	/**
	 * Every announcement there is, newest first, for the administration list,
	 * including ones not started or expired.
	 *
	 * @return Announcement[]
	 */
	public function getAll(): array {
		$qb = $this->getAnnouncementsSelectSql();
		$qb->orderBy('a.id', 'desc');

		return $this->announcements($qb);
	}

	/**
	 * The announcements that apply as of `$now`. The window is a predicate of
	 * the statement, so it works without a cron; a NULL bound matches either way.
	 *
	 * @return Announcement[]
	 */
	public function getActive(?int $now = null): array {
		$now ??= time();

		$qb = $this->getAnnouncementsSelectSql();
		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->isNull('a.starts_at'),
				$qb->expr()->lte(
					'a.starts_at',
					$qb->createNamedParameter($this->dateTime($now), IQueryBuilder::PARAM_DATE)
				)
			)
		);
		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->isNull('a.ends_at'),
				$qb->expr()->gt(
					'a.ends_at',
					$qb->createNamedParameter($this->dateTime($now), IQueryBuilder::PARAM_DATE)
				)
			)
		);
		$qb->orderBy('a.id', 'asc');

		return $this->announcements($qb);
	}

	/**
	 * @throws ItemNotFoundException there is no announcement with that id
	 */
	public function getById(int $id): Announcement {
		$qb = $this->getAnnouncementsSelectSql();
		$qb->andWhere($qb->expr()->eq('a.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ItemNotFoundException('announcement not found');
		}

		return $this->parseAnnouncementsSelectSql($data);
	}

	/**
	 * Removes the announcement and every dismissal of it.
	 *
	 * @throws ItemNotFoundException there was no such announcement, so nothing
	 *                               was removed
	 */
	public function delete(int $id): void {
		$qb = $this->getAnnouncementsDeleteSql();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		if ($qb->executeStatement() === 0) {
			throw new ItemNotFoundException('announcement not found');
		}

		$qb = $this->getReadsDeleteSql();
		$qb->where($qb->expr()->eq('announcement_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		// And the reactions to it.
		$reactions = $this->getReactionsDeleteSql();
		$reactions->where($reactions->expr()->eq(
			'announcement_id', $reactions->createNamedParameter($id, IQueryBuilder::PARAM_INT)
		));
		$reactions->executeStatement();
	}

	/**
	 * Marks the announcement read by that account. Idempotent through the
	 * unique index on (account, announcement), as two devices may dismiss the
	 * same notice.
	 */
	public function dismiss(int $announcementId, string $actorId): void {
		$qb = $this->getReadsInsertSql();
		$qb->setValue('announcement_id', $qb->createNamedParameter($announcementId, IQueryBuilder::PARAM_INT))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$this->insertUnlessDuplicate($qb);
	}

	/**
	 * Which of those announcements that account has dismissed, in one query; the
	 * account is in the statement, so only its own dismissals come back.
	 *
	 * @param int[] $announcementIds
	 *
	 * @return int[] the ids that were dismissed
	 */
	public function dismissedBy(string $actorId, array $announcementIds): array {
		if ($announcementIds === []) {
			return [];
		}

		$qb = $this->getReadsSelectSql();
		$qb->andWhere($qb->expr()->eq('ar.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere(
				$qb->expr()->in(
					'ar.announcement_id',
					$qb->createNamedParameter($announcementIds, IQueryBuilder::PARAM_INT_ARRAY)
				)
			);

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$ids[] = $this->getInt('announcement_id', $data);
		}
		$cursor->closeCursor();

		return $ids;
	}

	/**
	 * Records a reaction. Reacting twice with the same emoji is a no-op, which
	 * is what the unique index is for.
	 */
	public function react(int $announcementId, string $actorId, string $name): void {
		$qb = $this->getReactionsInsertSql();
		$qb->setValue('announcement_id', $qb->createNamedParameter($announcementId, IQueryBuilder::PARAM_INT))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('name', $qb->createNamedParameter($name))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$this->insertUnlessDuplicate($qb);
	}

	/** Takes one back. Taking back one that was never there is a no-op. */
	public function unreact(int $announcementId, string $actorId, string $name): void {
		$qb = $this->getReactionsDeleteSql();
		$qb->where($qb->expr()->eq('announcement_id', $qb->createNamedParameter($announcementId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));

		$qb->executeStatement();
	}

	/**
	 * How many of each reaction those announcements carry, and which of them
	 * this account is among (`me`), in one query.
	 *
	 * @param int[] $announcementIds
	 *
	 * @return array<int, array<string, array{count: int, me: bool}>>
	 *                                                                announcement id => emoji => the count and whether it is ours
	 */
	public function reactionsOn(string $actorId, array $announcementIds): array {
		if ($announcementIds === []) {
			return [];
		}

		$qb = $this->getReactionsSelectSql();
		$prim = $qb->prim($actorId);
		$actorParameter = (string)$qb->createNamedParameter($prim);
		$qb->select('re.announcement_id', 're.name')
			->selectAlias($qb->createFunction('COUNT(re.id)'), 'reaction_count')
			->selectAlias(
				$qb->createFunction(
					'MAX(CASE WHEN re.actor_id_prim = ' . $actorParameter . ' THEN 1 ELSE 0 END)'
				),
				'me'
			)
			->andWhere($qb->expr()->in(
				're.announcement_id',
				$qb->createNamedParameter($announcementIds, IQueryBuilder::PARAM_INT_ARRAY)
			))
			->groupBy('re.announcement_id')
			->addGroupBy('re.name');

		$reactions = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$id = $this->getInt('announcement_id', $data);
			$name = $this->get('name', $data, '');
			$reactions[$id][$name] = [
				'count' => $this->getInt('reaction_count', $data),
				'me' => $this->getInt('me', $data) === 1,
			];
		}
		$cursor->closeCursor();

		return $reactions;
	}

	/** How many distinct emoji one account has put on one announcement. */
	public function countReactionsBy(int $announcementId, string $actorId): int {
		$qb = $this->getReactionsSelectSql();
		$qb->andWhere($qb->expr()->eq('re.announcement_id', $qb->createNamedParameter($announcementId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('re.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$count = 0;
		$cursor = $qb->executeQuery();
		while ($cursor->fetch()) {
			$count++;
		}
		$cursor->closeCursor();

		return $count;
	}

	/**
	 * Everything an account leaves behind here when it is deleted; the
	 * announcements themselves are the instance's and stay.
	 */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getReadsDeleteSql();
		$qb->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->executeStatement();

		$reactions = $this->getReactionsDeleteSql();
		$reactions->where($reactions->expr()->eq(
			'actor_id_prim', $reactions->createNamedParameter($reactions->prim($actorId))
		));
		$reactions->executeStatement();
	}

	/**
	 * @return Announcement[]
	 */
	private function announcements(SocialQueryBuilder $qb): array {
		$announcements = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$announcements[] = $this->parseAnnouncementsSelectSql($data);
		}
		$cursor->closeCursor();

		return $announcements;
	}

	/**
	 * A timestamp as the date columns here hold one: in the server's timezone,
	 * like the `now` it is compared with, since a `DateTime('@…')` is UTC.
	 */
	private function dateTime(int $timestamp): ?DateTime {
		if ($timestamp === 0) {
			return null;
		}

		return (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
	}
}
