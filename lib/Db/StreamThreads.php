<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Nid;

/**
 * The replies to a post and the conversation under it.
 *
 * A trait used by `StreamRequest` for the reason `StreamTimelines` gives: it
 * separates the file, not the object.
 */
trait StreamThreads {
	/**
	 * @param string $id
	 * @param int $since
	 * @param int $limit
	 * @param bool $asViewer
	 *
	 * @return Stream[]
	 * @throws StreamNotFoundException
	 * @throws DateTimeException
	 */
	public function getRepliesByParentId(string $id, int $since = 0, int $limit = 5, bool $asViewer = false,
	): array {
		if ($id === '') {
			throw new StreamNotFoundException();
		};

		$qb = $this->getStreamSelectSql();
		$qb->limitToInReplyTo($id);
		$qb->limitPaginate($since, $limit);

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		if ($asViewer) {
			$qb->limitToViewer('sd', 'f', true);
			$qb->leftJoinStreamAction();
		}

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The public replies to a post, oldest first, for the `replies` collection
	 * a peer walks to discover a thread.
	 *
	 * Public only. The collection is served to anybody who asks for it, and a
	 * followers-only or direct reply is not theirs to read — not even as an id,
	 * which is enough to fetch the reply itself from the instance that holds
	 * it. This is the same audience test `getPublicByAuthor()` applies to an
	 * outbox.
	 *
	 * Oldest first, because that is the order a thread is read in and the order
	 * `OrderedCollectionPage` offsets are stable under: newest-first paging
	 * renumbers every page as soon as somebody replies again. Ordered on the
	 * nid, which is the publication time with a random suffix, so that a page
	 * can start after the last one's final reply (`$after`) rather than read
	 * and discard every reply before it.
	 *
	 * @param string $after the nid the page starts after, '' for none
	 *
	 * @return Stream[]
	 */
	public function getPublicRepliesTo(string $id, int $limit, int $offset = 0, string $after = ''): array {
		if ($id === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getStreamSelectSql();
		$qb->limitToInReplyTo($id, true);
		$qb->limitToStatusTypes();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		if ($after !== '') {
			$qb->andWhere($qb->expr()->gt('s.nid', $qb->createNamedParameter(Nid::normalize($after))));
		}

		$qb->orderBy('s.nid', 'asc');
		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * How many replies {@see self::getPublicRepliesTo()} would return: the
	 * `totalItems` of the collection, counted over the same audience so the
	 * number and the pages cannot disagree.
	 */
	public function countPublicRepliesTo(string $id): int {
		if ($id === '') {
			return 0;
		}

		$qb = $this->countNotesSelectSql();
		$qb->limitToInReplyTo($id, true);
		$qb->limitToStatusTypes();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * @param string $id
	 *
	 * @return int
	 */
	public function countRepliesTo(string $id): int {
		$qb = $this->countNotesSelectSql();
		$qb->limitToInReplyTo($id, true);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/** How much of a thread one context request returns. */
	public const MAX_DESCENDANTS = 200;

	/** How many levels below a post one context request walks. */
	public const MAX_DESCENDANT_DEPTH = 20;

	/**
	 * The whole conversation under a post: its replies, the replies to those,
	 * and so on — what `/statuses/{id}/context` calls the descendants. Used to
	 * return the direct replies only, so a thread three messages deep showed as
	 * one reply with nothing under it.
	 *
	 * Bounded in depth and in count (Mastodon bounds the same walk), filtered
	 * for the viewer at every level, and returned depth first with siblings
	 * oldest first, so each reply follows what it answers.
	 *
	 * @return Stream[]
	 */
	public function getDescendants(string $id): array {
		$byParent = [];
		$parents = [$id];
		$count = 0;
		for ($depth = 0; $depth < self::MAX_DESCENDANT_DEPTH && $parents !== []; $depth++) {
			$remaining = self::MAX_DESCENDANTS - $count;
			if ($remaining <= 0) {
				break;
			}

			$level = $this->getRepliesTo($parents, $remaining);
			$count += count($level);
			$parents = [];
			foreach ($level as $reply) {
				$byParent[$reply->getInReplyTo()][] = $reply;
				$parents[] = $reply->getId();
			}
		}

		$thread = [];
		$this->flattenThread($id, $byParent, $thread);
		// a row was only ever selected as the child of a row above it, so this is
		// empty unless a stored in_reply_to differs from its parent's id in spelling
		foreach ($byParent as $unplaced) {
			array_push($thread, ...$unplaced);
		}

		return $thread;
	}

	/**
	 * @param array<string, Stream[]> $byParent
	 * @param Stream[] $thread
	 */
	private function flattenThread(string $parent, array &$byParent, array &$thread): void {
		foreach ($byParent[$parent] ?? [] as $reply) {
			$thread[] = $reply;
			$this->flattenThread($reply->getId(), $byParent, $thread);
		}
		unset($byParent[$parent]);
	}

	/**
	 * One level of a thread: the direct replies to a set of posts, as the
	 * viewer may see them, oldest first.
	 *
	 * @param string[] $ids
	 *
	 * @return Stream[]
	 */
	protected function getRepliesTo(array $ids, int $limit): array {
		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);

		$qb->filterType(SocialAppNotification::TYPE);
		// direct messages included, as `getStreamById()` and the ancestor walk
		// both include them: a DM's recipient row is keyed on the viewer's own
		// id, so without this the descendants of a direct thread could never
		// match and a client was handed the ancestors and nothing else
		$qb->limitToViewer('sd', 'f', true, true);
		$qb->limitToDBFieldArray(
			'in_reply_to_prim',
			array_map(static fn (string $id): string => $qb->prim($id), $ids)
		);
		// a thread is read by anyone, logged in or not, and every row comes
		// back fully hydrated: the context of a post that thousands replied to
		// is not something to hand to PHP whole
		$qb->setMaxResults($limit);
		$qb->orderBy('s.published_time', 'asc');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The boosts of a post and the replies to it, each with its author joined
	 * in: the actors a Delete of the post has to reach beyond the author's own
	 * followers, because each of them carried the post to followers of theirs.
	 *
	 * @return Stream[]
	 */
	public function getAnnouncesAndRepliesTo(string $id, int $limit = 500): array {
		$qb = $this->getStreamSelectSql();
		$prim = $qb->prim($id);
		if ($prim === '') {
			return [];
		}

		$expr = $qb->expr();
		$qb->andWhere(
			$expr->orX(
				$expr->andX(
					$qb->exprLimitToDBField('type', Announce::TYPE),
					$qb->exprLimitToDBField('object_id_prim', $prim)
				),
				$qb->exprLimitToDBField('in_reply_to_prim', $prim)
			)
		);
		$qb->setMaxResults($limit);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		return $this->getStreamsFromRequest($qb);
	}
}
