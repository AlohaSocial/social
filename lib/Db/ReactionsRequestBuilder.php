<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * The queries behind `social_reaction`, in the shape the other request
 * builders of this app take.
 */
class ReactionsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getReactionsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		$qb->select('r.id', 'r.actor_id', 'r.object_id', 'r.emoji', 'r.creation')
			->from(self::TABLE_REACTIONS, 'r');

		$this->defaultSelectAlias = 'r';
		$qb->setDefaultSelectAlias('r');

		return $qb;
	}

	protected function getReactionsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_REACTIONS)
			->setDefaultSelectAlias('r');

		return $qb;
	}

	/**
	 * @throws ActionDoesNotExistException
	 */
	protected function getReactionFromRequest(SocialQueryBuilder $qb): EmojiReact {
		try {
			/** @var EmojiReact $result */
			$result = $qb->getRow([$this, 'parseReactionsSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new ActionDoesNotExistException($e->getMessage());
		}

		return $result;
	}

	/**
	 * @return EmojiReact[]
	 */
	public function getReactionsFromRequest(SocialQueryBuilder $qb): array {
		/** @var EmojiReact[] $result */
		$result = $qb->getRows([$this, 'parseReactionsSelectSql']);

		return $result;
	}

	public function parseReactionsSelectSql(array $data, SocialQueryBuilder $qb): EmojiReact {
		$reaction = new EmojiReact();
		$reaction->importFromDatabase($data);
		// the table has no `type` column, so the import leaves it empty, and
		// an `Undo` that finds the row is routed by it
		$reaction->setType(EmojiReact::TYPE);

		return $reaction;
	}
}
