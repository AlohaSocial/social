<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TArrayTools;

class StreamActionsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamActionInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM_ACTIONS);

		return $qb;
	}

	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamActionUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STREAM_ACTIONS);

		return $qb;
	}

	/**
	 * Base of the Sql Select request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamActionSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'sa.id', 'sa.actor_id', 'sa.stream_id',
			'sa.boosted', 'sa.liked', 'sa.replied', 'sa.bookmarked',
			'sa.liked_nid', 'sa.bookmarked_nid'
		)
			->from(self::TABLE_STREAM_ACTIONS, 'sa');

		$this->defaultSelectAlias = 'sa';
		$qb->setDefaultSelectAlias('sa');

		return $qb;
	}

	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamActionDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_ACTIONS);

		return $qb;
	}

	/**
	 * @param array $data
	 *
	 * @return StreamAction
	 */
	public function parseStreamActionsSelectSql($data): StreamAction {
		$action = new StreamAction();
		$action->importFromDatabase($data);

		return $action;
	}
}
