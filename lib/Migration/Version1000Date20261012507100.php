<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Hangs the stored profile banners (`social_cache_doc` rows under
 * `/documents/header/`) off the local actor whose handle they name, so
 * deleting the account removes them by parent. A banner whose handle no
 * actor has is left alone; run again, it finds nothing to place.
 */
class Version1000Date20261012507100 extends SimpleMigrationStep {
	/** How many banners one statement looks at. */
	private const BATCH = 500;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$documents = '*PREFIX*' . CoreRequestBuilder::TABLE_CACHE_DOCUMENTS;
		$actors = '*PREFIX*' . CoreRequestBuilder::TABLE_ACTORS;

		$after = 0;
		$placed = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `cd`.`nid`, `a`.`id` AS `actor_id` FROM `' . $documents . '` `cd`'
				. ' INNER JOIN `' . $actors . '` `a` ON `a`.`preferred_username` = `cd`.`account`'
				. ' WHERE `cd`.`nid` > ? AND `cd`.`id` LIKE ? AND `cd`.`account` <> ?'
				. ' AND (`cd`.`parent_id_prim` IS NULL OR `cd`.`parent_id_prim` = ?)'
				. ' ORDER BY `cd`.`nid` ASC LIMIT ' . self::BATCH,
				[(string)$after, '%/documents/header/%', '', '']
			)->fetchAll();

			if ($page === []) {
				break;
			}

			foreach ($page as $row) {
				$after = max($after, (int)$row['nid']);
				$actorId = (string)$row['actor_id'];
				$this->connection->executeStatement(
					'UPDATE `' . $documents . '` SET `parent_id` = ?, `parent_id_prim` = ? WHERE `nid` = ?',
					[$actorId, md5($actorId), (string)$row['nid']]
				);
				$placed++;
			}
		}

		if ($placed > 0) {
			$output->info('hung ' . $placed . ' profile banner(s) off their account');
		}
	}
}
