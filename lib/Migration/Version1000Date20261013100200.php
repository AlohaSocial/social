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
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_stream_act.liked_nid` and `bookmarked_nid`: when a post was
 * favourited or bookmarked, as a sortable id, so `/api/v1/favourites` and
 * `/api/v1/bookmarks` page by the mark, newest first, as Mastodon does. Minted
 * when the flag is set (`StreamActionService`), zero when cleared, on the
 * same scale as a post's nid.
 *
 * Existing marks get their post's id; run again, it finds no set mark
 * without an id.
 */
class Version1000Date20261013100200 extends SimpleMigrationStep {
	/** How many action rows one backfill statement looks at. */
	private const BATCH = 1000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STREAM_ACTIONS)) {
			return null;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM_ACTIONS);
		$changed = false;

		foreach (['liked_nid', 'bookmarked_nid'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::BIGINT, ['default' => 0, 'length' => 20, 'notnull' => true, 'unsigned' => true]);
				$changed = true;
			}
		}

		// the two reads there are: one viewer's set marks, newest mark first
		if (!$table->hasIndex('social_sa_aln')) {
			$table->addIndex(['actor_id_prim', 'liked', 'liked_nid'], 'social_sa_aln');
			$changed = true;
		}
		if (!$table->hasIndex('social_sa_abn')) {
			$table->addIndex(['actor_id_prim', 'bookmarked', 'bookmarked_nid'], 'social_sa_abn');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	/**
	 * Gives the marks already stored the id of their post.
	 *
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$actions = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM_ACTIONS;
		$stream = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		$after = 0;
		$done = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `sa`.`id`, `sa`.`liked`, `sa`.`bookmarked`, `sa`.`liked_nid`, `sa`.`bookmarked_nid`, `s`.`nid`'
				. ' FROM `' . $actions . '` `sa`'
				. ' INNER JOIN `' . $stream . '` `s` ON `s`.`id_prim` = `sa`.`stream_id_prim`'
				. ' WHERE `sa`.`id` > ? AND ((`sa`.`liked` = ? AND `sa`.`liked_nid` = 0)'
				. ' OR (`sa`.`bookmarked` = ? AND `sa`.`bookmarked_nid` = 0))'
				. ' ORDER BY `sa`.`id` ASC LIMIT ' . self::BATCH,
				[(string)$after, '1', '1']
			)->fetchAll();

			if ($page === []) {
				break;
			}

			foreach ($page as $row) {
				$after = max($after, (int)$row['id']);
				$nid = (string)$row['nid'];
				$this->connection->executeStatement(
					'UPDATE `' . $actions . '` SET `liked_nid` = ?, `bookmarked_nid` = ? WHERE `id` = ?',
					[
						$this->flag($row['liked']) ? $nid : (string)$row['liked_nid'],
						$this->flag($row['bookmarked']) ? $nid : (string)$row['bookmarked_nid'],
						(int)$row['id'],
					]
				);
				$done++;
			}
		}

		if ($done > 0) {
			$output->info('dated ' . $done . ' favourite(s) and bookmark(s)');
		}
	}

	/** A flag column as each database hands it back: `t`, `1`, `true`, or an int. */
	private function flag(mixed $value): bool {
		return in_array($value, [true, 1, '1', 't', 'true'], true);
	}
}
