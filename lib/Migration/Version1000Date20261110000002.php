<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Lets a read skip the HTML sanitizer for text that went through it on the
 * way in (`HtmlSanitizer::version()`).
 *
 * - `social_stream.sanitized`: the stamp the stored `content` was sanitized
 *   under, 0 for a row written before this step.
 * - `social_cache_actor.description` and `sanitized`: the bio as the
 *   sanitizer makes it out of `summary`, which holds the bio as it arrived,
 *   and the stamp it was made under.
 *
 * A row at 0 reads exactly as before, sanitized on every read, until
 * `Cron\Resanitize` has rewritten it; nothing is backfilled here, so the
 * upgrade itself stays short on a large table.
 */
class Version1000Date20261110000002 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable(CoreRequestBuilder::TABLE_STREAM)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM);
			if (!$table->hasColumn('sanitized')) {
				$table->addColumn('sanitized', Types::SMALLINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
				$changed = true;
			}
		}

		if ($schema->hasTable(CoreRequestBuilder::TABLE_CACHE_ACTORS)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_CACHE_ACTORS);
			if (!$table->hasColumn('description')) {
				$table->addColumn('description', Types::TEXT, ['notnull' => false]);
				$changed = true;
			}
			if (!$table->hasColumn('sanitized')) {
				$table->addColumn('sanitized', Types::SMALLINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
