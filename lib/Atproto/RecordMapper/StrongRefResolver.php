<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\RecordMapper;

use OCA\Social\Atproto\Repository\Record;
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\Details;
use OCP\IDBConnection;

/** Resolve a shared Social post to its native strong reference without publishing private content. */
class StrongRefResolver {
	public function __construct(
		private readonly StreamRequest $streams,
		private readonly IDBConnection $db,
	) {
	}
	public function resolve(string $id): ?array {
		if ($id === '') {
			return null;
		}
		try {
			$post = $this->streams->getStreamById($id);
		} catch (StreamNotFoundException) {
			return null;
		}
		if (($post->getDetails(Details::PUBLICATION)['atproto'] ?? true) === false) {
			return null;
		}
		if ($post->getVisibility() !== 'public' || !$post->addressesPublic()) {
			return null;
		}
		$native = $post->getDetails(Details::ATPROTO);
		if (isset($native['uri'], $native['cid'])) {
			return ['ref' => ['uri' => $native['uri'], 'cid' => $native['cid']], 'root' => $native['reply']['root'] ?? ['uri' => $native['uri'], 'cid' => $native['cid']]];
		}
		if (!$post->isLocal()) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('social_atpds_record')->where($qb->expr()->eq('local_id', $qb->createNamedParameter((string)$post->getNid())))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter('app.bsky.feed.post')));
		$row = $qb->executeQuery()->fetchAssociative();
		if (!$row) {
			throw new \RuntimeException('Parent publication pending; retry required');
		}
		$value = Record::decodeCbor(Repository::bytes($row['bytes']));
		$ref = ['uri' => 'at://' . $row['did'] . '/' . $row['collection'] . '/' . $row['rkey'], 'cid' => $row['cid']];
		return ['ref' => $ref, 'root' => $value['reply']['root'] ?? $ref];
	}
}
