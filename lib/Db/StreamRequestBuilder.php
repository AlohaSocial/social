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

/**
 * Class StreamRequestBuilder
 *
 * @package OCA\Social\Db
 */
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
	 * Base of the Sql Select request for Shares
	 *
	 * @param int $format
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamSelectSql(
		int $format = Stream::FORMAT_ACTIVITYPUB,
		bool $withArchived = false,
	): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->setFormat($format);

		$qb->selectDistinct('s.id')
			->from(self::TABLE_STREAM, 's');
		foreach (self::$tables[self::TABLE_STREAM] as $field) {
			if ($field === 'id') {
				continue;
			}
			$qb->addSelect('s.' . $field);
		}

		$qb->setDefaultSelectAlias('s');
		$this->hideArchived($qb, $withArchived);

		return $qb;
	}

	/**
	 * A post its author has put away is out of every list this server builds,
	 * and the filter is here rather than in each of them.
	 *
	 * Fail-closed on purpose: a read that should show archived posts — the
	 * author's own list of them, and a single post fetched by its address —
	 * asks for them, and a read written later shows none until somebody
	 * decides it should. The other way round, every list written from now on
	 * would leak one until somebody noticed.
	 */
	protected function hideArchived(SocialQueryBuilder $qb, bool $withArchived): void {
		if ($withArchived) {
			return;
		}

		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->eq('s.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
				// rows written before the column existed
				$qb->expr()->isNull('s.archived')
			)
		);
	}

	/**
	 * The same query, projecting one column.
	 *
	 * A timeline reads DISTINCT over eighty columns, several of them TEXT,
	 * because joining the recipients and follows tables can return a stream
	 * more than once. The database cannot deduplicate that without building
	 * and sorting the whole matching set first, which is why a timeline used
	 * to cost the same whether twenty rows were asked for or a hundred.
	 *
	 * Deduplicating one integer instead is cheap, so the page is chosen here
	 * and the rows are fetched afterwards by id.
	 */
	/**
	 * @param bool $distinct whether the joins this page uses can return a post
	 *                       more than once.
	 *
	 * The recipient join is `social_stream_dest`, whose unique index is
	 * `(stream_id, actor_id, type)`: a query that fixes **both** the actor and
	 * the type — the public timeline, notifications, direct messages, the
	 * marked timelines — can match at most one row per post and cannot
	 * duplicate. The home timeline can: a post addressed to three accounts the
	 * viewer follows matches three rows, which is precisely what the
	 * deduplication is for.
	 *
	 * It is not free. `SELECT DISTINCT` makes the database materialise every
	 * matching row before it can take a page: measured on the public timeline
	 * of an instance with 22,000 posts, 37.4 ms with it and 0.21 ms without —
	 * for the same twenty rows, with no duplicates among them. So it is asked
	 * for where it is needed rather than always.
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

		// Deduplicated here as well as in SQL, and cheaply — this is twenty
		// integers. A page that asked for no `DISTINCT` because its recipient
		// join cannot duplicate can still be handed a repeat by one of the
		// left joins the hidden-actor filter adds: a viewer with an expired
		// timed mute on both the booster and the boosted author matches the
		// expiry table twice. That is rare enough to be worth a page one row
		// short and not worth making every other read materialise itself.
		return array_values(array_unique($nids));
	}

	/**
	 * Base of the Sql Select request for Shares
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
		/** @var Stream[] $result */
		$result = $qb->getRows([$this, 'parseStreamSelectSql']);
		$this->markMutedConversations($result);

		return $result;
	}

	/**
	 * Marks the posts whose conversation the viewer has muted.
	 *
	 * Mastodon's `muted` on a status is about the thread: the mute is kept
	 * against the thread's root (`social_convo_state`) and a post does not
	 * store its root, so the roots of a page are walked here — a level at a
	 * time, one query per level — and compared with the viewer's muted roots.
	 * Those are asked once per request and nothing more is asked for a viewer
	 * who muted nothing, which is nearly every viewer. A notification is a
	 * stream too, and the post it is about is its object.
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
	 * The thread root of each post, by id.
	 *
	 * The parent chain is followed a level at a time, every level one query
	 * for the whole page, at most `MAX_THREAD_DEPTH` levels — a malformed
	 * chain must not walk for ever. A post that replies to nothing, or whose
	 * parent this instance does not hold, is its own root, as
	 * `ConversationsRequest::rootOf()` has it for one post.
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
