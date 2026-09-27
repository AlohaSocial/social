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
 * The feeds an account follows, and what came out of them.
 *
 * A subscription is not a follow and an entry is not a post: nothing here
 * federates, nothing is attributed to anybody on this server, and nothing can
 * be boosted or replied to. Hence tables of their own rather than rows in
 * `social_stream` — a feed entry that lived there would be one mistake away
 * from being served as an ActivityPub object this instance claims to publish.
 *
 * Owned by a Nextcloud user rather than by an actor: reading a blog is not
 * something you need a fediverse identity for, and somebody who has not
 * finished the setup screen can still follow a feed.
 *
 * A file of its own rather than a few lines in the squashed migration, though
 * it only shapes tables. The squash carries the version of the initial step it
 * replaced, so every instance that has ever upgraded has already recorded it
 * as run and Nextcloud will not run it again: a table added there would exist
 * on fresh installs and on nobody else's server.
 */
class Version1000Date20260924000001 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_feed')) {
			$table = $schema->createTable('social_feed');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			// where the entries are read from, which is not where a reader typed
			$table->addColumn('url', Types::TEXT, ['notnull' => true]);
			$table->addColumn('url_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			// the page a human would open, from the feed's own <link>
			$table->addColumn('site_url', Types::TEXT, ['notnull' => false]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => false]);
			// what the last read said, so a feed that has gone can say so
			$table->addColumn('error', Types::STRING, ['length' => 255, 'notnull' => false]);
			// a conditional request asks for what changed rather than the file
			$table->addColumn('etag', Types::STRING, ['length' => 255, 'notnull' => false]);
			$table->addColumn('modified_at', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('fetched_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			// one row per feed per reader: following the same blog twice is one
			// subscription, and the second Follow is a no-op rather than a copy
			$table->addUniqueIndex(['user_id', 'url_prim'], 'social_feed_uu');
			$table->addIndex(['fetched_at'], 'social_feed_due');
		}

		if (!$schema->hasTable('social_feed_item')) {
			$table = $schema->createTable('social_feed_item');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('feed_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			// the entry's own id where it has one, its link where it has not
			$table->addColumn('guid_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('link', Types::TEXT, ['notnull' => false]);
			$table->addColumn('title', Types::STRING, ['length' => 512, 'notnull' => false]);
			$table->addColumn('summary', Types::TEXT, ['notnull' => false]);
			$table->addColumn('thumbnail', Types::TEXT, ['notnull' => false]);
			$table->addColumn('published', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			// the same entry read twice is the same row: a feed republished
			// with one entry added must not repeat the other fifteen
			$table->addUniqueIndex(['feed_id', 'guid_prim'], 'social_feeditem_fg');
			$table->addIndex(['feed_id', 'published'], 'social_feeditem_fp');
		}
		return $schema;
	}
}
