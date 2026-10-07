<?php

declare(strict_types=1);

namespace OCA\Social\Atproto;

use OCP\IDBConnection;

class AuthorWatchService {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}
	public function watch(string $did, string $handle): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('social_atproto_watch')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		if ($qb->executeQuery()->fetchOne() !== false) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$values = [];
		foreach (['did' => $did, 'handle' => $handle, 'next_sync' => gmdate('Y-m-d H:i:s'), 'failures' => 0] as $key => $value) {
			$values[$key] = $qb->createNamedParameter($value);
		}
		$qb->insert('social_atproto_watch')->values($values)->executeStatement();
	}
}
