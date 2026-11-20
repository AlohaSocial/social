<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Tools\Exceptions\CacheItemNotFoundException;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

class StreamRequestBuilder extends CoreRequestBuilder {
	/** how deep a reply chain is followed to find its root; the bound ConversationsRequest::rootOf() uses */
	private const MAX_THREAD_DEPTH = 40;
	/** how many muted threads are read for a viewer; more than anybody mutes */
	private const MUTED_ROOTS_LIMIT = 500;

	/**
	 * viewer id => the roots they muted, for this request. Static, as the
	 * blocked-domains memo is, so the write that changes a mute can drop it
	 * from whichever request object it was read through.
	 *
	 * @var array<string, array<string, true>>
	 */
	private static array $mutedRoots = [];

	use TArrayTools;

	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM);

		return $qb;
	}

	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STREAM);

		return $qb;
	}

	/**
	 * The posts, every column of them, with no `DISTINCT`: over these TEXT
	 * columns it would build a temporary table of every matching row. Joins
	 * that can repeat a post are deduplicated by `getStreamsFromRequest()`,
	 * and paged reads choose their page by nid first
	 * (`getStreamNidsSelectSql()`).
	 *
	 * @param bool $narrow read without what a client-format export does not
	 *                     use (`SocialCrossQueryBuilder::setNarrowStreamRead()`);
	 *                     only for the client format, and only where the
	 *                     posts go to a client and nowhere else
	 */
	protected function getStreamSelectSql(
		int $format = Stream::FORMAT_ACTIVITYPUB,
		bool $withArchived = false,
		bool $narrow = false,
	): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->setFormat($format);
		$qb->setNarrowStreamRead($narrow && $format === ACore::FORMAT_LOCAL);

		$qb->select('s.id')
			->from(self::TABLE_STREAM, 's');
		foreach (self::$tables[self::TABLE_STREAM] as $field) {
			if ($field === 'id') {
				continue;
			}
			if ($field === 'source') {
				$qb->selectStreamSource('s', 'source');
				continue;
			}
			$qb->addSelect('s.' . $field);
		}

		$qb->setDefaultSelectAlias('s');
		$this->hideArchived($qb, $withArchived);

		return $qb;
	}

	/**
	 * Leaves archived posts out of every list this server builds. Fail-closed:
	 * a read that should show them (the author's own list, a single post by
	 * its address) asks. `$admitUnfilled` keeps the `OR … IS NULL` after the
	 * backfill, for a query whose plan depends on it.
	 */
	protected function hideArchived(SocialQueryBuilder $qb, bool $withArchived, bool $admitUnfilled = false): void {
		if ($withArchived) {
			return;
		}

		$notArchived = $qb->expr()->eq('s.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL));
		if ($this->archivedIsFilled() && !$admitUnfilled) {
			$qb->andWhere($notArchived);

			return;
		}

		// rows not yet backfilled carry NULL; the `OR` is dropped once the
		// flag says it is dead weight, since planners estimate it badly
		$qb->andWhere($qb->expr()->orX($notArchived, $qb->expr()->isNull('s.archived')));
	}

	/**
	 * The same query, projecting one column, so a page can be chosen and
	 * deduplicated on one integer and its rows fetched afterwards by id.
	 *
	 * @param bool $distinct whether the joins this page uses can return a post
	 *                       more than once.
	 *
	 * `social_stream_dest` is unique on `(stream_id, actor_id, type)`, so a
	 * query fixing both actor and type (public, notifications, direct) cannot
	 * duplicate, while the home and marked timelines can. `DISTINCT` is
	 * costly (37.4 ms against 0.21 ms on a 22,000-post public timeline), so
	 * it is only asked for where needed.
	 */
	protected function getStreamNidsSelectSql(
		bool $distinct = true,
		bool $withArchived = false,
	): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		if ($distinct) {
			$qb->selectDistinct('s.nid');
		} else {
			$qb->select('s.nid');
		}
		$qb->from(self::TABLE_STREAM, 's');
		$qb->setDefaultSelectAlias('s');
		$this->hideArchived($qb, $withArchived);

		return $qb;
	}

	/**
	 * @param SocialQueryBuilder $qb a query projecting s.nid
	 *
	 * @return string[] the ids of the page, in the order the query put them
	 */
	protected function getNidsFromRequest(SocialQueryBuilder $qb): array {
		$nids = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$nids[] = (string)$row['nid'];
		}
		$cursor->closeCursor();

		// deduplicated here too: a page without `DISTINCT` can still get a
		// repeat from the hidden-actor filter's left joins (expired mutes on
		// both booster and author); rare enough to leave a page one row short
		return array_values(array_unique($nids));
	}

	/**
	 * Base of the Sql Select request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function countNotesSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_STREAM, 's');

		$qb->setDefaultSelectAlias('s');

		return $qb;
	}

	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM);

		return $qb;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 * @param string $alias
	 * @param string $aliasFollow
	 * @param bool $select whether the actor's columns are wanted in the result;
	 *                     false for a query that is only choosing a page
	 */
	protected function timelineHomeLinkCacheActor(
		SocialQueryBuilder $qb, string $alias = 'ca', string $aliasFollow = 'f', bool $select = true,
	) {
		$qb->linkToCacheActors($alias, 's.attributed_to_prim', true, $select);

		$expr = $qb->expr();

		$follow = $expr->andX(
			$expr->eq($aliasFollow . '.type', $qb->createNamedParameter('Follow'))
		);

		$loopback = $expr->andX(
			$expr->eq($aliasFollow . '.type', $qb->createNamedParameter('Loopback')),
			$expr->eq($alias . '.id_prim', $qb->getDefaultSelectAlias() . '.attributed_to_prim')
		);

		$orX = $expr->orX($follow, $loopback);

		$qb->andWhere($orX);
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	protected function getStreamFromRequest(SocialQueryBuilder $qb): Stream {
		/** @var Stream $result */
		try {
			$result = $qb->getRow([$this, 'parseStreamSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new StreamNotFoundException('stream not found');
		}
		$this->markMutedConversations([$result]);

		return $result;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Stream[]
	 */
	public function getStreamsFromRequest(SocialQueryBuilder $qb): array {
		/** @var Stream[] $rows */
		$rows = $qb->getRows([$this, 'parseStreamSelectSql']);

		// one post once, at its first row: see getStreamSelectSql()
		$result = [];
		$seen = [];
		foreach ($rows as $stream) {
			$nid = (string)$stream->getNid();
			if (isset($seen[$nid])) {
				continue;
			}
			$seen[$nid] = true;
			$result[] = $stream;
		}
		$this->markMutedConversations($result);

		return $result;
	}

	/**
	 * The posts of a page already chosen, in the page's order, each with its
	 * author, and as the viewer acts on it when `$asViewer`.
	 *
	 * @param string[] $nids
	 *
	 * @return Stream[]
	 */
	protected function streamsInPageOrder(array $nids, int $format, bool $asViewer): array {
		if ($nids === []) {
			return [];
		}

		$qb = $this->getStreamSelectSql($format);
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		if ($asViewer) {
			$qb->leftJoinStreamAction('sa');
		}

		$order = array_flip($nids);
		$streams = array_values(array_filter(
			$this->getStreamsFromRequest($qb),
			static fn (Stream $stream): bool => isset($order[(string)$stream->getNid()])
		));
		usort(
			$streams,
			static fn (Stream $a, Stream $b): int => $order[(string)$a->getNid()] <=> $order[(string)$b->getNid()]
		);

		return $streams;
	}

	/**
	 * Marks the posts whose conversation the viewer has muted. The mute is
	 * kept against the thread's root (`social_convo_state`), so the roots of
	 * a page are walked a level at a time; nothing more is asked for a viewer
	 * who muted nothing. A notification's post is its object.
	 *
	 * @param Stream[] $streams
	 */
	protected function markMutedConversations(array $streams): void {
		$viewerId = $this->getViewerId();
		if ($viewerId === '' || $streams === []) {
			return;
		}
		$muted = $this->mutedRootsOf($viewerId);
		if ($muted === []) {
			return;
		}

		$posts = [];
		foreach ($streams as $stream) {
			$posts[] = $stream;
			$object = $stream->getObject();
			if ($object instanceof Stream) {
				$posts[] = $object;
			}
		}
		$roots = $this->rootsOf(array_map(static fn (Stream $post): string => $post->getId(), $posts));
		foreach ($posts as $post) {
			if (isset($muted[$roots[$post->getId()] ?? $post->getId()])) {
				$post->setMutedConversation(true);
			}
		}
	}

	/** Muting or unmuting a conversation makes the memo wrong; drop it. */
	public static function forgetMutedRoots(): void {
		self::$mutedRoots = [];
	}

	/**
	 * The roots the viewer muted, as a set, asked once per viewer and request.
	 *
	 * @return array<string, true>
	 */
	protected function mutedRootsOf(string $viewerId): array {
		if (isset(self::$mutedRoots[$viewerId])) {
			return self::$mutedRoots[$viewerId];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('cs.root_id')
			->from(self::TABLE_CONVERSATION_STATE, 'cs')
			->where($qb->expr()->eq('cs.actor_id_prim', $qb->createNamedParameter($qb->prim($viewerId))))
			->andWhere($qb->expr()->eq('cs.muted', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->setMaxResults(self::MUTED_ROOTS_LIMIT);

		$roots = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$roots[(string)$data['root_id']] = true;
		}
		$cursor->closeCursor();

		return self::$mutedRoots[$viewerId] = $roots;
	}

	/**
	 * The thread root of each post, by id: one query per level, at most
	 * `MAX_THREAD_DEPTH` levels. A post without a held parent is its own
	 * root, as in `ConversationsRequest::rootOf()`.
	 *
	 * @param string[] $ids
	 * @return array<string, string> id => root id
	 */
	protected function rootsOf(array $ids): array {
		$parents = [];
		$frontier = array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
		for ($depth = 0; $depth < self::MAX_THREAD_DEPTH && $frontier !== []; $depth++) {
			$qb = $this->getQueryBuilder();
			$qb->select('s.id', 's.in_reply_to')
				->from(self::TABLE_STREAM, 's')
				->where($qb->expr()->in(
					's.id_prim',
					$qb->createNamedParameter(array_map([$qb, 'prim'], $frontier), IQueryBuilder::PARAM_STR_ARRAY)
				));

			$found = [];
			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$parents[(string)$data['id']] = (string)$data['in_reply_to'];
				$found[(string)$data['id']] = true;
			}
			$cursor->closeCursor();

			$next = [];
			foreach ($frontier as $id) {
				if (!isset($found[$id])) {
					$parents[$id] = '';
				}
				$parent = $parents[$id];
				if ($parent !== '' && !isset($parents[$parent])) {
					$next[$parent] = true;
				}
			}
			$frontier = array_keys($next);
		}

		$roots = [];
		foreach ($ids as $id) {
			$current = $id;
			for ($depth = 0; $depth < self::MAX_THREAD_DEPTH; $depth++) {
				$parent = $parents[$current] ?? '';
				if ($parent === '') {
					break;
				}
				$current = $parent;
			}
			$roots[$id] = $current;
		}

		return $roots;
	}

	/**
	 * @param array $data
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Stream
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	public function parseStreamSelectSql(array $data, SocialQueryBuilder $qb): Stream {
		$as = $this->get('type', $data, Stream::TYPE);

		/** @var Stream $item */
		$item = AP::instance()->getItemFromType($as);
		$item->importFromDatabase($data);
		$item->setExportFormat($qb->getFormat());
		$item->setSourceOmitted($qb->omitsSourceOf($data));
		$instances = json_decode($this->get('instances', $data, '[]'), true);
		if (is_array($instances)) {
			foreach ($instances as $instance) {
				$instancePath = new InstancePath();
				$instancePath->import($instance);
				$item->addInstancePath($instancePath);
			}
		}

		try {
			$actor = $qb->parseLeftJoinCacheActors($data, 'ca_', $qb->getFormat());
			$actor->setExportFormat($qb->getFormat());
			$item->setCompleteDetails(true);
			$item->setActor($actor);
		} catch (InvalidResourceException $e) {
		}

		try {
			$object = $qb->parseLeftJoinStream($data, 'os_', ACore::FORMAT_LOCAL);
			$item->setObject($object);
		} catch (InvalidResourceException $e) {
		}

		$action = $this->parseStreamActionsLeftJoin($data);
		$item->setAction($action);

		if ($item->hasCache()) {
			$cache = $item->getCache();
			try {
				$cachedItem = $cache->getItem($action->getStreamId());
				$cachedObject = $cachedItem->getObject();
				$cachedObject['action'] = $action;
				$cachedItem->setContent(json_encode($cachedObject));
				$cache->updateItem($cachedItem, false);
			} catch (CacheItemNotFoundException $e) {
			}
		}

		return $item;
	}
}
