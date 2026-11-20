<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\Client\Collection;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Extends StreamRequestBuilder because a collection's items are posts, read
 * through the same `social_stream` helpers as every timeline.
 *
 * @package OCA\Social\Db
 */
class CollectionsRequestBuilder extends StreamRequestBuilder {
	use TArrayTools;

	protected function getCollectionsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_COLLECTIONS);

		return $qb;
	}

	protected function getCollectionsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select(
			'c.id', 'c.actor_id', 'c.actor_id_prim', 'c.title', 'c.description',
			'c.visibility', 'c.creation', 'c.updated'
		)
			->from(self::TABLE_COLLECTIONS, 'c');

		$this->defaultSelectAlias = 'c';
		$qb->setDefaultSelectAlias('c');

		return $qb;
	}

	protected function getCollectionsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_COLLECTIONS);

		return $qb;
	}

	protected function getCollectionsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_COLLECTIONS);

		return $qb;
	}

	protected function getCollectionItemsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_COLLECTION_ITEMS);

		return $qb;
	}

	protected function getCollectionItemsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('ci.id', 'ci.collection_id', 'ci.stream_id_prim', 'ci.position', 'ci.creation')
			->from(self::TABLE_COLLECTION_ITEMS, 'ci');

		$this->defaultSelectAlias = 'ci';
		$qb->setDefaultSelectAlias('ci');

		return $qb;
	}

	protected function getCollectionItemsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_COLLECTION_ITEMS);

		return $qb;
	}

	/** @return Collection[] */
	protected function getCollectionsFromRequest(SocialQueryBuilder $qb): array {
		/** @var Collection[] $result */
		$result = $qb->getRows(
			static function (array $data): Collection {
				$collection = new Collection();
				$collection->importFromDatabase($data);

				return $collection;
			}
		);

		return $result;
	}
}
