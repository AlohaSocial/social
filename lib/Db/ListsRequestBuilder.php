<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class ListsRequestBuilder
 *
 * Extends StreamRequestBuilder rather than CoreRequestBuilder because a list
 * has a timeline: the list timeline is the home timeline narrowed to the
 * list's members, and it is built out of the same stream helpers
 * (`getStreamNidsSelectSql()`, `timelineHomeLinkCacheActor()`,
 * `getStreamsFromRequest()`) rather than out of a copy of them.
 *
 * @package OCA\Social\Db
 */
class ListsRequestBuilder extends StreamRequestBuilder {
	use TArrayTools;

	protected function getListsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_LISTS);

		return $qb;
	}

	protected function getListsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('l.id', 'l.actor_id', 'l.actor_id_prim', 'l.title', 'l.replies_policy', 'l.exclusive', 'l.group_id', 'l.visibility', 'l.creation')
			->from(self::TABLE_LISTS, 'l');

		$this->defaultSelectAlias = 'l';
		$qb->setDefaultSelectAlias('l');

		return $qb;
	}

	protected function getListsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_LISTS);

		return $qb;
	}

	protected function getListsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_LISTS);

		return $qb;
	}

	protected function getListMembersInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_LIST_MEMBERS);

		return $qb;
	}

	protected function getListMembersSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('lm.id', 'lm.list_id', 'lm.actor_id', 'lm.actor_id_prim', 'lm.creation')
			->from(self::TABLE_LIST_MEMBERS, 'lm');

		$this->defaultSelectAlias = 'lm';
		$qb->setDefaultSelectAlias('lm');

		return $qb;
	}

	protected function getListMembersDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_LIST_MEMBERS);

		return $qb;
	}

	protected function parseListsSelectSql(array $data): MastodonList {
		return (new MastodonList())->importFromDatabase($data);
	}

	/**
	 * A membership row as the routes above read one: who, and the id the page
	 * cursor moves on.
	 *
	 * @return array{id: int, actorId: string}
	 */
	protected function parseListMemberSelectSql(array $data): array {
		return [
			'id' => $this->getInt('id', $data),
			'actorId' => $this->get('actor_id', $data),
		];
	}
}
