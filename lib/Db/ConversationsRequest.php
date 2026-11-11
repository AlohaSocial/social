<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The storage a conversation needs, which is less than a conversation.
 *
 * A thread is not stored: it is the transitive closure of `in_reply_to` over
 * `social_stream`, and the two walks below are how it is read — up, to find
 * the root a message belongs to, and down, to find the messages of a thread
 * one account may see. Both are bounded in depth, both select four columns,
 * and neither does a statement per message: the walk down does one per
 * level, the walk up one per `ANCESTOR_LEVELS` levels.
 *
 * What is stored is what an account has *done* with a thread, in
 * `social_convo_state`: how far it has read it, and how far it has dismissed
 * it. Both are kept as the nid of the newest message the action covered, not
 * as a flag, so that a message arriving afterwards undoes neither — which is
 * Mastodon's behaviour on both counts: a new message makes a conversation
 * unread again, and brings a deleted one back.
 */
class ConversationsRequest extends ConversationsRequestBuilder {
	/**
	 * How many levels of `in_reply_to` a thread walk follows, in either
	 * direction. `StreamRequest::MAX_DESCENDANT_DEPTH` bounds the same walk at
	 * the same number, and Mastodon bounds its own thread walk too: without a
	 * bound, a cycle in `in_reply_to` — which a remote server can create — is
	 * an endless loop holding a database connection.
	 */
	public const MAX_THREAD_DEPTH = 20;

	/**
	 * How many levels of `in_reply_to` one statement of a walk up reads
	 * (`getAncestorLinks()`): the twenty levels of `MAX_THREAD_DEPTH` in four.
	 */
	public const ANCESTOR_LEVELS = 5;

	/** How many posts one level of a thread walk may return. */
	public const MAX_THREAD_WIDTH = 200;

	/**
	 * The posts the given ids name, as their place in a thread.
	 *
	 * Keyed by ActivityPub id, so a caller that asked for ids it could not
	 * find gets fewer rows back and can tell which are missing — a parent this
	 * instance does not store is the end of the walk upwards, and is how a
	 * thread that started elsewhere gets a root here at all.
	 *
	 * @param string[] $ids
	 *
	 * @return array<string, array{id: string, idPrim: string, nid: int, inReplyTo: string}>
	 */
	public function getThreadLinks(array $ids): array {
		$prims = $this->prims($ids);
		if ($prims === []) {
			return [];
		}

		$qb = $this->getThreadLinkSelectSql();
		$qb->andWhere(
			$qb->expr()->in(
				's.id_prim',
				$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			)
		);
		$qb->setMaxResults(count($prims));

		return $this->threadLinksFromRequest($qb);
	}

	/**
	 * The posts the given ids name and up to `$levels - 1` of their ancestors
	 * each, in one statement: the post joined to its parent, the parent to its
	 * own, `$levels` deep. What `getThreadLinks()` answers, `$levels` levels of
	 * a walk up at a time, and with each the root its thread has stored
	 * (`rootPrim`, '' where none is), past which a walk need not go.
	 *
	 * @param string[] $ids
	 *
	 * @return array<string, array{id: string, idPrim: string, nid: int|string, inReplyTo: string, rootPrim: string}>
	 */
	public function getAncestorLinks(array $ids, int $levels): array {
		$prims = $this->prims($ids);
		if ($prims === [] || $levels < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->from(self::TABLE_STREAM, 'a0');
		for ($level = 0; $level < $levels; $level++) {
			$alias = 'a' . $level;
			if ($level > 0) {
				$child = 'a' . ($level - 1);
				// a parent that is no address has no prim, and must not match
				// the posts that have none either
				$qb->leftJoin(
					$child, self::TABLE_STREAM, $alias,
					$expr->andX(
						$expr->eq($alias . '.id_prim', $child . '.in_reply_to_prim'),
						$expr->neq($child . '.in_reply_to_prim', $qb->createNamedParameter(''))
					)
				);
			}
			$qb->selectAlias($alias . '.id', $alias . '_id')
				->selectAlias($alias . '.id_prim', $alias . '_id_prim')
				->selectAlias($alias . '.nid', $alias . '_nid')
				->selectAlias($alias . '.in_reply_to', $alias . '_in_reply_to')
				->selectAlias($alias . '.root_prim', $alias . '_root_prim');
		}
		$qb->where(
			$expr->in('a0.id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
		);

		$links = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			for ($level = 0; $level < $levels; $level++) {
				$prefix = 'a' . $level . '_';
				if (($data[$prefix . 'id'] ?? null) === null) {
					break;
				}

				$link = $this->parseThreadLinkSelectSql([
					'id' => $data[$prefix . 'id'],
					'id_prim' => $data[$prefix . 'id_prim'],
					'nid' => $data[$prefix . 'nid'],
					'in_reply_to' => $data[$prefix . 'in_reply_to'],
				]);
				$link['rootPrim'] = (string)($data[$prefix . 'root_prim'] ?? '');
				$links[$link['id']] = $link;
			}
		}
		$cursor->closeCursor();

		return $links;
	}

	/**
	 * The root of each given post's thread as it was stored with the post
	 * (`storeRoot()`), keyed by the post's id. A post whose root was never
	 * stored, or whose root is no longer here, is left out, and its caller
	 * walks up instead.
	 *
	 * @param string[] $ids
	 *
	 * @return array<string, array{id: string, idPrim: string, nid: int|string, inReplyTo: string}>
	 */
	public function getStoredRoots(array $ids): array {
		$prims = $this->prims($ids);
		if ($prims === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->selectAlias('s.id', 'post_id')
			->selectAlias('r.id', 'id')
			->selectAlias('r.id_prim', 'id_prim')
			->selectAlias('r.nid', 'nid')
			->selectAlias('r.in_reply_to', 'in_reply_to')
			->from(self::TABLE_STREAM, 's')
			->innerJoin('s', self::TABLE_STREAM, 'r', $expr->eq('r.id_prim', 's.root_prim'))
			->where($expr->in('s.id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));

		$roots = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$roots[(string)$data['post_id']] = $this->parseThreadLinkSelectSql($data);
		}
		$cursor->closeCursor();

		return $roots;
	}

	/**
	 * Who wrote each of the given posts, keyed by the post's id. A post not
	 * stored here is left out.
	 *
	 * @param string[] $ids
	 *
	 * @return array<string, string> post id => author id
	 */
	public function getAuthorsOf(array $ids): array {
		$prims = $this->prims($ids);
		if ($prims === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('s.id', 's.attributed_to')
			->from(self::TABLE_STREAM, 's')
			->where($qb->expr()->in('s.id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(count($prims));

		$authors = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$authors[(string)$data['id']] = (string)($data['attributed_to'] ?? '');
		}
		$cursor->closeCursor();

		return $authors;
	}

	/**
	 * Stores the root of the thread a direct message belongs to: its parent's
	 * stored root, else the topmost post the walk up reaches, else itself.
	 * The replies that arrived before it were their own roots, and they and
	 * the replies under them are moved under this one, so a thread keeps one
	 * root whatever order its messages came in.
	 *
	 * Bounded like `rootOf()`: a chain deeper than its walk that was stored
	 * before the roots were is rooted where the walk stopped.
	 */
	public function storeRoot(string $id, string $inReplyTo, int $maxDepth = 40): void {
		$prim = $this->prims([$id])[0] ?? '';
		if ($prim === '') {
			return;
		}

		$root = $this->rootAbove($inReplyTo, $maxDepth) ?? $prim;

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STREAM)
			->set('root_prim', $qb->createNamedParameter($root))
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($prim)));
		$qb->executeStatement();

		$qb = $this->getQueryBuilder();
		$qb->select('id_prim')
			->from(self::TABLE_STREAM)
			->where($qb->expr()->eq('in_reply_to_prim', $qb->createNamedParameter($prim)))
			->andWhere($qb->expr()->eq('root_prim', 'id_prim'))
			->setMaxResults(self::MAX_THREAD_WIDTH);
		$cursor = $qb->executeQuery();
		$orphans = array_map(static fn (array $row): string => (string)$row['id_prim'], $cursor->fetchAll());
		$cursor->closeCursor();
		if ($orphans === []) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STREAM)
			->set('root_prim', $qb->createNamedParameter($root))
			->where($qb->expr()->in('root_prim', $qb->createNamedParameter($orphans, IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
	}

	/**
	 * The prim of the root above `$parentId`: the first stored root on the
	 * way up, or the topmost post this instance holds; null when the parent
	 * is not here at all.
	 */
	private function rootAbove(string $parentId, int $maxDepth): ?string {
		$current = $parentId;
		$topmost = null;
		for ($depth = 0; $depth < $maxDepth;) {
			$levels = min(self::ANCESTOR_LEVELS, $maxDepth - $depth);
			$links = $this->getAncestorLinks([$current], $levels);
			for ($level = 0; $level < $levels; $level++, $depth++) {
				$link = $links[$current] ?? null;
				if ($link === null) {
					return $topmost;
				}
				if ($link['rootPrim'] !== '') {
					return $link['rootPrim'];
				}

				$topmost = $link['idPrim'];
				if ($link['inReplyTo'] === '') {
					return $topmost;
				}
				$current = $link['inReplyTo'];
			}
		}

		return $topmost;
	}

	/**
	 * Stores the roots of the direct messages that were stored without one,
	 * reading the stream from after `$after` in nid order, `$chunk` posts at
	 * a time. Oldest first, so a parent has its root before its replies ask
	 * for it. A post that fails is logged and passed over.
	 *
	 * @return array{read: int, last: string} how many posts were read, and the nid of the last
	 */
	public function backfillRoots(string $after, int $chunk): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$dm = 'EXISTS (SELECT 1 FROM ' . $qb->getTableName(self::TABLE_STREAM_DEST) . ' d'
			. ' WHERE d.stream_id = s.id_prim AND d.type = ' . (string)$qb->createNamedParameter('dm') . ')';
		$qb->select('s.nid', 's.id', 's.in_reply_to', 's.root_prim')
			->selectAlias($qb->createFunction('CASE WHEN ' . $dm . ' THEN 1 ELSE 0 END'), 'dm')
			->from(self::TABLE_STREAM, 's')
			->where($expr->gt('s.nid', $qb->createNamedParameter($after)))
			->orderBy('s.nid', 'asc')
			->setMaxResults($chunk);

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		$last = '0';
		foreach ($rows as $row) {
			$last = (string)$row['nid'];
			if ((int)$row['dm'] !== 1 || (string)($row['root_prim'] ?? '') !== '') {
				continue;
			}

			try {
				$this->storeRoot((string)$row['id'], (string)($row['in_reply_to'] ?? ''));
			} catch (\Throwable $e) {
				$this->logger->warning('could not store the thread root of a direct message', [
					'exception' => $e, 'nid' => $last,
				]);
			}
		}

		return ['read' => count($rows), 'last' => $last];
	}

	/**
	 * Every post of the thread under `$rootId` that is a direct message of
	 * `$actorId`, the root itself included.
	 *
	 * An empty answer is the whole of the access check on a conversation: an
	 * account that is not a `dm` dest of any post of the thread has no
	 * conversation there, and the routes that take a conversation id answer
	 * 404 on it — a conversation that is not there and one that is somebody
	 * else's are the same answer, as they are on Mastodon.
	 *
	 * @return array<string, array{id: string, idPrim: string, nid: int, inReplyTo: string}>
	 */
	public function getThreadFor(string $actorId, string $rootId): array {
		$thread = $this->getThread($rootId);
		if ($thread === []) {
			return [];
		}

		return $this->getDirectPostsById($actorId, array_keys($thread));
	}

	/**
	 * The thread under `$rootId`, root included, as its posts' places in it.
	 *
	 * Structure only, and for nobody in particular: the ids and nids of the
	 * posts, which the caller then intersects with what one account may see.
	 * Filtering each level for the reader instead would end the walk at the
	 * first post they are not a recipient of, and an account that joined a
	 * thread halfway down is a recipient of nothing above where it joined.
	 *
	 * @return array<string, array{id: string, idPrim: string, nid: int, inReplyTo: string}>
	 */
	public function getThread(string $rootId): array {
		$thread = $this->getThreadLinks([$rootId]);
		// the posts that stored this root, newest first and however deep; the
		// walk below adds those stored before the roots were
		foreach ($this->getRootedAt($rootId, self::MAX_THREAD_WIDTH) as $id => $link) {
			$thread[$id] ??= $link;
		}
		$parents = array_keys($thread);

		for ($depth = 0; $depth < self::MAX_THREAD_DEPTH && $parents !== []; $depth++) {
			$remaining = self::MAX_THREAD_WIDTH - count($thread);
			if ($remaining <= 0) {
				break;
			}

			$level = $this->getRepliesTo($parents, $remaining);

			$parents = [];
			foreach ($level as $id => $link) {
				if (array_key_exists($id, $thread)) {
					// a cycle in in_reply_to costs one wasted level, not a walk
					// that never ends
					continue;
				}

				$thread[$id] = $link;
				$parents[] = $id;
			}
		}

		return $thread;
	}

	/**
	 * The posts whose stored root is `$rootId`, newest first.
	 *
	 * @return array<string, array{id: string, idPrim: string, nid: int|string, inReplyTo: string}>
	 */
	protected function getRootedAt(string $rootId, int $limit): array {
		$prims = $this->prims([$rootId]);
		if ($prims === []) {
			return [];
		}

		$qb = $this->getThreadLinkSelectSql();
		$qb->andWhere($qb->expr()->eq('s.root_prim', $qb->createNamedParameter($prims[0])));
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults($limit);

		return $this->threadLinksFromRequest($qb);
	}

	/**
	 * How far each of the given threads has been read and dismissed by one
	 * account, keyed by the thread root's id.
	 *
	 * A thread with no row has never been read or dismissed, and is left out
	 * rather than returned as zeroes: the caller defaults, and there is then
	 * one place that decides what "never read" means.
	 *
	 * @param string[] $rootIds
	 *
	 * @return array<string, array{rootId: string, readNid: int, hiddenNid: int}>
	 */
	public function getMarkers(string $actorId, array $rootIds): array {
		$prims = $this->prims($rootIds);
		if ($prims === []) {
			return [];
		}

		$qb = $this->getConversationStateSelectSql();
		$qb->andWhere($qb->expr()->eq('cs.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere(
				$qb->expr()->in(
					'cs.root_id_prim',
					$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);
		$qb->setMaxResults(count($prims));

		$markers = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$marker = $this->parseConversationStateSelectSql($data);
			$markers[$marker['rootId']] = $marker;
		}
		$cursor->closeCursor();

		return $markers;
	}

	/** How far the account has read the thread, as the nid of a message. */
	public function markRead(string $actorId, string $rootId, int|string $nid): void {
		$this->raiseMarker('read_nid', $actorId, $rootId, $nid);
	}

	/** How far the account has dismissed the thread. */
	public function markHidden(string $actorId, string $rootId, int|string $nid): void {
		$this->raiseMarker('hidden_nid', $actorId, $rootId, $nid);
	}

	/**
	 * Whether this account wants to hear about this thread.
	 *
	 * Mastodon's conversation mute, which stops the **notifications** a thread
	 * produces and leaves its posts on the timelines — muting a conversation
	 * is saying "stop telling me", not "hide this".
	 */
	public function setMuted(string $actorId, string $rootId, bool $muted): void {
		// the status entity's `muted` is read through a memo of these rows;
		// dropped first, because the update below returns early
		StreamRequestBuilder::forgetMutedRoots();

		if ($this->getMarkers($actorId, [$rootId]) !== []) {
			$this->updateMuted($actorId, $rootId, $muted);

			return;
		}

		$qb = $this->getConversationStateInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($actorId))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('root_id', $qb->createNamedParameter($rootId))
			->setValue('root_id_prim', $qb->createNamedParameter($qb->prim($rootId)))
			->setValue('muted', $qb->createNamedParameter($muted, IQueryBuilder::PARAM_BOOL))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if (!$this->insertUnlessDuplicate($qb)) {
			// the row was written between the read above and the insert
			$this->updateMuted($actorId, $rootId, $muted);
		}
	}

	/**
	 * The threads this account has muted.
	 *
	 * @return string[] root ids
	 */
	public function getMutedRoots(string $actorId, int $limit = 200): array {
		$qb = $this->getConversationStateSelectSql();
		$qb->andWhere($qb->expr()->eq('cs.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('cs.muted', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->setMaxResults(max(1, $limit));

		$roots = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$roots[] = (string)$data['root_id'];
		}
		$cursor->closeCursor();

		return $roots;
	}

	/** Whether this account has muted the thread under that root. */
	public function isMuted(string $actorId, string $rootId): bool {
		return in_array($rootId, $this->getMutedRoots($actorId), true);
	}

	protected function updateMuted(string $actorId, string $rootId, bool $muted): void {
		$qb = $this->getConversationStateUpdateSql();
		$qb->set('muted', $qb->createNamedParameter($muted, IQueryBuilder::PARAM_BOOL));
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))),
			$qb->expr()->eq('root_id_prim', $qb->createNamedParameter($qb->prim($rootId)))
		);

		$qb->executeStatement();
	}

	/**
	 * The root of the thread a post belongs to, walking `in_reply_to` up.
	 *
	 * A post that replies to nothing is its own root, and so is one whose
	 * parent this instance does not hold — which is the ordinary case for a
	 * reply that arrived before the post it answers. Bounded, because a
	 * malformed chain must not walk for ever. A direct message's thread
	 * answers with the root stored with it (`storeRoot()`).
	 */
	public function rootOf(string $statusId, int $maxDepth = 40): string {
		$current = $statusId;
		for ($depth = 0; $depth < $maxDepth;) {
			$levels = min(self::ANCESTOR_LEVELS, $maxDepth - $depth);
			$links = $this->getAncestorLinks([$current], $levels);
			for ($level = 0; $level < $levels; $level++, $depth++) {
				$link = $links[$current] ?? null;
				if ($link !== null && $link['rootPrim'] !== '') {
					// a direct message's thread, whose root was stored with it
					$stored = $this->getStoredRoots([$current])[$current] ?? null;
					if ($stored !== null) {
						return $stored['id'];
					}
				}
				if ($link === null || $link['inReplyTo'] === '') {
					return $current;
				}

				$current = $link['inReplyTo'];
			}
		}

		return $current;
	}

	/**
	 * The account is gone, so what it had read and dismissed goes with it:
	 * nobody else may read these rows, and the actor id they are keyed by can
	 * be handed to another actor by a later Move.
	 */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getConversationStateDeleteSql();
		$qb->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}

	/**
	 * Moves one marker forward, and only forward.
	 *
	 * `WHERE … < :nid` is what keeps it monotonic: two clients marking the
	 * same conversation read at the same time, or a retry of a request that
	 * was already applied, cannot move a marker back over a message the user
	 * has seen.
	 */
	private function raiseMarker(string $column, string $actorId, string $rootId, int|string $nid): void {
		if ($this->getMarkers($actorId, [$rootId]) !== []) {
			$this->updateMarker($column, $actorId, $rootId, $nid);

			return;
		}

		if (!$this->insertMarker($column, $actorId, $rootId, $nid)) {
			// the row was written between the read above and the insert
			$this->updateMarker($column, $actorId, $rootId, $nid);
		}
	}

	private function updateMarker(string $column, string $actorId, string $rootId, int|string $nid): void {
		$qb = $this->getConversationStateUpdateSql();
		$qb->set($column, $qb->createNamedParameter($nid));
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))),
			$qb->expr()->eq('root_id_prim', $qb->createNamedParameter($qb->prim($rootId))),
			$qb->expr()->lt($column, $qb->createNamedParameter($nid))
		);

		$qb->executeStatement();
	}

	/**
	 * @return bool whether the row was inserted; false when it is there already
	 * @throws DBException
	 */
	private function insertMarker(string $column, string $actorId, string $rootId, int|string $nid): bool {
		$qb = $this->getConversationStateInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($actorId))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('root_id', $qb->createNamedParameter($rootId))
			->setValue('root_id_prim', $qb->createNamedParameter($qb->prim($rootId)))
			->setValue($column, $qb->createNamedParameter($nid))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		return $this->insertUnlessDuplicate($qb);
	}

	/**
	 * The posts of `$ids` that are direct messages of the account.
	 *
	 * @param string[] $ids
	 *
	 * @return array<string, array{id: string, idPrim: string, nid: int, inReplyTo: string}>
	 */
	protected function getDirectPostsById(string $actorId, array $ids): array {
		$prims = $this->prims($ids);
		if ($prims === []) {
			return [];
		}

		$qb = $this->getThreadLinkForDestSql($actorId);
		$qb->andWhere(
			$qb->expr()->in(
				's.id_prim',
				$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			)
		);
		$qb->setMaxResults(count($prims));

		return $this->threadLinksFromRequest($qb);
	}

	/**
	 * One level down a thread: the replies to a set of posts.
	 *
	 * @param string[] $ids
	 *
	 * @return array<string, array{id: string, idPrim: string, nid: int, inReplyTo: string}>
	 */
	protected function getRepliesTo(array $ids, int $limit): array {
		$prims = $this->prims($ids);
		if ($prims === [] || $limit < 1) {
			return [];
		}

		$qb = $this->getThreadLinkSelectSql();
		$qb->andWhere(
			$qb->expr()->in(
				's.in_reply_to_prim',
				$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			)
		);
		$qb->setMaxResults($limit);

		return $this->threadLinksFromRequest($qb);
	}

	/**
	 * @return array<string, array{id: string, idPrim: string, nid: int, inReplyTo: string}>
	 */
	protected function threadLinksFromRequest(SocialQueryBuilder $qb): array {
		$links = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$link = $this->parseThreadLinkSelectSql($data);
			$links[$link['id']] = $link;
		}
		$cursor->closeCursor();

		return $links;
	}

	/**
	 * The md5s the id columns are indexed by, without blanks and without
	 * repeats: the walks above are fed their own output, which repeats.
	 *
	 * @param string[] $ids
	 *
	 * @return string[]
	 */
	private function prims(array $ids): array {
		$prims = [];
		foreach ($ids as $id) {
			// the same rule SocialCoreQueryBuilder::prim() applies: anything
			// that is not a URL has no prim and can match no row
			if (!is_string($id) || strpos($id, 'http') !== 0) {
				continue;
			}

			$prim = md5($id);
			$prims[$prim] = $prim;
		}

		return array_values($prims);
	}
}
