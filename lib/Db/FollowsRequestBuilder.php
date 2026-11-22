<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;

class FollowsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getFollowsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FOLLOWS);

		return $qb;
	}

	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getFollowsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_FOLLOWS);

		return $qb;
	}

	/**
	 * Base of the Sql Select request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getFollowsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'f.id', 'f.id_prim', 'f.type', 'f.actor_id', 'f.object_id', 'f.follow_id', 'f.accepted', 'f.creation'
		)
			->from(self::TABLE_FOLLOWS, 'f');

		$this->defaultSelectAlias = 'f';
		$qb->setDefaultSelectAlias('f');

		return $qb;
	}

	/**
	 * Base of the Sql count request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function countFollowsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_FOLLOWS, 'f');

		$qb->setDefaultSelectAlias('f');
		$this->defaultSelectAlias = 'f';

		return $qb;
	}

	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getFollowsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FOLLOWS);

		return $qb;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Follow
	 * @throws FollowNotFoundException
	 */
	protected function getFollowFromRequest(SocialQueryBuilder $qb): Follow {
		/** @var Follow $result */
		try {
			$result = $qb->getRow([$this, 'parseFollowsSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new FollowNotFoundException($e->getMessage());
		}

		return $result;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Follow[]
	 */
	public function getFollowsFromRequest(SocialQueryBuilder $qb): array {
		/** @var Follow[] $result */
		$result = $qb->getRows([$this, 'parseFollowsSelectSql']);

		return $result;
	}

	/**
	 * @param array $data
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Follow
	 */
	public function parseFollowsSelectSql(array $data, SocialQueryBuilder $qb): Follow {
		$follow = new Follow();
		$follow->importFromDatabase($data);

		try {
			$actor = $qb->parseLeftJoinCacheActors($data, 'ca_');
			$actor->setCompleteDetails(true);
			$this->assignDetails($actor, $data);

			$follow->setCompleteDetails(true);
			$follow->setActor($actor);

			return $follow;
		} catch (InvalidResourceException $e) {
			// no cached actor on this row — the local accounts table is the
			// other place it can come from
		}

		try {
			$actor = $this->parseAccountsLeftJoin($data);
			$follow->setActor($actor);
		} catch (InvalidResourceException $e) {
			// no actor from either join: the follow is returned without one, since
			// most callers read only its own columns
		}

		return $follow;
	}
}
