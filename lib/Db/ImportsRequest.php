<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\ImportJob;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The imports an account asked for, and where each one got to.
 *
 * Three reads, all small: one row by id for the job that runs it, one
 * account's recent rows for the page that watches them, and whether an
 * account already has one of a kind under way, which is what keeps a second
 * press of the same button from queueing the same file twice.
 *
 * @package OCA\Social\Db
 */
class ImportsRequest extends CoreRequestBuilder {
	/** How many of an account's imports the page is shown, newest first. */
	public const RECENT = 20;

	public function create(ImportJob $job): void {
		$now = time();
		$job->setCreation($now)->setUpdated($now);

		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_IMPORTS)
			->setValue('user_id', $qb->createNamedParameter($job->getUserId()))
			->setValue('kind', $qb->createNamedParameter($job->getKind()))
			->setValue('status', $qb->createNamedParameter($job->getStatus()))
			->setValue('total', $qb->createNamedParameter($job->getTotal(), IQueryBuilder::PARAM_INT))
			->setValue('done', $qb->createNamedParameter($job->getDone(), IQueryBuilder::PARAM_INT))
			->setValue('skipped', $qb->createNamedParameter($job->getSkipped(), IQueryBuilder::PARAM_INT))
			->setValue('failed', $qb->createNamedParameter($job->getFailed(), IQueryBuilder::PARAM_INT))
			->setValue('report', $qb->createNamedParameter(json_encode($job->getReport())))
			->setValue('options', $qb->createNamedParameter(json_encode($job->getOptions())))
			->setValue('file', $qb->createNamedParameter($job->getFile()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('@' . $now), IQueryBuilder::PARAM_DATE))
			->setValue('updated', $qb->createNamedParameter(new DateTime('@' . $now), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();

		$job->setId($qb->getLastInsertId());
	}

	/** Writes where a run got to and what it came to; everything but who asked and for what. */
	public function update(ImportJob $job): void {
		$job->setUpdated(time());

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_IMPORTS)
			->set('status', $qb->createNamedParameter($job->getStatus()))
			->set('total', $qb->createNamedParameter($job->getTotal(), IQueryBuilder::PARAM_INT))
			->set('done', $qb->createNamedParameter($job->getDone(), IQueryBuilder::PARAM_INT))
			->set('skipped', $qb->createNamedParameter($job->getSkipped(), IQueryBuilder::PARAM_INT))
			->set('failed', $qb->createNamedParameter($job->getFailed(), IQueryBuilder::PARAM_INT))
			->set('report', $qb->createNamedParameter(json_encode($job->getReport())))
			->set('file', $qb->createNamedParameter($job->getFile()))
			->set('updated', $qb->createNamedParameter(new DateTime('@' . $job->getUpdated()), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($job->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function getById(int $id): ?ImportJob {
		$qb = $this->getQueryBuilder();
		$this->select($qb)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->rows($qb)[0] ?? null;
	}

	/** @return ImportJob[] one account's imports, newest first */
	public function getByUser(string $userId, int $limit = self::RECENT): array {
		$qb = $this->getQueryBuilder();
		$this->select($qb)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'desc')
			->setMaxResults($limit);

		return $this->rows($qb);
	}

	/** Whether an import of this kind is queued or running for this account. */
	public function hasActive(string $userId, string $kind): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_IMPORTS)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[ImportJob::STATUS_QUEUED, ImportJob::STATUS_RUNNING], IQueryBuilder::PARAM_STR_ARRAY
			)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$found = $cursor->fetch();
		$cursor->closeCursor();

		return $found !== false;
	}

	public function delete(int $id): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_IMPORTS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	/** Everything an account leaves behind here when it is deleted. */
	public function deleteByUser(string $userId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_IMPORTS)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	private function select(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select(
			'id', 'user_id', 'kind', 'status', 'total', 'done', 'skipped', 'failed',
			'report', 'options', 'file', 'creation', 'updated'
		)->from(self::TABLE_IMPORTS);
	}

	/**
	 * @return ImportJob[]
	 */
	private function rows(IQueryBuilder $qb): array {
		$jobs = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$report = json_decode((string)($data['report'] ?? ''), true);
			$options = json_decode((string)($data['options'] ?? ''), true);
			$job = new ImportJob();
			$job->setId((int)$data['id'])
				->setUserId((string)($data['user_id'] ?? ''))
				->setKind((string)($data['kind'] ?? ''))
				->setStatus((string)($data['status'] ?? ImportJob::STATUS_QUEUED))
				->setTotal((int)($data['total'] ?? 0))
				->setDone((int)($data['done'] ?? 0))
				->setSkipped((int)($data['skipped'] ?? 0))
				->setFailed((int)($data['failed'] ?? 0))
				->setReport(is_array($report) ? $report : [])
				->setOptions(is_array($options) ? $options : [])
				->setFile((string)($data['file'] ?? ''))
				->setCreation($this->timestampOf($data['creation'] ?? null))
				->setUpdated($this->timestampOf($data['updated'] ?? null));
			$jobs[] = $job;
		}
		$cursor->closeCursor();

		return $jobs;
	}

	private function timestampOf(mixed $value): int {
		if ($value === null || $value === '') {
			return 0;
		}

		$time = strtotime((string)$value);

		return ($time === false) ? 0 : $time;
	}
}
