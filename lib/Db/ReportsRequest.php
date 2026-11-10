<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\Report;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Storage for moderation reports.
 */
class ReportsRequest extends ReportsRequestBuilder {
	/** How many reports one page of the administration panel holds. */
	public const PAGE = 50;

	public function save(Report $report): int {
		$qb = $this->getReportsInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($report->getActorId()))
			->setValue('account_id', $qb->createNamedParameter($report->getAccountId()))
			->setValue('status_ids', $qb->createNamedParameter(json_encode($report->getStatusIds())))
			->setValue('comment', $qb->createNamedParameter($report->getComment()))
			->setValue('category', $qb->createNamedParameter($report->getCategory()))
			->setValue('rules', $qb->createNamedParameter($report->getRules() === [] ? null : json_encode($report->getRules())))
			->setValue('local', $qb->createNamedParameter($report->isLocal() ? 1 : 0))
			->setValue('resolved', $qb->createNamedParameter($report->isResolved() ? 1 : 0))
			->setValue('forwarded', $qb->createNamedParameter($report->isForwarded() ? 1 : 0))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
		$id = $qb->getLastInsertId();
		$report->setId($id);
		if ($report->getCreation() === 0) {
			$report->setCreation(time());
		}

		return $id;
	}

	/**
	 * @throws ReportNotFoundException
	 */
	public function getById(int $id): Report {
		$qb = $this->getReportsSelectSql();
		$qb->andWhere($qb->expr()->eq('r.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ReportNotFoundException('report ' . $id . ' not found');
		}

		return $this->parseReportsSelectSql($data);
	}

	/**
	 * @return Report[]
	 */
	public function getAll(bool $includeResolved = false, int $limit = 200): array {
		$qb = $this->getReportsSelectSql();
		if (!$includeResolved) {
			$qb->andWhere($qb->expr()->eq('r.resolved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('r.id', 'desc');
		$qb->setMaxResults($limit);

		$reports = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$reports[] = $this->parseReportsSelectSql($data);
		}
		$cursor->closeCursor();

		return $reports;
	}

	/**
	 * One page of the open or of the resolved reports, newest first.
	 *
	 * The panel used to read the first two hundred of both mixed together, so
	 * an instance with a busy month of resolved complaints pushed the open
	 * ones off the bottom — and past two hundred, off the page entirely.
	 *
	 * @return Report[]
	 */
	public function getPage(bool $resolved, int $limit = self::PAGE, int $offset = 0): array {
		$qb = $this->getReportsSelectSql();
		$qb->andWhere($qb->expr()->eq('r.resolved', $qb->createNamedParameter($resolved ? 1 : 0, IQueryBuilder::PARAM_INT)));
		$qb->orderBy('r.id', 'desc');
		$qb->setMaxResults(max(1, $limit));
		$qb->setFirstResult(max(0, $offset));

		$reports = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$reports[] = $this->parseReportsSelectSql($data);
		}
		$cursor->closeCursor();

		return $reports;
	}

	public function countOpen(): int {
		return $this->count(false);
	}

	/** How many reports are open, or how many are resolved. */
	public function count(bool $resolved): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_REPORTS)
			->where($qb->expr()->eq('resolved', $qb->createNamedParameter($resolved ? 1 : 0, IQueryBuilder::PARAM_INT)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data === false ? [] : $data, 0);
	}

	/**
	 * Recorded after the fact: the report is stored first and delivered
	 * second, so a forward that fails still leaves a report a moderator can
	 * read — and one that succeeds is not lost if this update is the thing
	 * that fails.
	 */
	public function setForwarded(int $id, bool $forwarded): void {
		$qb = $this->getReportsUpdateSql();
		$qb->set('forwarded', $qb->createNamedParameter($forwarded ? 1 : 0));
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Files a report under a different category.
	 *
	 * A moderator reading a report often finds it under the wrong one — "spam"
	 * for something that is harassment — and the category a reporter chose was
	 * the category for ever until this.
	 */
	public function setCategory(int $id, string $category): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_REPORTS)
			->set('category', $qb->createNamedParameter($category))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	public function setResolved(int $id, bool $resolved): void {
		$qb = $this->getReportsUpdateSql();
		$qb->set('resolved', $qb->createNamedParameter($resolved ? 1 : 0));
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * The reports an account is the subject of, and the ones it filed. Both are
	 * about something that no longer exists once the account is gone.
	 */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getReportsDeleteSql();
		$qb->where(
			$qb->expr()->orX(
				$qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId)),
				$qb->expr()->eq('account_id', $qb->createNamedParameter($actorId))
			)
		);

		$qb->executeStatement();
	}

	public function delete(int $id): void {
		$qb = $this->getReportsDeleteSql();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}
}
