<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\CustomEmoji;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The instance's own custom emoji: tens, not thousands, and read on every post
 * that uses one, so readers fetch the whole set and the service caches it for
 * the request.
 */
class EmojiRequest extends CoreRequestBuilder {
	/**
	 * Stores one, replacing whatever the shortcode named before: an admin
	 * re-uploading under an existing name means to replace it.
	 */
	public function save(CustomEmoji $emoji): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_EMOJI)
			->setValue('shortcode', $qb->createNamedParameter($emoji->getShortcode()))
			->setValue('category', $qb->createNamedParameter($emoji->getCategory()))
			->setValue('filename', $qb->createNamedParameter($emoji->getFilename()))
			->setValue('media_type', $qb->createNamedParameter($emoji->getMediaType()))
			->setValue('visible', $qb->createNamedParameter($emoji->isVisible(), IQueryBuilder::PARAM_BOOL))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if ($this->insertUnlessDuplicate($qb)) {
			return;
		}

		$update = $this->getQueryBuilder();
		$update->update(self::TABLE_EMOJI)
			->set('category', $update->createNamedParameter($emoji->getCategory()))
			->set('filename', $update->createNamedParameter($emoji->getFilename()))
			->set('media_type', $update->createNamedParameter($emoji->getMediaType()))
			->set('visible', $update->createNamedParameter($emoji->isVisible(), IQueryBuilder::PARAM_BOOL))
			->where($update->expr()->eq(
				'shortcode', $update->createNamedParameter($emoji->getShortcode())
			));

		$update->executeStatement();
	}

	/**
	 * Every emoji this instance has, by shortcode.
	 *
	 * @return array<string, CustomEmoji>
	 */
	public function getAll(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'shortcode', 'category', 'filename', 'media_type', 'visible', 'creation')
			->from(self::TABLE_EMOJI)
			->orderBy('shortcode');

		$all = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$emoji = CustomEmoji::fromRow($row);
			$all[$emoji->getShortcode()] = $emoji;
		}
		$cursor->closeCursor();

		return $all;
	}

	/** @return CustomEmoji|null the one that shortcode names, if any */
	public function getByShortcode(string $shortcode): ?CustomEmoji {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'shortcode', 'category', 'filename', 'media_type', 'visible', 'creation')
			->from(self::TABLE_EMOJI)
			->where($qb->expr()->eq('shortcode', $qb->createNamedParameter($shortcode)));

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? null : CustomEmoji::fromRow($row);
	}

	/** @return string the appdata file it named, or '' if there was no such emoji */
	public function delete(string $shortcode): string {
		$emoji = $this->getByShortcode($shortcode);
		if ($emoji === null) {
			return '';
		}

		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_EMOJI)
			->where($qb->expr()->eq('shortcode', $qb->createNamedParameter($shortcode)));
		$qb->executeStatement();

		return $emoji->getFilename();
	}
}
