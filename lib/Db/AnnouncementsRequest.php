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
 * An announcement belongs to the instance rather than to an account, so there
 * is no owner to compare in SQL here and no access rule this class enforces:
 * who may write one is the admin requirement on the routes that do. The one
 * thing every read does carry is the window — `getActive()` compares the
 * bounds in the statement, so an announcement whose range has not opened or
 * has closed is a row the query never returns. Nothing deletes it: an
 * instance whose cron has stopped keeps showing a stale notice otherwise, and
 * a notice that outstays its window is the failure this feature has.
 *
 * A dismissal is one row per (account, announcement), so one account's read
 * state cannot be read off another's, and there is nothing per-account in the
 * announcement row itself to get that wrong with.
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
	 * Every announcement there is, newest first — the administration list,
	 * where one that has not started yet and one that has run out are both
	 * things the admin has to be able to see and remove.
	 *
	 * @return Announcement[]
	 */
	public function getAll(): array {
		$qb = $this->getAnnouncementsSelectSql();
		$qb->orderBy('a.id', 'desc');

		return $this->announcements($qb);
	}

	/**
	 * The announcements that apply as of `$now`.
	 *
	 * The window is a predicate of this statement and not a row somebody
	 * deletes, so an announcement starts and stops being served at the moment
	 * it says it will, on an instance with no working cron as much as on one
	 * with. A bound that was never set is NULL and matches either way.
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
	 * Removes the announcement and every dismissal of it: those rows have
	 * nothing left to say about anything once it is gone.
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

		// and the reactions to it, for the same reason as the dismissals: the
		// announcement is gone for everybody, read or not, reacted to or not
		$reactions = $this->getReactionsDeleteSql();
		$reactions->where($reactions->expr()->eq(
			'announcement_id', $reactions->createNamedParameter($id, IQueryBuilder::PARAM_INT)
		));
		$reactions->executeStatement();
	}

	/**
	 * Marks the announcement read by that account.
	 *
	 * Idempotent: dismissing twice is what a client does when two devices have
	 * the same unread notice open, and the unique index on (account,
	 * announcement) is what makes the second one a no-op rather than a second
	 * row.
	 */
	public function dismiss(int $announcementId, string $actorId): void {
		$qb = $this->getReadsInsertSql();
		$qb->setValue('announcement_id', $qb->createNamedParameter($announcementId, IQueryBuilder::PARAM_INT))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$this->insertUnlessDuplicate($qb);
	}

	/**
	 * Which of those announcements that account has dismissed, in one query
	 * rather than one per announcement: a client reads the whole active set at
	 * once.
	 *
	 * The account is in the statement, so what comes back can only ever be
	 * that account's own dismissals — one user's read state is not readable
	 * through another's.
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
	 * this account is among — in one query rather than one per announcement,
	 * because a client reads the whole active set at once.
	 *
	 * The account is in the statement, so `me` can only ever describe the
	 * account being answered.
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
	 * Everything an account leaves behind here when it is deleted. Called from
	 * the account-deletion path, like every other deleteRelatedId(). The
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
	 * A timestamp as the date columns here hold one.
	 *
	 * Every other date in this schema is written from `new DateTime('now')`,
	 * which carries the server's timezone, and DBAL formats a DateTime in
	 * whatever zone the object has. A `DateTime('@…')` is always UTC, so a
	 * window built from a timestamp would be stored hours away from the `now`
	 * it is compared with on any instance that is not on UTC.
	 */
	private function dateTime(int $timestamp): ?DateTime {
		if ($timestamp === 0) {
			return null;
		}

		return (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
	}
}
