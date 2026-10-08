<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The attachment copies a post stores and the `social_stream_media` links
 * from this instance's own posts to the documents they carry.
 *
 * A trait used by `StreamRequest` for the reason `StreamTimelines` gives: it
 * separates the file, not the object.
 */
trait StreamMedia {
	/**
	 * The posts that carry a stored attachment copy, a page at a time.
	 *
	 * A post keeps its own copy of its attachments (`save()` writes
	 * `asLocal()` into the `attachments` column), so anything that changes a
	 * *document* after the fact -- a poster frame made for a video that was
	 * stored before posters existed -- never reaches the post that shows it.
	 * `occ social:media:posters` walks these and rewrites the copies.
	 *
	 * Only the id and the blob are read: rebuilding a whole `Stream` with its
	 * joins, for every post with a picture on the instance, would be a great
	 * deal of work to reach one column.
	 *
	 * @return array<array{nid: string, id: string, attachments: string}>
	 */
	public function getStoredAttachmentCopies(int $limit, int|string $after = '0'): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('nid', 'id', 'attachments')
			->from(self::TABLE_STREAM)
			->andWhere($expr->neq('attachments', $qb->createNamedParameter('')))
			->andWhere($expr->neq('attachments', $qb->createNamedParameter('[]')))
			->andWhere($expr->gt('nid', $qb->createNamedParameter($after)))
			->orderBy('nid', 'asc')
			->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'nid' => (string)$data['nid'],
				'id' => (string)$data['id'],
				'attachments' => (string)$data['attachments'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * Whether one of `$actorId`'s posts carries the upload with this nid in
	 * its stored attachment copies. Narrowed by the author first, which is
	 * indexed, before the copies are matched.
	 */
	public function carriesUpload(string $actorId, string $nid): bool {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('nid')
			->from(self::TABLE_STREAM)
			->setMaxResults(1);
		$qb->limitToAttributedTo($actorId, true);
		$qb->andWhere($expr->like('attachments', $qb->createNamedParameter(
			'%"id":"' . $this->dbConnection->escapeLikeParameter($nid) . '"%'
		)));

		$cursor = $qb->executeQuery();
		$found = $cursor->fetch() !== false;
		$cursor->closeCursor();

		return $found;
	}

	/** Replaces one post's stored attachment copies with the JSON given. */
	public function setStoredAttachmentCopies(string $id, string $attachments): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attachments', $qb->createNamedParameter($attachments));
		$qb->limitToIdPrim($qb->prim($id));

		$qb->executeStatement();

		// a rewrite that swaps a document — an archive restoring a picture
		// as a new upload — moves the post's links with it; one that only
		// rebuilds the same documents' copies leaves them alone
		$nids = self::documentNidsOf($attachments);
		if ($nids !== $this->linkedMedia($id)) {
			$this->linkMedia($id, $nids, true);
		}
	}

	/**
	 * Remote posts whose original ActivityPub object may still contain media
	 * even though the attachment column was written empty. The JSON is checked
	 * by the repair command; this bounded query only finds candidates.
	 *
	 * @return array<array{nid: string, id: string, source: string, subtype: string}>
	 */
	public function getMissingRemoteAttachments(int $limit, int|string $after = '0'): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('nid', 'id', 'source', 'subtype')
			->from(self::TABLE_STREAM)
			->where($expr->eq('local', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->eq('attachments', $qb->createNamedParameter('[]')))
			->andWhere($expr->gt('nid', $qb->createNamedParameter($after)))
			->orderBy('nid', 'asc')
			->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'nid' => (string)$data['nid'],
				'id' => (string)$data['id'],
				'source' => (string)$data['source'],
				'subtype' => (string)$data['subtype'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/** Keep a concurrent import's attachments and update the Photos/Video index together. */
	public function setRecoveredRemoteAttachments(string $id, string $attachments, string $subtype): bool {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attachments', $qb->createNamedParameter($attachments))
			->set('media_kind', $qb->createNamedParameter(Stream::mediaKindOf($attachments, $subtype)))
			->where($qb->expr()->eq('attachments', $qb->createNamedParameter('[]')));
		$qb->limitToIdPrim($qb->prim($id));

		return $qb->executeStatement() > 0;
	}

	public function updateAttachments(Document $document): void {
		$qb = $this->getStreamSelectSql();
		$qb->limitToIdPrim($qb->prim($document->getParentId()));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return;
		}

		$new = $this->updateAttachmentInList($document, $this->getArray('attachments', $data, []));
		$qb = $this->getStreamUpdateSql();
		$qb->set('attachments', $qb->createNamedParameter(json_encode($new, JSON_UNESCAPED_SLASHES)));
		$qb->limitToIdPrim($qb->prim($document->getParentId()));

		$qb->executeStatement();
	}

	/**
	 * Rebuilds the attachment copy of $document on every post of this
	 * instance that carries it.
	 *
	 * An upload is not tied to its post by `parent_id` the way a fetched
	 * attachment is — it is uploaded before the post exists — so the posts
	 * are found through `social_stream_media`, which links each of this
	 * instance's own posts to the documents it carries, by the document's
	 * nid. Only this instance's own posts: a remote post names the origin's
	 * file, not ours.
	 *
	 * @return int how many posts were rewritten
	 */
	public function updateLocalAttachmentCopies(Document $document): int {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('s.id', 's.attachments')
			->from(self::TABLE_STREAM_MEDIA, 'sm')
			->innerJoin('sm', self::TABLE_STREAM, 's', $expr->eq('s.id_prim', 'sm.stream_id_prim'))
			->where($expr->eq('sm.doc_nid', $qb->createNamedParameter((int)$document->getNid(), IQueryBuilder::PARAM_INT)))
			->andWhere($expr->eq('s.local', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = $data;
		}
		$cursor->closeCursor();

		$rewritten = 0;
		foreach ($rows as $data) {
			$stored = json_decode((string)$data['attachments'], true);
			if (!is_array($stored)) {
				continue;
			}

			$new = $this->updateAttachmentInList($document, $stored);
			if ($new === $stored) {
				continue;
			}

			$this->setStoredAttachmentCopies(
				(string)$data['id'], json_encode($new, JSON_UNESCAPED_SLASHES)
			);
			$rewritten++;
		}

		return $rewritten;
	}

	/**
	 * The documents a list of stored attachment copies names, by nid: the
	 * `id` of each copy, which for an attachment this instance cached is the
	 * nid of its `social_cache_doc` row.
	 *
	 * @return int[] each once, in ascending order
	 */
	public static function documentNidsOf(string $attachments): array {
		$copies = json_decode($attachments, true);
		if (!is_array($copies)) {
			return [];
		}

		return self::nidsFrom(array_map(
			static fn ($copy): string => is_array($copy) ? (string)($copy['id'] ?? '') : '', $copies
		));
	}

	/**
	 * @param string[] $ids attachment ids
	 * @return int[] those that are a nid, each once, in ascending order
	 */
	private static function nidsFrom(array $ids): array {
		$nids = [];
		foreach ($ids as $id) {
			if ($id !== '' && ctype_digit($id)) {
				$nids[] = (int)$id;
			}
		}

		$nids = array_values(array_unique($nids));
		sort($nids);

		return $nids;
	}

	/**
	 * Links one of this instance's own posts to the documents it carries; see
	 * `updateLocalAttachmentCopies()`. A remote post is not linked.
	 *
	 * @param bool $replace whether the post already has links that an edit
	 *                      may have made wrong
	 */
	private function linkLocalMedia(Stream $stream, bool $replace): void {
		if (!$stream instanceof Note || !$stream->isLocal()) {
			return;
		}

		$ids = array_map(
			static fn (MediaAttachment $attachment): string => $attachment->getId(), $stream->getAttachments()
		);
		$this->linkMedia($stream->getId(), self::nidsFrom($ids), $replace);
	}

	/**
	 * @param int[] $nids
	 */
	private function linkMedia(string $streamId, array $nids, bool $replace): void {
		$prim = $this->getQueryBuilder()->prim($streamId);
		if ($prim === '') {
			return;
		}

		if ($replace) {
			$qb = $this->getQueryBuilder();
			$qb->delete(self::TABLE_STREAM_MEDIA)
				->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($prim)));
			$qb->executeStatement();
		}

		// asked to skip a row that is already there rather than to fail on
		// it: this runs inside the transaction that stores the post, and
		// PostgreSQL aborts a transaction on any failed statement
		foreach ($nids as $nid) {
			$this->dbConnection->insertIgnoreConflict(
				self::TABLE_STREAM_MEDIA,
				['stream_id_prim' => $prim, 'doc_nid' => $nid]
			);
		}
	}

	/**
	 * @return int[] the documents a post is linked to, in ascending order
	 */
	private function linkedMedia(string $streamId): array {
		$prim = $this->getQueryBuilder()->prim($streamId);
		if ($prim === '') {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('doc_nid')
			->from(self::TABLE_STREAM_MEDIA)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($prim)))
			->orderBy('doc_nid', 'asc');

		$nids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$nids[] = (int)$data['doc_nid'];
		}
		$cursor->closeCursor();

		return $nids;
	}

	/**
	 * The post's stored attachment copies with the one for $document rebuilt
	 * from it.
	 *
	 * The copies are the client format `save()` writes, keyed by the
	 * document's nid, not cache rows: read back as `Document`s they matched
	 * nothing, and the whole list was written out again in the ActivityPub
	 * shape, without ids, previews or alt text. The picture the cron had just
	 * fetched kept its empty link, and every other picture on the post lost
	 * its preview with it. The copies that are not this document's are kept
	 * exactly as stored.
	 *
	 * @return array<mixed>
	 */
	private function updateAttachmentInList(Document $document, array $attachments): array {
		$nid = (string)$document->getNid();

		$new = [];
		foreach ($attachments as $attachment) {
			if (is_array($attachment) && (string)($attachment['id'] ?? '') === $nid) {
				$new[] = $document->convertToMediaAttachment($this->urlGenerator)->asLocal();
			} else {
				$new[] = $attachment;
			}
		}

		return $new;
	}
}
