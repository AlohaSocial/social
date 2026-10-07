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
 * The two tables behind replies shown as comments in the Files app.
 *
 * `social_file_post` ties a Nextcloud file to the post it was attached to.
 * The row is written when the file is copied into an attachment, with only
 * the attachment's nid, and gets its post when that post is published; a row
 * still without one after two days is an attachment nobody posted.
 *
 * `social_file_comment` ties a reply to the comment that shows it on one
 * file, in either direction: a reply copied in as a comment, or a comment
 * the post's author wrote in Files and that went out as a reply. One row per
 * file, since a post with three pictures from Files has its replies on all
 * three.
 */
class Version1000Date20261008000001 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_file_post')) {
			$table = $schema->createTable('social_file_post');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('doc_nid', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('post_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('post_id_prim', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
			$table->addColumn('creation', Types::BIGINT, ['length' => 11, 'notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['doc_nid'], 'social_fpost_doc');
			$table->addIndex(['post_id_prim'], 'social_fpost_post');
			$table->addIndex(['file_id'], 'social_fpost_file');
			$changed = true;
		}

		if (!$schema->hasTable('social_file_comment')) {
			$table = $schema->createTable('social_file_comment');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('comment_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('post_id_prim', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
			$table->addColumn('reply_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('reply_id_prim', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
			$table->addColumn('outbound', Types::SMALLINT, ['length' => 1, 'notnull' => true, 'default' => 0]);
			$table->addColumn('creation', Types::BIGINT, ['length' => 11, 'notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['reply_id_prim', 'file_id'], 'social_fcom_reply');
			$table->addIndex(['comment_id'], 'social_fcom_comment');
			$table->addIndex(['post_id_prim'], 'social_fcom_post');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
