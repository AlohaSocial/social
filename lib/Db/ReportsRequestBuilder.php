<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\Report;
use OCA\Social\Tools\DbTime;
use OCA\Social\Tools\Traits\TArrayTools;

class ReportsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getReportsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_REPORTS);

		return $qb;
	}

	protected function getReportsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_REPORTS);

		return $qb;
	}

	protected function getReportsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('r.id', 'r.actor_id', 'r.account_id', 'r.status_ids', 'r.comment', 'r.category', 'r.rules', 'r.local', 'r.resolved', 'r.forwarded', 'r.creation')
			->from(self::TABLE_REPORTS, 'r');

		$this->defaultSelectAlias = 'r';
		$qb->setDefaultSelectAlias('r');

		return $qb;
	}

	protected function getReportsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_REPORTS);

		return $qb;
	}

	protected function parseReportsSelectSql(array $data): Report {
		$statusIds = json_decode($this->get('status_ids', $data, '[]'), true);

		$report = new Report();
		$report->setId($this->getInt('id', $data))
			->setActorId($this->get('actor_id', $data))
			->setAccountId($this->get('account_id', $data))
			->setStatusIds(is_array($statusIds) ? $statusIds : [])
			->setComment($this->get('comment', $data))
			->setCategory($this->get('category', $data, Report::CATEGORY_OTHER))
			->setRules(Report::decodeRules($this->get('rules', $data)))
			->setLocal($this->getInt('local', $data, 1) === 1)
			->setResolved($this->getInt('resolved', $data, 0) === 1)
			->setForwarded($this->getInt('forwarded', $data, 0) === 1);

		$creation = $this->get('creation', $data);
		if ($creation !== '') {
			$report->setCreation(DbTime::toTimestamp($creation));
		}

		return $report;
	}
}
