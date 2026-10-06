<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\Client\StatusRevision;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class StatusRevisionsRequestBuilder
 *
 * Extends CoreRequestBuilder and not StreamRequestBuilder: a revision is a
 * row hanging off a status by its prim and is never read through a stream
 * query — the history route has the status in hand before it asks for one.
 *
 * @package OCA\Social\Db
 */
class StatusRevisionsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getStatusRevisionsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STATUS_REVISIONS);

		return $qb;
	}

	protected function getStatusRevisionsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('sr.id', 'sr.stream_id_prim', 'sr.content', 'sr.spoiler_text', 'sr.sensitive', 'sr.published', 'sr.media')
			->from(self::TABLE_STATUS_REVISIONS, 'sr');

		$this->defaultSelectAlias = 'sr';
		$qb->setDefaultSelectAlias('sr');

		return $qb;
	}

	protected function getStatusRevisionsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STATUS_REVISIONS);

		return $qb;
	}

	protected function parseStatusRevisionsSelectSql(array $data): StatusRevision {
		return (new StatusRevision())->importFromDatabase($data);
	}
}
