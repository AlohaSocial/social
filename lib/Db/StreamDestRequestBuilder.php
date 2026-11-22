<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\StreamDestDoesNotExistException;
use OCA\Social\Model\StreamDest;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;

class StreamDestRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Base of the Sql Insert request
	 */
	protected function getStreamDestInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM_DEST);

		return $qb;
	}

	/**
	 * Base of the Sql Select request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamDestSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		// `sd.id` is what `getRelatedToActor()` pages on; nothing else reads it
		$qb->select('sd.id', 'sd.actor_id', 'sd.stream_id', 'sd.type', 'sd.subtype')
			->from(self::TABLE_STREAM_DEST, 'sd');

		$this->defaultSelectAlias = 'sd';
		$qb->setDefaultSelectAlias('sd');

		return $qb;
	}

	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamDestDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_DEST);

		return $qb;
	}

	/**
	 * Base of the Sql Count request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function countStreamDestSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_STREAM_DEST, 'sd');

		$this->defaultSelectAlias = 'sd';
		$qb->setDefaultSelectAlias('sd');

		return $qb;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return StreamDest
	 * @throws StreamDestDoesNotExistException
	 */
	public function getStreamDestFromRequest(SocialQueryBuilder $qb): StreamDest {
		/** @var StreamDest $result */
		try {
			$result = $qb->getRow([$this, 'parseStreamDestSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new StreamDestDoesNotExistException();
		}

		return $result;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return StreamDest[]
	 */
	public function getStreamDestsFromRequest(SocialQueryBuilder $qb): array {
		return $qb->getRows([$this, 'parseStreamDestSelectSql']);
	}

	/**
	 * @param array $data
	 *
	 * @return StreamDest
	 */
	public function parseStreamDestSelectSql(array $data): StreamDest {
		$streamDest = new StreamDest();
		$streamDest->importFromDatabase($data);

		return $streamDest;
	}
}
