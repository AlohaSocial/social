<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\RecordMapper;

use OCA\Social\Atproto\Identity\IdentityService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class OutboundWorker {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IdentityService $identities,
		private readonly OutboundPublisher $publisher,
		private readonly LoggerInterface $logger,
	) {
	}
	public function run(): void {
		if (!$this->identities->isEnabled()) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('social_atpds_outbox')->where($qb->expr()->lte('next_try', $qb->createNamedParameter(time())))->orderBy('id', 'ASC')->setMaxResults(25);
		foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
			$lease = time() + 120;
			$qb = $this->db->getQueryBuilder();
			$claimed = $qb->update('social_atpds_outbox')->set('next_try', $qb->createNamedParameter($lease))->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])))->andWhere($qb->expr()->eq('next_try', $qb->createNamedParameter($row['next_try'])))->executeStatement();
			if ($claimed !== 1) {
				continue;
			}
			try {
				$row['action'] === 'delete' ? $this->publisher->deletePost($row['post_nid']) : $this->publisher->publishPost($row['post_nid']);
				$qb = $this->db->getQueryBuilder();
				$qb->delete('social_atpds_outbox')->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])))->andWhere($qb->expr()->eq('next_try', $qb->createNamedParameter($lease)))->executeStatement();
			} catch (\Throwable $e) {
				$attempts = (int)$row['attempts'] + 1;
				$qb = $this->db->getQueryBuilder();
				$qb->update('social_atpds_outbox')->set('attempts', $qb->createNamedParameter($attempts))->set('last_error', $qb->createNamedParameter(substr($e->getMessage(), 0, 255)))
					->set('next_try', $qb->createNamedParameter(time() + min(3600, 30 * 2 ** min($attempts, 7))))->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])))->andWhere($qb->expr()->eq('next_try', $qb->createNamedParameter($lease)))->executeStatement();
				$this->logger->warning('AT Protocol publishing will retry', ['exception' => $e, 'post_nid' => $row['post_nid']]);
			}
		}
	}
}
