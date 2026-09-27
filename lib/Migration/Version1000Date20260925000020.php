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
 * The two tables of My interests.
 *
 * `social_interest` is one row per (reader, hashtag): the score reading earned
 * it and when, whether the reader added it themselves, and the rank they
 * pinned it to. `social_interest_hide` is the posts they said "less like this"
 * about, kept out of their feed for as long as the feed could show them.
 *
 * Numbered after master's latest step rather than by the day it was written:
 * `Version1000Date20260924000001` was minted on two branches at once — it
 * creates the subscription tables on master and these two here — and a
 * version identifier is what `oc_migrations` records. An instance that ran
 * either one would have silently skipped the other for good.
 *
 * A step of its own rather than a paragraph of the squash, because the squash
 * is already recorded as run on every instance that exists: a table added to
 * it would appear on fresh installs and nowhere else. Guarded like the squash,
 * so running it twice asks for nothing the second time.
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
