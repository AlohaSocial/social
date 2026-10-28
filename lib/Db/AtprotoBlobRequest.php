<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Protocol\Cid;

/**
 * The blobs each repository holds: a CID naming one of the app's stored
 * documents.
 */
class AtprotoBlobRequest extends CoreRequestBuilder {
	/** @return bool whether this call first registered the account's CID */
	public function put(BlobRef $blob): bool {
		return $this->dbConnection->insertIgnoreConflict(self::TABLE_ATPROTO_BLOB, [
			'did' => $blob->did,
			'cid' => $blob->cid->toString(),
			'document_id' => $blob->documentId,
			'document_id_prim' => md5($blob->documentId),
			'mime' => $blob->mime,
			'size' => $blob->size,
			'creation' => (new DateTime('now'))->format('Y-m-d H:i:s'),
		]) > 0;
	}

	public function get(string $did, string $cid): ?BlobRef {
		$qb = $this->getQueryBuilder();
		$qb->select('did', 'cid', 'document_id', 'mime', 'size')
			->from(self::TABLE_ATPROTO_BLOB)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('cid', $qb->createNamedParameter($cid)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? null : $this->blob($row);
	}

	/**
	 * The blob a document already is in a repository, so a picture used
	 * twice is uploaded once.
	 */
	public function getByDocument(string $did, string $documentId): ?BlobRef {
		$qb = $this->getQueryBuilder();
		$qb->select('did', 'cid', 'document_id', 'mime', 'size')
			->from(self::TABLE_ATPROTO_BLOB)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('document_id_prim', $qb->createNamedParameter(md5($documentId))));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? null : $this->blob($row);
	}

	/** Whether a document is a blob in any repository, whose CID names its bytes. */
	public function hasDocument(string $documentId): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('cid')
			->from(self::TABLE_ATPROTO_BLOB)
			->where($qb->expr()->eq('document_id_prim', $qb->createNamedParameter(md5($documentId))))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row !== false;
	}

	/**
	 * @param string $cursor the CID to continue after, '' for the start
	 * @return BlobRef[] by CID, the page `listBlobs` answers
	 */
	public function list(string $did, int $limit, string $cursor = ''): array {
		$qb = $this->getQueryBuilder();
		$qb->select('did', 'cid', 'document_id', 'mime', 'size')
			->from(self::TABLE_ATPROTO_BLOB)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->orderBy('cid', 'asc')
			->setMaxResults($limit);
		if ($cursor !== '') {
			$qb->andWhere($qb->expr()->gt('cid', $qb->createNamedParameter($cursor)));
		}
		$blobs = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$blobs[] = $this->blob($row);
		}
		$result->closeCursor();

		return $blobs;
	}

	public function deleteByDid(string $did): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_BLOB)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	private function blob(array $row): BlobRef {
		return new BlobRef(
			(string)$row['did'],
			Cid::parse((string)$row['cid']),
			(string)$row['document_id'],
			(string)$row['mime'],
			(int)$row['size'],
		);
	}
}
