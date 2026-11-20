<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The two tables of For you. `social_interest` is one row per (reader,
 * hashtag): the score reading earned and when, whether the reader added it,
 * and the rank they pinned it to. `social_interest_hide` is the posts they
 * said "less like this" about, kept out of their feed while it could show
 * them.
 *
 * Its own version id, not `Version1000Date20260924000001`, which another
 * step already uses, and not part of the squash, which existing instances
 * have recorded as run. Guarded, so a second run asks for nothing.
 */
class Version1000Date20260925000020 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_interest')) {
			$table = $schema->createTable('social_interest');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('hashtag', Types::STRING, ['length' => 127, 'notnull' => true]);
			$table->addColumn('score', Types::FLOAT, ['default' => 0, 'notnull' => true]);
			$table->addColumn('scored_at', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => true]);
			$table->addColumn('manual', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->addColumn('position', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('score_week', Types::FLOAT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'hashtag'], 'social_in_ah');
			$changed = true;
		}

		if (!$schema->hasTable('social_interest_hide')) {
			$table = $schema->createTable('social_interest_hide');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('stream_nid', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'stream_nid'], 'social_inh_as');
			$table->addIndex(['creation'], 'social_inh_c');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
