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
 * The Bluesky labelers each person subscribes to, with their choice per
 * label value.
 */
class AtprotoLabelerRequest extends CoreRequestBuilder {
	/**
	 * @return array<string, array<string, string>> labeler DID → label value → setting
	 */
	public function getByUser(string $userId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('did', 'settings')->from(self::TABLE_ATPROTO_LABELER)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('creation', 'asc');
		$labelers = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$settings = json_decode((string)$row['settings'], true);
			$labelers[(string)$row['did']] = is_array($settings) ? array_filter($settings, 'is_string') : [];
		}
		$result->closeCursor();

		return $labelers;
	}

	/**
	 * Every labeler somebody here subscribes to, the most subscribed first.
	 *
	 * @return string[]
	 */
	public function getSubscribedDids(int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('did')->selectAlias($qb->func()->count('id'), 'n')
			->from(self::TABLE_ATPROTO_LABELER)
			->groupBy('did')
			->orderBy('n', 'desc')
			->setMaxResults($limit);
		$dids = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$dids[] = (string)$row['did'];
		}
		$result->closeCursor();

		return $dids;
	}

	/**
	 * @return bool whether a row was added
	 */
	public function subscribe(string $userId, string $did): bool {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_LABELER)
			->setValue('user_id', $qb->createNamedParameter($userId))
			->setValue('did', $qb->createNamedParameter($did))
			->setValue('settings', $qb->createNamedParameter('{}'))
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

	public function unsubscribe(string $userId, string $did): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_LABELER)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('did', $qb->createNamedParameter($did)));

		return $qb->executeStatement() > 0;
	}

	/**
	 * @param array<string, string> $settings label value → setting
	 */
	public function setSettings(string $userId, string $did, array $settings): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_LABELER)
			->set('settings', $qb->createNamedParameter((string)json_encode((object)$settings)))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	public function deleteByUser(string $userId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_LABELER)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
