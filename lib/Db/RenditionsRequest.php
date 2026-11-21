<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\VideoRendition;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The rungs of a video's ladder, read smallest first, the order the master
 * playlist wants so a player starts on the quickest.
 */
class RenditionsRequest extends CoreRequestBuilder {
	/**
	 * Writes one rung, or replaces the one that is there, so re-laddering at a
	 * different quality replaces it. The caller deletes the old file.
	 */
	public function save(VideoRendition $rendition): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_RENDITIONS)
			->setValue('doc_nid', $qb->createNamedParameter($rendition->getDocNid()))
			->setValue('height', $qb->createNamedParameter($rendition->getHeight(), IQueryBuilder::PARAM_INT))
			->setValue('bandwidth', $qb->createNamedParameter($rendition->getBandwidth(), IQueryBuilder::PARAM_INT))
			->setValue('size', $qb->createNamedParameter($rendition->getSize(), IQueryBuilder::PARAM_INT))
			->setValue('local_copy', $qb->createNamedParameter($rendition->getLocalCopy()))
			->setValue('playlist', $qb->createNamedParameter($rendition->getPlaylist()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if ($this->insertUnlessDuplicate($qb)) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_RENDITIONS)
			->set('bandwidth', $qb->createNamedParameter($rendition->getBandwidth(), IQueryBuilder::PARAM_INT))
			->set('size', $qb->createNamedParameter($rendition->getSize(), IQueryBuilder::PARAM_INT))
			->set('local_copy', $qb->createNamedParameter($rendition->getLocalCopy()))
			->set('playlist', $qb->createNamedParameter($rendition->getPlaylist()))
			->set('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('doc_nid', $qb->createNamedParameter($rendition->getDocNid())))
			->andWhere($qb->expr()->eq('height', $qb->createNamedParameter($rendition->getHeight(), IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * The rungs of one video, smallest first.
	 *
	 * @return VideoRendition[]
	 */
	public function forDocument(int|string $docNid): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'doc_nid', 'height', 'bandwidth', 'size', 'local_copy', 'playlist')
			->from(self::TABLE_RENDITIONS)
			->where($qb->expr()->eq('doc_nid', $qb->createNamedParameter($docNid)))
			->orderBy('height', 'asc');

		$renditions = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$renditions[] = $this->parse($data);
		}
		$cursor->closeCursor();

		return $renditions;
	}

	/** One rung by height, or null when that is not a rung of this video. */
	public function forHeight(int|string $docNid, int $height): ?VideoRendition {
		foreach ($this->forDocument($docNid) as $rendition) {
			if ($rendition->getHeight() === $height) {
				return $rendition;
			}
		}

		return null;
	}

	/**
	 * Forgets every rung of one video and returns the paths they named, for
	 * the caller to delete; this class does not know the file store.
	 *
	 * @return string[] the store paths the deleted rows named
	 */
	public function deleteForDocument(int|string $docNid): array {
		$orphans = array_map(
			static fn (VideoRendition $rendition): string => $rendition->getLocalCopy(),
			$this->forDocument($docNid)
		);

		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_RENDITIONS)
			->where($qb->expr()->eq('doc_nid', $qb->createNamedParameter($docNid)));
		$qb->executeStatement();

		return array_values(array_filter($orphans, static fn (string $path): bool => $path !== ''));
	}

	/** How much disk every rendition on this instance is holding. */
	public function totalSize(): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('size'), 'total')->from(self::TABLE_RENDITIONS);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function parse(array $data): VideoRendition {
		$rendition = new VideoRendition();
		$rendition->setId((int)$data['id'])
			->setDocNid((string)$data['doc_nid'])
			->setHeight((int)$data['height'])
			->setBandwidth((int)$data['bandwidth'])
			->setSize((int)$data['size'])
			->setLocalCopy((string)($data['local_copy'] ?? ''))
			->setPlaylist((string)($data['playlist'] ?? ''));

		return $rendition;
	}
}
