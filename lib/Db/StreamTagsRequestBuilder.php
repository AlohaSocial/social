<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

class StreamTagsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamTagsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM_TAGS);

		return $qb;
	}

	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getStreamTagsUpdateSql(): IQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STREAM_TAGS);

		return $qb;
	}

	/**
	 * Base of the Sql Select request
	 *
	 * @return IQueryBuilder
	 */
	protected function getStreamTagsSelectSql(): IQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select('st.stream_id', 'st.hashtag')
			->from(self::TABLE_STREAM_TAGS, 'st');

		$this->defaultSelectAlias = 'st';
		$qb->setDefaultSelectAlias('st');

		return $qb;
	}

	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getStreamTagsDeleteSql(): IQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_TAGS);

		return $qb;
	}
}
