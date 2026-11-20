<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\StreamQueue;
use OCA\Social\Tools\Traits\TArrayTools;

class StreamQueueRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamQueueInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM_QUEUE);

		return $qb;
	}

	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamQueueUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STREAM_QUEUE);

		return $qb;
	}

	/**
	 * Base of the Sql Select request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamQueueSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'qs.id', 'qs.token', 'qs.stream_id', 'qs.type', 'qs.status', 'qs.tries', 'qs.last'
		)
			->from(self::TABLE_STREAM_QUEUE, 'qs');

		$this->defaultSelectAlias = 'qs';
		$qb->setDefaultSelectAlias('qs');

		return $qb;
	}

	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamQueueDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_QUEUE);

		return $qb;
	}

	/**
	 * @param array $data
	 *
	 * @return StreamQueue
	 */
	public function parseStreamQueueSelectSql($data): StreamQueue {
		$queue = new StreamQueue();
		$queue->importFromDatabase($data);

		return $queue;
	}
}
