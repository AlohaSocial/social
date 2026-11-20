<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\Client\ScheduledStatus;
use OCA\Social\Tools\Traits\TArrayTools;

class ScheduledStatusesRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getScheduledInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_SCHEDULED);

		return $qb;
	}

	protected function getScheduledUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_SCHEDULED);

		return $qb;
	}

	protected function getScheduledSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('ss.id', 'ss.actor_id', 'ss.actor_id_prim', 'ss.scheduled_at', 'ss.params', 'ss.creation')
			->from(self::TABLE_SCHEDULED, 'ss');

		$this->defaultSelectAlias = 'ss';
		$qb->setDefaultSelectAlias('ss');

		return $qb;
	}

	protected function countScheduledSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_SCHEDULED, 'ss');

		$this->defaultSelectAlias = 'ss';
		$qb->setDefaultSelectAlias('ss');

		return $qb;
	}

	protected function getScheduledDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_SCHEDULED);

		return $qb;
	}

	protected function parseScheduledSelectSql(array $data): ScheduledStatus {
		return (new ScheduledStatus())->importFromDatabase($data);
	}
}
