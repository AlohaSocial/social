<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Tools\Traits\TArrayTools;

class CacheDocumentsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getCacheDocumentsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_CACHE_DOCUMENTS);

		return $qb;
	}

	/**
	 * Base of the Sql Update request
	 */
	protected function getCacheDocumentsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_DOCUMENTS);

		return $qb;
	}

	/**
	 * Base of the Sql Select request for Shares
	 */
	protected function getCacheDocumentsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		$qb->select(
			'cd.nid', 'cd.id', 'cd.type', 'cd.parent_id', 'cd.account',
			'cd.media_type', 'cd.mime_type', 'cd.url', 'cd.local_copy', 'cd.public',
			'cd.error', 'cd.creation', 'cd.caching', 'cd.resized_copy', 'cd.meta',
			'cd.blurhash', 'cd.description', 'cd.size', 'cd.ai_source',
			'cd.transcoded', 'cd.laddered'
		)
			->from(self::TABLE_CACHE_DOCUMENTS, 'cd');

		$this->defaultSelectAlias = 'cd';
		$qb->setDefaultSelectAlias('cd');

		return $qb;
	}

	/**
	 * Base of the Sql Delete request
	 */
	protected function getCacheDocumentsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CACHE_DOCUMENTS);

		return $qb;
	}

	public function parseCacheDocumentsSelectSql(array $data): Document {
		$document = new Document();
		$document->importFromDatabase($data);

		return $document;
	}
}
