<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCA\Social\Model\Client\FilterStatus;
use OCA\Social\Tools\DbTime;
use OCA\Social\Tools\Traits\TArrayTools;

class FiltersRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * The filter tables are not `TABLE_*` constants of `CoreRequestBuilder`,
	 * so `CoreRequestBuilder::$tables` and `occ social:reset` do not know
	 * them, and a reset leaves them behind.
	 */

	protected function getFiltersInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FILTERS);

		return $qb;
	}

	protected function getFiltersUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_FILTERS);

		return $qb;
	}

	protected function getFiltersSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('f.id', 'f.actor_id_prim', 'f.title', 'f.contexts', 'f.action', 'f.expires_at', 'f.creation')
			->from(self::TABLE_FILTERS, 'f');

		$this->defaultSelectAlias = 'f';
		$qb->setDefaultSelectAlias('f');

		return $qb;
	}

	protected function getFiltersDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FILTERS);

		return $qb;
	}

	protected function getKeywordsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FILTER_KEYWORDS);

		return $qb;
	}

	protected function getKeywordsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_FILTER_KEYWORDS);

		return $qb;
	}

	protected function getKeywordsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('fk.id', 'fk.filter_id', 'fk.keyword', 'fk.whole_word', 'fk.creation')
			->from(self::TABLE_FILTER_KEYWORDS, 'fk');

		$this->defaultSelectAlias = 'fk';
		$qb->setDefaultSelectAlias('fk');

		return $qb;
	}

	protected function getKeywordsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FILTER_KEYWORDS);

		return $qb;
	}

	protected function getStatusesInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FILTER_STATUSES);

		return $qb;
	}

	protected function getStatusesSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('fs.id', 'fs.filter_id', 'fs.status_id', 'fs.creation')
			->from(self::TABLE_FILTER_STATUSES, 'fs');

		$this->defaultSelectAlias = 'fs';
		$qb->setDefaultSelectAlias('fs');

		return $qb;
	}

	protected function getStatusesDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FILTER_STATUSES);

		return $qb;
	}

	/**
	 * A filtered status is owned by whoever owns its filter — the same join,
	 * for the same reason, as `getOwnedKeywordSelectSql()`.
	 */
	protected function getOwnedStatusSelectSql(string $actorId): SocialQueryBuilder {
		$qb = $this->getStatusesSelectSql();
		$qb->innerJoin(
			'fs',
			self::TABLE_FILTERS,
			'f',
			$qb->expr()->andX(
				$qb->expr()->eq('fs.filter_id', 'f.id'),
				$qb->expr()->eq('f.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			)
		);

		return $qb;
	}

	protected function parseStatusesSelectSql(array $data): FilterStatus {
		return (new FilterStatus())
			->setId($this->getInt('id', $data))
			->setFilterId($this->getInt('filter_id', $data))
			->setStatusId($this->get('status_id', $data, '0'));
	}

	/**
	 * A keyword is owned by whoever owns its filter, so the ownership check is
	 * this join: somebody else's keyword comes back empty.
	 */
	protected function getOwnedKeywordSelectSql(string $actorId): SocialQueryBuilder {
		$qb = $this->getKeywordsSelectSql();
		$qb->innerJoin(
			'fk',
			self::TABLE_FILTERS,
			'f',
			$qb->expr()->andX(
				$qb->expr()->eq('fk.filter_id', 'f.id'),
				$qb->expr()->eq('f.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			)
		);

		return $qb;
	}

	protected function parseFiltersSelectSql(array $data): Filter {
		$filter = new Filter();
		$filter->setId($this->getInt('id', $data))
			->setTitle($this->get('title', $data))
			->setAction($this->get('action', $data, Filter::ACTION_WARN))
			->importContexts($this->get('contexts', $data));

		$expiresAt = $this->get('expires_at', $data);
		if ($expiresAt !== '') {
			$filter->setExpiresAt(DbTime::toTimestamp($expiresAt));
		}

		$creation = $this->get('creation', $data);
		if ($creation !== '') {
			$filter->setCreation(DbTime::toTimestamp($creation));
		}

		return $filter;
	}

	protected function parseKeywordsSelectSql(array $data): FilterKeyword {
		$keyword = new FilterKeyword();
		$keyword->setId($this->getInt('id', $data))
			->setFilterId($this->getInt('filter_id', $data))
			->setKeyword($this->get('keyword', $data))
			->setWholeWord($this->getInt('whole_word', $data) === 1);

		$creation = $this->get('creation', $data);
		if ($creation !== '') {
			$keyword->setCreation(DbTime::toTimestamp($creation));
		}

		return $keyword;
	}
}
