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
 * `social_stream.source_digest`: what a read takes out of the stored wire
 * object beside the columns — the custom emoji and the totals the origin
 * stated — as a small JSON object (`Stream::sourceDigestOf()`), so a
 * timeline is read without `source`.
 *
 * NULL is "not digested yet" and reads `source` as before. Every write of
 * `source` writes it; the rows already there are digested in the background
 * (`SourceDigestBackfill`), which a table this size cannot afford during
 * `occ upgrade`.
 */
class Version1000Date20261113200001 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STREAM)) {
			return null;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM);
		if ($table->hasColumn('source_digest')) {
			return null;
		}

		$table->addColumn('source_digest', Types::TEXT, ['notnull' => false, 'default' => null]);

		return $schema;
	}
}
