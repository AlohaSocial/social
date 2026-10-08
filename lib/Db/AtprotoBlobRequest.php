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
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The blobs each repository holds: a CID naming one of the app's stored
 * documents.
 */
class AtprotoBlobRequest extends CoreRequestBuilder {
	public function put(BlobRef $blob): void {
		if ($this->get($blob->did, $blob->cid->toString()) !== null) {
			return;
		}
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_BLOB)
			->setValue('did', $qb->createNamedParameter($blob->did))
			->setValue('cid', $qb->createNamedParameter($blob->cid->toString()))
			->setValue('document_id', $qb->createNamedParameter($blob->documentId))
			->setValue('document_id_prim', $qb->createNamedParameter(md5($blob->documentId)))
			->setValue('mime', $qb->createNamedParameter($blob->mime))
			->setValue('size', $qb->createNamedParameter($blob->size, IQueryBuilder::PARAM_INT))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
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
