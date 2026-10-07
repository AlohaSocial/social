<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\RecordMapper;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Tools\Nid;
use OCP\IDBConnection;

class OutboundQueue {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IdentityService $identities,
	) {
	}
	public function queuePost(string $nid): void {
		$this->queue($nid, 'publish');
	}
	public function queueDelete(string $nid): void {
		$this->queue($nid, 'delete');
	}
	private function queue(string $nid, string $action): void {
		if (!$this->identities->isEnabled()) {
			return;
		} $nid = Nid::normalize($nid);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('social_atproto_outbox')->where($qb->expr()->eq('post_nid', $qb->createNamedParameter($nid)));
		$id = $qb->executeQuery()->fetchOne();
		$qb = $this->db->getQueryBuilder();
		if ($id !== false) {
			$qb->update('social_atproto_outbox')->set('action', $qb->createNamedParameter($action))->set('next_try', $qb->createNamedParameter(time()))->set('attempts', $qb->createNamedParameter(0))->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
		} else {
			$qb->insert('social_atproto_outbox')->values(['post_nid' => $qb->createNamedParameter($nid), 'action' => $qb->createNamedParameter($action), 'next_try' => $qb->createNamedParameter(time())])->executeStatement();
		}
	}
}
