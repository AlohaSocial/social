<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class DiscoveryRequestBuilder
 *
 * Extends CacheActorsRequestBuilder because discovery answers with Account
 * entities, built from `social_cache_actor` (local accounts included). The
 * `discoverable` flag is on `social_actor`, so queries honouring it join the
 * two.
 *
 * Queries run in two steps, like the timelines: an aggregating one picks the
 * accounts by a single column, a second fetches the rows, since grouping by
 * all Account columns is unportable (Oracle cannot group by a CLOB).
 *
 * @package OCA\Social\Db
 */
class DiscoveryRequestBuilder extends CacheActorsRequestBuilder {
	use TArrayTools;

	/** The Account entities of a set of accounts already decided on. */
	protected function getDiscoveryActorsSelectSql(): SocialQueryBuilder {
		return $this->getCacheActorsSelectSql(Stream::FORMAT_LOCAL);
	}

	/**
	 * The local accounts that opted in to being listed, as a single `id_prim`
	 * column; the join reads the `discoverable` flag.
	 */
	protected function getDiscoverablePrimsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('a.id_prim')
			->from(self::TABLE_ACTORS, 'a');
		$qb->andWhere($qb->expr()->eq('a.discoverable', $qb->createNamedParameter(1)));

		$this->defaultSelectAlias = 'a';
		$qb->setDefaultSelectAlias('a');

		return $qb;
	}

	/**
	 * The accepted follows of one account, as a single `object_id_prim`
	 * column. Pending requests are left out, as they would leak a locked
	 * account's followers into suggestions.
	 */
	protected function getFollowedPrimsSelectSql(string $alias): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select($alias . '.object_id_prim')
			->from(self::TABLE_FOLLOWS, $alias);
		$qb->andWhere($qb->expr()->eq($alias . '.accepted', $qb->createNamedParameter(1)));

		$this->defaultSelectAlias = $alias;
		$qb->setDefaultSelectAlias($alias);

		return $qb;
	}
}
