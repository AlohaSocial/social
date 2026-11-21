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
 * `social_video_views` and `social_video_seen`: view counts of videos posted
 * here, counting each viewer once a day.
 *
 * `social_video_views` is one counter row per video. `social_video_seen` is
 * the day's dedupe only, unique on (post, hash), where the hash is keyed on
 * day, video and account or session, so rows cannot be linked to a person
 * or across days; `Cron\Cache` drops past days by `day`.
 */
class Version1000Date20261016103300 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_video_views')) {
			$table = $schema->createTable('social_video_views');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 20, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
			$table->addColumn('views', Types::BIGINT, ['length' => 20, 'notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id_prim'], 'social_vviews_s');
			$changed = true;
		}

		if (!$schema->hasTable('social_video_seen')) {
			$table = $schema->createTable('social_video_seen');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 20, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
			$table->addColumn('viewer_hash', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('day', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id_prim', 'viewer_hash'], 'social_vseen_sv');
			$table->addIndex(['day'], 'social_vseen_d');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
