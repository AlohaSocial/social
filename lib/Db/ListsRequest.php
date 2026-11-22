<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Mastodon's lists: a group of accounts, made by one account, with a timeline
 * of its own.
 *
 * Every read and write carries the owner's prim in the statement itself, so a
 * list of somebody else's is never even read; no method takes a list id
 * without an owner.
 */
class ListsRequest extends ListsRequestBuilder {
	/** The width of `social_list.title`, which is Mastodon's own. */
	public const MAX_TITLE_LENGTH = 255;

	/**
	 * A title as this app stores one, or '' for a blank title, which the
	 * controller refuses with a 422 as Mastodon does.
	 */
	public static function normaliseTitle(string $title): string {
		// Characters, not bytes: the column is VARCHAR(255).
		return mb_substr(trim($title), 0, self::MAX_TITLE_LENGTH, 'UTF-8');
	}

	public function create(MastodonList $list): MastodonList {
		$qb = $this->getListsInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($list->getOwnerId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($list->getOwnerId())))
			->setValue('title', $qb->createNamedParameter($list->getTitle()))
			->setValue('replies_policy', $qb->createNamedParameter($list->getRepliesPolicy()))
			->setValue('exclusive', $qb->createNamedParameter($list->isExclusive() ? 1 : 0))
			->setValue('group_id', $qb->createNamedParameter($list->getGroupId()))
			->setValue('visibility', $qb->createNamedParameter($list->getVisibility()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
		$list->setId($qb->getLastInsertId());
		if ($list->getCreation() === 0) {
			$list->setCreation(time());
		}

		return $list;
	}

	/**
	 * The one list the owner named.
	 *
	 * @throws ItemNotFoundException missing or somebody else's, the same answer
	 *                               as on Mastodon
	 */
	public function getOwnedById(string $actorId, int $id): MastodonList {
		$qb = $this->getListsSelectSql();
		$qb->andWhere($qb->expr()->eq('l.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('l.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ItemNotFoundException('Record not found');
		}

		return $this->parseListsSelectSql($data);
	}

	/**
	 * Every list the account owns, oldest first as in Mastodon; unpaged, as
	 * `GET /api/v1/lists` is.
	 *
	 * @return MastodonList[]
	 */
	public function getByActor(string $actorId): array {
		$qb = $this->getListsSelectSql();
		$qb->andWhere($qb->expr()->eq('l.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->orderBy('l.id', 'asc');

		$lists = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$lists[] = $this->parseListsSelectSql($data);
		}
		$cursor->closeCursor();

		return $lists;
	}

	/**
	 * Public lists, oldest first, for the pass that publishes the ones not on
	 * Bluesky yet (`BlueskyLists::publishMissing()`).
	 *
	 * @return MastodonList[]
	 */
	public function getPublic(int $limit, int $afterId = 0): array {
		$qb = $this->getListsSelectSql();
		$qb->andWhere($qb->expr()->eq('l.visibility', $qb->createNamedParameter(MastodonList::VISIBILITY_PUBLIC)));
		$qb->andWhere($qb->expr()->gt('l.id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)));
		$qb->orderBy('l.id', 'asc');
		$qb->setMaxResults($limit);

		$lists = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$lists[] = $this->parseListsSelectSql($data);
		}
		$cursor->closeCursor();

		return $lists;
	}

	/**
	 * The lists of one owner that a given account is in, for
	 * `GET /api/v1/accounts/{id}/lists`; only the owner's own lists.
	 *
	 * @return MastodonList[]
	 */
	public function getByMember(string $actorId, string $memberId): array {
		$qb = $this->getListsSelectSql();
		$expr = $qb->expr();

		$qb->innerJoin(
			'l', self::TABLE_LIST_MEMBERS, 'lm',
			$expr->andX(
				$expr->eq('lm.list_id', 'l.id'),
				$expr->eq('lm.actor_id_prim', $qb->createNamedParameter($qb->prim($memberId)))
			)
		);
		$qb->andWhere($expr->eq('l.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->orderBy('l.id', 'asc');

		$lists = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$lists[] = $this->parseListsSelectSql($data);
		}
		$cursor->closeCursor();

		return $lists;
	}

	/** The owner is part of the statement, so a foreign list is never touched. */
	/**
	 * Every list that follows a Nextcloud group, whoever owns it.
	 *
	 * @return list<MastodonList>
	 */
	public function getByGroup(string $groupId): array {
		if ($groupId === '') {
			return [];
		}

		$qb = $this->getListsSelectSql();
		$qb->andWhere($qb->expr()->eq('l.group_id', $qb->createNamedParameter($groupId)));
		$qb->orderBy('l.id', 'asc');

		$lists = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$lists[] = $this->parseListsSelectSql($data);
		}
		$cursor->closeCursor();

		return $lists;
	}

	/**
	 * The groups that have lists following them.
	 *
	 * @return list<string>
	 */
	public function getGroupIds(): array {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct('group_id')
			->from(self::TABLE_LISTS)
			->where($qb->expr()->neq('group_id', $qb->createNamedParameter('')));

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$ids[] = (string)$data['group_id'];
		}
		$cursor->closeCursor();

		return $ids;
	}

	/** @return int how many lists were retitled */
	public function updateTitleByGroup(string $groupId, string $title): int {
		if ($groupId === '') {
			return 0;
		}

		$qb = $this->getListsUpdateSql();
		$qb->set('title', $qb->createNamedParameter($title))
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));

		return $qb->executeStatement();
	}

	public function deleteByGroup(string $groupId): void {
		foreach ($this->getByGroup($groupId) as $list) {
			$this->delete($list);
		}
	}

	/**
	 * Every member of a list, as actor ids: what a reconcile diffs against.
	 *
	 * @return list<string>
	 */
	public function getMemberIds(MastodonList $list): array {
		$qb = $this->getListMembersSelectSql();
		$qb->andWhere($qb->expr()->eq('lm.list_id', $qb->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT)));

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$ids[] = $this->get('actor_id', $data);
		}
		$cursor->closeCursor();

		return $ids;
	}

	public function update(MastodonList $list): void {
		$qb = $this->getListsUpdateSql();
		$qb->set('title', $qb->createNamedParameter($list->getTitle()))
			->set('replies_policy', $qb->createNamedParameter($list->getRepliesPolicy()))
			->set('exclusive', $qb->createNamedParameter($list->isExclusive() ? 1 : 0))
			->set('visibility', $qb->createNamedParameter($list->getVisibility()));
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($list->getOwnerId())))
		);

		$qb->executeStatement();
	}

	/**
	 * Deletes the list and its membership rows, members first, so a crash leaves
	 * no rows for a later list reusing the same autoincrement id.
	 */
	public function delete(MastodonList $list): void {
		$this->deleteMembersOf($list);

		$qb = $this->getListsDeleteSql();
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($list->getOwnerId())))
		);

		$qb->executeStatement();
	}

	/**
	 * Deletes every list an account owns, and their memberships, when the
	 * account goes.
	 */
	public function deleteRelatedId(string $actorId): void {
		foreach ($this->getByActor($actorId) as $list) {
			$this->delete($list);
		}

		// The account is gone, so it leaves every other list too.
		$qb = $this->getListMembersDeleteSql();
		$qb->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->executeStatement();
	}

	private function deleteMembersOf(MastodonList $list): void {
		$qb = $this->getListMembersDeleteSql();
		$qb->where(
			$qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT))
		);

		$qb->executeStatement();
	}

	/**
	 * Idempotent: adding an account already in the list is not an error, as in
	 * Mastodon, so a retry succeeds.
	 */
	public function addMember(MastodonList $list, string $memberId): void {
		$qb = $this->getListMembersInsertSql();
		$qb->setValue('list_id', $qb->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT))
			->setValue('actor_id', $qb->createNamedParameter($memberId))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($memberId)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$this->insertUnlessDuplicate($qb);
	}

	/** Removing an account that is not in the list is not an error either. */
	public function removeMember(MastodonList $list, string $memberId): void {
		$qb = $this->getListMembersDeleteSql();
		$qb->where(
			$qb->expr()->eq('list_id', $qb->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($memberId)))
		);

		$qb->executeStatement();
	}

	public function isMember(MastodonList $list, string $memberId): bool {
		$qb = $this->getListMembersSelectSql();
		$qb->andWhere($qb->expr()->eq('lm.list_id', $qb->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('lm.actor_id_prim', $qb->createNamedParameter($qb->prim($memberId))));
		$qb->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $data !== false;
	}

	/**
	 * One page of the list's members, newest addition first, paged on the
	 * membership row id since an account can be removed and re-added. `$limit`
	 * is already bounded by the controller.
	 *
	 * @return array<array{id: int, actorId: string}>
	 */
	public function getMembers(MastodonList $list, int $limit, int|string $maxId = '0', int|string $minId = 0): array {
		$qb = $this->getListMembersSelectSql();
		$expr = $qb->expr();
		$qb->andWhere(
			$expr->eq('lm.list_id', $qb->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT))
		);

		if ($maxId > 0) {
			$qb->andWhere($expr->lt('lm.id', $qb->createNamedParameter($maxId)));
		}
		if ($minId > 0) {
			$qb->andWhere($expr->gt('lm.id', $qb->createNamedParameter($minId)));
		}

		$qb->orderBy('lm.id', 'desc');
		$qb->setMaxResults($limit);

		$members = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$members[] = $this->parseListMemberSelectSql($data);
		}
		$cursor->closeCursor();

		return $members;
	}

	/**
	 * The list's timeline: the home timeline narrowed to the list's members.
	 * Every home timeline filter applies and the membership join only removes
	 * posts, so a list never shows what the home timeline would not.
	 *
	 * @return Stream[]
	 */
	public function getTimeline(MastodonList $list, ProbeOptions $options): array {
		$nids = $this->listTimelineNids($list, $options);
		if ($nids === []) {
			return [];
		}

		$posts = $this->streamsByNids($nids, $options);
		if ($options->isInverted()) {
			// Read upwards, handed back newest first like every timeline.
			$posts = array_reverse($posts);
		}

		return $posts;
	}

	/**
	 * Which posts belong on the page, decided over one column as the home
	 * timeline does (see StreamRequest::getTimelineHome()).
	 *
	 * @return string[]
	 */
	protected function listTimelineNids(MastodonList $list, ProbeOptions $options): array {
		$page = $this->getStreamNidsSelectSql();
		$expr = $page->expr();

		// The narrowing: an inner join on (list_id, actor_id_prim), the unique index
		// of social_list_member read left to right.
		$page->innerJoin(
			's', self::TABLE_LIST_MEMBERS, 'lm',
			$expr->andX(
				$expr->eq('lm.list_id', $page->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT)),
				$expr->eq('lm.actor_id_prim', 's.attributed_to_prim')
			)
		);

		$page->filterType(SocialAppNotification::TYPE);
		$page->paginate($options);
		if ($options->isOnlyShort()) {
			$page->limitToShort();
		} elseif ($options->isOnlyVideo()) {
			$page->limitToVideo();
		} elseif ($options->isOnlyMedia()) {
			$page->limitToMedia();
		}
		// independent of the media filters, as on every other timeline
		if ($options->isOnlyNews()) {
			$page->limitToNews();
		}
		$page->limitToViewer('sd', 'f', false);
		// As on the home timeline: boosts the reader turned off are hidden.
		$page->filterHiddenBoosts();
		// A filter on the follow's type, not a join; actor columns are left out as
		// this query only selects nids.
		$this->timelineHomeLinkCacheActor($page, 'ca', 'f', false);
		$page->filterDuplicate();

		return $this->getNidsFromRequest($page);
	}

	/**
	 * The rows of a page that has already been decided, in its order; the same
	 * shape as StreamRequest's private reader.
	 *
	 * @param string[] $nids
	 *
	 * @return Stream[]
	 */
	protected function streamsByNids(array $nids, ProbeOptions $options): array {
		$qb = $this->getStreamSelectSql($options->getFormat(), false, true);
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$qb->orderBy('s.nid', $options->isInverted() ? 'asc' : 'desc');

		// The author, for the row; the page is already decided, so no follows join.
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction('sa');
		$qb->leftJoinObjectStatus();

		return $this->getStreamsFromRequest($qb);
	}
}
