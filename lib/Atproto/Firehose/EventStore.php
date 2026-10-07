<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Firehose;

use OCA\Social\Atproto\Protocol\DagCbor;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Sequence allocation is serialized until the enclosing publication commits. */
class EventStore {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}
	public function initialize(): void {
		$qb = $this->db->getQueryBuilder();
		if ($qb->select('id')->from('social_atpds_event_clock')->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne() !== false) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$last = (int)$qb->select($qb->func()->max('seq'))->from('social_atpds_event')->executeQuery()->fetchOne();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atpds_event_clock')->values(['id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT), 'last_seq' => $qb->createNamedParameter($last, IQueryBuilder::PARAM_INT)])->executeStatement();
	}
	public function latestSequence(): int {
		$qb = $this->db->getQueryBuilder();
		$value = $qb->select('last_seq')->from('social_atpds_event_clock')->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
		if ($value === false) {
			throw new \RuntimeException('ATProto event clock is not initialized; run the app upgrade');
		}
		return (int)$value;
	}
	public function append(string $did, string $kind, array $body): int {
		$this->db->beginTransaction();
		try {
			// UPDATE acquires the same row lock on every writer, including different DIDs.
			// Keep it until the outer transaction commits; auto-increment alone cannot
			// guarantee commit order on PostgreSQL/MySQL and can make relays skip events.
			$qb = $this->db->getQueryBuilder();
			$changed = $qb->update('social_atpds_event_clock')->set('last_seq', $qb->createFunction('last_seq + 1'))->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))->executeStatement();
			if ($changed !== 1) {
				throw new \RuntimeException('ATProto event clock is not initialized');
			}
			$seq = $this->latestSequence();
			$qb = $this->db->getQueryBuilder();
			$qb->insert('social_atpds_event')->values(['seq' => $qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT), 'did' => $qb->createNamedParameter($did), 'kind' => $qb->createNamedParameter($kind),
				'bytes' => $qb->createNamedParameter(DagCbor::encode($body), IQueryBuilder::PARAM_LOB), 'time' => $qb->createNamedParameter(gmdate('Y-m-d H:i:s'))])->executeStatement();
			$this->db->commit();
			return $seq;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
