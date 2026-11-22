<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\Client\Announcement;
use OCA\Social\Tools\Traits\TArrayTools;

class AnnouncementsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getAnnouncementsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ANNOUNCEMENTS);

		return $qb;
	}

	protected function getAnnouncementsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('a.id', 'a.content', 'a.starts_at', 'a.ends_at', 'a.all_day', 'a.creation', 'a.last_update')
			->from(self::TABLE_ANNOUNCEMENTS, 'a');

		$this->defaultSelectAlias = 'a';
		$qb->setDefaultSelectAlias('a');

		return $qb;
	}

	protected function getAnnouncementsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ANNOUNCEMENTS);

		return $qb;
	}

	protected function getReadsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ANNOUNCEMENT_READS);

		return $qb;
	}

	protected function getReadsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('ar.id', 'ar.announcement_id', 'ar.actor_id_prim')
			->from(self::TABLE_ANNOUNCEMENT_READS, 'ar');

		$this->defaultSelectAlias = 'ar';
		$qb->setDefaultSelectAlias('ar');

		return $qb;
	}

	protected function getReadsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ANNOUNCEMENT_READS);

		return $qb;
	}

	protected function getReactionsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ANNOUNCEMENT_REACTIONS);

		return $qb;
	}

	protected function getReactionsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('re.id', 're.announcement_id', 're.actor_id_prim', 're.name')
			->from(self::TABLE_ANNOUNCEMENT_REACTIONS, 're');

		$this->defaultSelectAlias = 're';
		$qb->setDefaultSelectAlias('re');

		return $qb;
	}

	protected function getReactionsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ANNOUNCEMENT_REACTIONS);

		return $qb;
	}

	protected function parseAnnouncementsSelectSql(array $data): Announcement {
		return (new Announcement())->importFromDatabase($data);
	}
}
