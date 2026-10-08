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
 * The administrator's Bluesky block list: PDS hosts and DIDs.
 */
class AtprotoBlocklistRequest extends CoreRequestBuilder {
	public const KIND_HOST = 'host';
	public const KIND_DID = 'did';

	/**
	 * @return bool whether a row was added; false when it was there already
	 */
	public function add(string $kind, string $value, string $reason): bool {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_BLOCKLIST)
			->setValue('kind', $qb->createNamedParameter($kind))
			->setValue('value', $qb->createNamedParameter($value))
			->setValue('reason', $qb->createNamedParameter($reason))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}

		return true;
	}

	/**
	 * @return bool whether a row was removed
	 */
	public function remove(string $kind, string $value): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_BLOCKLIST)
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('value', $qb->createNamedParameter($value)));

		return $qb->executeStatement() > 0;
	}

	/**
	 * @return list<array{kind: string, value: string, reason: string, creation: int}>
	 */
	public function getAll(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('kind', 'value', 'reason', 'creation')->from(self::TABLE_ATPROTO_BLOCKLIST)->orderBy('kind')->addOrderBy('value');
		$rows = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$rows[] = [
				'kind' => (string)$row['kind'],
				'value' => (string)$row['value'],
				'reason' => (string)$row['reason'],
				'creation' => AtprotoIdentityRequest::time($row['creation']),
			];
		}
		$result->closeCursor();

		return $rows;
	}
}
