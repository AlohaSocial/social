<?php

declare(strict_types=1);

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
