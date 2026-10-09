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
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Mastodon's lists: a group of accounts, made by one account, with a timeline
 * of its own.
 *
 * A list belongs to exactly one account, and every read and every write here
 * carries the owner's prim as a predicate of the statement itself rather than
 * checking it after the row has been read. That is the difference between a
 * list somebody else cannot *see* and a list somebody else cannot *reach*: a
 * `WHERE id = ?` that is filtered afterwards has already handed the row to the
 * wrong caller by the time the check runs, and one forgotten `if` anywhere
 * above would be enough. There is no method here that takes a list id without
 * an owner.
 */
class ListsRequest extends ListsRequestBuilder {
	/** The width of `social_list.title`, which is Mastodon's own. */
	public const MAX_TITLE_LENGTH = 255;

	/**
	 * A title as this app stores one, from whatever the client sent.
	 *
	 * Returns '' for a title that is not one — Mastodon answers "Title can't
	 * be blank" with a 422, and so does the controller, rather than storing a
	 * list the user cannot tell apart from any other blank one.
	 */
	public static function normaliseTitle(string $title): string {
		// characters, not bytes: the column is VARCHAR(255), and cutting a
		// multi-byte title with substr() would store half a character
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
	 * @throws ItemNotFoundException a list that is not there and a list that
	 *                               is somebody else's are the same answer, as
	 *                               they are on Mastodon: telling the two
	 *                               apart would say whether an id exists
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
	 * Every list the account owns, oldest first — the order Mastodon returns
	 * them in, and the order a client draws its sidebar in.
	 *
	 * Not paged: `GET /api/v1/lists` takes no cursor on Mastodon either, and
	 * an account has as many lists as it made by hand.
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
	 * The lists of one owner that a given account is in — what
	 * `GET /api/v1/accounts/{id}/lists` answers.
	 *
	 * Scoped to the asking account's own lists: which lists a stranger has put
	 * somebody in is not something either of them may read.
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
	 * Deletes the list and, with it, its membership rows.
	 *
	 * The members go first: a crash between the two statements then leaves
	 * rows belonging to a list that still exists, which is harmless, rather
	 * than rows belonging to a list that does not — those would be invisible
	 * to every route here and would be handed to whichever list next took the
	 * same autoincrement id.
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
	 * Every list an account owns, and their memberships.
	 *
	 * A list is private to whoever made it, so it has nobody to outlive: this
	 * is what makes the lists go when the account does.
	 */
	public function deleteRelatedId(string $actorId): void {
		foreach ($this->getByActor($actorId) as $list) {
			$this->delete($list);
		}

		// membership of somebody else's list: the account is gone, so it is
		// not in anybody's list any more either
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
	 * Idempotent: adding an account that is already in the list changes
	 * nothing and is not an error. Mastodon answers `{}` to both, and a client
	 * that lost the answer and retried must not get a failure for a request
	 * that succeeded.
	 */
	public function addMember(MastodonList $list, string $memberId): void {
		$qb = $this->getListMembersInsertSql();
		$qb->setValue('list_id', $qb->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT))
			->setValue('actor_id', $qb->createNamedParameter($memberId))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($memberId)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
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
	 * membership row id — an account can be removed from a list and added
	 * again, so its own id does not move in one direction and cannot page.
	 *
	 * `$limit` arrives already bounded — Mastodon's documented `limit=0`,
	 * "all accounts without pagination", is turned into a real ceiling by the
	 * controller, because the page is built in memory and one request may not
	 * be made to read an unbounded number of rows.
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
	 *
	 * Narrowed, not widened. Every filter the home timeline applies applies
	 * here unchanged — the viewer's visibility, blocks, mutes, hidden actors
	 * and duplicate boosts — and the membership join can only take posts away,
	 * so a list can never show its owner a post that their home timeline would
	 * not have shown them. That is the property that matters: a list is a view
	 * of what you already follow, not a second way of reading somebody.
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
			// the page was asked for upwards and read upwards; it is handed
			// back newest first, as every other timeline is
			$posts = array_reverse($posts);
		}

		return $posts;
	}

	/**
	 * Which posts belong on the page, decided over one column, as the home
	 * timeline decides its own — see StreamRequest::getTimelineHome(), which
	 * explains why the page and the rows are two queries.
	 *
	 * @return string[]
	 */
	protected function listTimelineNids(MastodonList $list, ProbeOptions $options): array {
		$page = $this->getStreamNidsSelectSql();
		$expr = $page->expr();

		// the narrowing, and the only thing here the home timeline does not do:
		// an inner join on (list_id, actor_id_prim), which is the unique index
		// of social_list_member read left to right
		$page->innerJoin(
			's', self::TABLE_LIST_MEMBERS, 'lm',
			$expr->andX(
				$expr->eq('lm.list_id', $page->createNamedParameter($list->getId(), IQueryBuilder::PARAM_INT)),
				$expr->eq('lm.actor_id_prim', 's.attributed_to_prim')
			)
		);

		$page->filterType(SocialAppNotification::TYPE);
		$page->paginate($options);
		if ($options->isOnlyVideo()) {
			$page->limitToVideo();
		} elseif ($options->isOnlyMedia()) {
			$page->limitToMedia();
		}
		$page->limitToViewer('sd', 'f', false);
		// as on the home timeline: an account whose boosts the reader turned
		// off is here to be read, not to be a relay
		$page->filterHiddenBoosts();
		// a filter, not a join: it constrains on the follow's type. The actor's
		// columns are left out, as on every page-selection query — this one
		// projects nids.
		$this->timelineHomeLinkCacheActor($page, 'ca', 'f', false);
		$page->filterDuplicate();

		return $this->getNidsFromRequest($page);
	}

	/**
	 * The rows of a page that has already been decided, in its order.
	 *
	 * The same shape as StreamRequest's own reader and deliberately not a call
	 * into it: that method is private to the stream timelines, and this class
	 * does not own StreamRequest.
	 *
	 * @param string[] $nids
	 *
	 * @return Stream[]
	 */
	protected function streamsByNids(array $nids, ProbeOptions $options): array {
		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$qb->orderBy('s.nid', $options->isInverted() ? 'asc' : 'desc');

		// the author, for the row; the follows table stays out of this query
		// because the page has already decided what belongs in it
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction('sa');
		$qb->leftJoinObjectStatus();

		return $this->getStreamsFromRequest($qb);
	}
}
