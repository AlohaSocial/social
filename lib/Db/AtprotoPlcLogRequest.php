<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Every PLC operation this instance makes, written before it is sent and
 * marked when the directory took it.
 */
class AtprotoPlcLogRequest extends CoreRequestBuilder {
	/**
	 * @param array $operation the signed operation, as it goes on the wire
	 * @return int the row id
	 */
	public function record(string $did, string $cid, array $operation): int {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_PLC_LOG)
			->setValue('did', $qb->createNamedParameter($did))
			->setValue('cid', $qb->createNamedParameter($cid))
			->setValue('operation', $qb->createNamedParameter((string)json_encode($operation)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	public function markSent(int $id): void {
		$this->mark($id, 'sent');
	}

	public function markConfirmed(int $id): void {
		$this->mark($id, 'confirmed');
	}

	/**
	 * A DID's operations, oldest first.
	 *
	 * @return array<int, array{id: int, did: string, cid: string, operation: array, creation: int, sent: int, confirmed: int}>
	 */
	public function getByDid(string $did): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'did', 'cid', 'operation', 'creation', 'sent', 'confirmed')
			->from(self::TABLE_ATPROTO_PLC_LOG)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->orderBy('id', 'asc');

		return $this->rows($qb);
	}

	/**
	 * Operations that were never confirmed: what a repair sends again.
	 *
	 * @return array<int, array{id: int, did: string, cid: string, operation: array, creation: int, sent: int, confirmed: int}>
	 */
	public function getUnconfirmed(int $limit = 100): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'did', 'cid', 'operation', 'creation', 'sent', 'confirmed')
			->from(self::TABLE_ATPROTO_PLC_LOG)
			->where($qb->expr()->isNull('confirmed'))
			->orderBy('id', 'asc')
			->setMaxResults($limit);

		return $this->rows($qb);
	}

	/** the latest operation's CID, the `prev` of the next one, '' for none */
	public function latestCid(string $did): string {
		$qb = $this->getQueryBuilder();
		$qb->select('cid')
			->from(self::TABLE_ATPROTO_PLC_LOG)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->orderBy('id', 'desc')
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? '' : (string)$row['cid'];
	}

	private function mark(int $id, string $column): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_PLC_LOG)
			->set($column, $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function rows(SocialQueryBuilder $qb): array {
		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$operation = json_decode((string)$row['operation'], true);
			$rows[] = [
				'id' => (int)$row['id'],
				'did' => (string)$row['did'],
				'cid' => (string)$row['cid'],
				'operation' => is_array($operation) ? $operation : [],
				'creation' => AtprotoIdentityRequest::time($row['creation']),
				'sent' => AtprotoIdentityRequest::time($row['sent']),
				'confirmed' => AtprotoIdentityRequest::time($row['confirmed']),
			];
		}
		$cursor->closeCursor();

		return $rows;
	}
}
