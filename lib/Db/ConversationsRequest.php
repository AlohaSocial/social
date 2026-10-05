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
 * and both do one statement per level rather than one per message.
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

		try {
			$qb = $this->getConversationStateInsertSql();
			$qb->setValue('actor_id', $qb->createNamedParameter($actorId))
				->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
				->setValue('root_id', $qb->createNamedParameter($rootId))
				->setValue('root_id_prim', $qb->createNamedParameter($qb->prim($rootId)))
				->setValue('muted', $qb->createNamedParameter($muted, IQueryBuilder::PARAM_BOOL))
				->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

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

	private function updateMuted(string $actorId, string $rootId, bool $muted): void {
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
	 * malformed chain must not walk for ever.
	 */
	public function rootOf(string $statusId, int $maxDepth = 40): string {
		$current = $statusId;
		for ($depth = 0; $depth < $maxDepth; $depth++) {
			$links = $this->getThreadLinks([$current]);
			$link = $links[$current] ?? null;
			if ($link === null || $link['inReplyTo'] === '') {
				return $current;
			}

			$current = $link['inReplyTo'];
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

		try {
			$this->insertMarker($column, $actorId, $rootId, $nid);
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

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
	 * @throws DBException
	 */
	private function insertMarker(string $column, string $actorId, string $rootId, int|string $nid): void {
		$qb = $this->getConversationStateInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($actorId))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('root_id', $qb->createNamedParameter($rootId))
			->setValue('root_id_prim', $qb->createNamedParameter($qb->prim($rootId)))
			->setValue($column, $qb->createNamedParameter($nid))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
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
