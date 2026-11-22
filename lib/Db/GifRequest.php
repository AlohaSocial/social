<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Gif;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The instance's own GIF library. Small, so one reader returns the whole set
 * and searching happens above.
 */
class GifRequest extends CoreRequestBuilder {
	/**
	 * Stores one, replacing whatever the slug named before (insert, update on
	 * conflict), as an admin re-adding a name means to replace it.
	 */
	public function save(Gif $gif): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_GIFS)
			->setValue('slug', $qb->createNamedParameter($gif->getSlug()))
			->setValue('title', $qb->createNamedParameter($gif->getTitle()))
			->setValue('filename', $qb->createNamedParameter($gif->getFilename()))
			->setValue('media_type', $qb->createNamedParameter($gif->getMediaType()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if ($this->insertUnlessDuplicate($qb)) {
			return;
		}

		$update = $this->getQueryBuilder();
		$update->update(self::TABLE_GIFS)
			->set('title', $update->createNamedParameter($gif->getTitle()))
			->set('filename', $update->createNamedParameter($gif->getFilename()))
			->set('media_type', $update->createNamedParameter($gif->getMediaType()))
			->where($update->expr()->eq('slug', $update->createNamedParameter($gif->getSlug())));

		$update->executeStatement();
	}

	/**
	 * The whole library, newest first.
	 *
	 * @return Gif[]
	 */
	public function all(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'slug', 'title', 'filename', 'media_type')
			->from(self::TABLE_GIFS)
			->orderBy('id', 'desc');

		$gifs = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$gifs[] = new Gif(
				(string)$data['slug'],
				(string)$data['filename'],
				(string)$data['media_type'],
				(string)($data['title'] ?? ''),
				(int)$data['id']
			);
		}
		$cursor->closeCursor();

		return $gifs;
	}

	/**
	 * Removes one.
	 *
	 * @return string the filename that was stored, or '' when there was no
	 *                such slug — so the caller knows which bytes to delete
	 */
	public function delete(string $slug): string {
		$qb = $this->getQueryBuilder();
		$qb->select('filename')
			->from(self::TABLE_GIFS)
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return '';
		}

		$remove = $this->getQueryBuilder();
		$remove->delete(self::TABLE_GIFS)
			->where($remove->expr()->eq('slug', $remove->createNamedParameter($slug)));
		$remove->executeStatement();

		return (string)$data['filename'];
	}
}
