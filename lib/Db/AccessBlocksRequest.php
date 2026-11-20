<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\AccessBlock;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The blocks on an address rather than an account (IPs and e-mail domains).
 * Each list is a short admin-written one read whole, so there is no lookup
 * by value; the service above caches the one read often.
 */
class AccessBlocksRequest extends CoreRequestBuilder {
	private const COLUMNS = ['id', 'type', 'value', 'severity', 'comment', 'expires', 'creation'];

	/**
	 * Stores one, replacing whatever stood against the same value, so blocking
	 * again changes the severity, comment or expiry.
	 */
	public function save(AccessBlock $block): void {
		$expires = $block->getExpires() === 0
			? null : new DateTime('@' . $block->getExpires());

		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ACCESS_BLOCKS)
			->setValue('type', $qb->createNamedParameter($block->getType()))
			->setValue('value', $qb->createNamedParameter($block->getValue()))
			->setValue('severity', $qb->createNamedParameter($block->getSeverity()))
			->setValue('comment', $qb->createNamedParameter($block->getComment()))
			->setValue('expires', $qb->createNamedParameter($expires, IQueryBuilder::PARAM_DATE))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if ($this->insertUnlessDuplicate($qb)) {
			return;
		}

		$update = $this->getQueryBuilder();
		$update->update(self::TABLE_ACCESS_BLOCKS)
			->set('severity', $update->createNamedParameter($block->getSeverity()))
			->set('comment', $update->createNamedParameter($block->getComment()))
			->set('expires', $update->createNamedParameter($expires, IQueryBuilder::PARAM_DATE))
			->where($update->expr()->eq('type', $update->createNamedParameter($block->getType())))
			->andWhere($update->expr()->eq('value', $update->createNamedParameter($block->getValue())));

		$update->executeStatement();
	}

	/**
	 * Every block of that kind, newest first.
	 *
	 * @return AccessBlock[]
	 */
	public function getByType(string $type): array {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::COLUMNS)
			->from(self::TABLE_ACCESS_BLOCKS)
			->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->orderBy('id', 'desc');

		$blocks = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$blocks[] = AccessBlock::fromRow($row);
		}
		$cursor->closeCursor();

		return $blocks;
	}

	/** @throws ItemNotFoundException */
	public function getById(int $id, string $type): AccessBlock {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::COLUMNS)
			->from(self::TABLE_ACCESS_BLOCKS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)));

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		if ($row === false) {
			throw new ItemNotFoundException('Record not found');
		}

		return AccessBlock::fromRow($row);
	}

	/** @throws ItemNotFoundException */
	public function delete(int $id, string $type): void {
		$block = $this->getById($id, $type);

		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ACCESS_BLOCKS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($block->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
