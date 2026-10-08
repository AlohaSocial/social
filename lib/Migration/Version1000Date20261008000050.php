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
 * Videos on their way to Bluesky (D11): `social_atproto_video`, one row per
 * local post whose video Bluesky's video service is making into a stream,
 * with the service's job, the blob it made and how it ended. The post is
 * published to Bluesky once the row is done, with the video or as a link.
 */
class Version1000Date20261008000050 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_atproto_video')) {
			return null;
		}

		$table = $schema->createTable('social_atproto_video');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('post_id', Types::TEXT, ['notnull' => true]);
		$table->addColumn('post_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
		$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('document_id', Types::TEXT, ['notnull' => true]);
		$table->addColumn('state', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('job_id', Types::STRING, ['length' => 128, 'notnull' => true, 'default' => '']);
		$table->addColumn('blob_cid', Types::STRING, ['length' => 128, 'notnull' => true, 'default' => '']);
		$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		$table->addColumn('error', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['post_id_prim'], 'social_atpvid_post');
		$table->addIndex(['state', 'updated'], 'social_atpvid_state');

		return $schema;
	}
}
