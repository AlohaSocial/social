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
 * `social_stream.source_digest`: the custom emoji and totals a read needs
 * from the wire object, as small JSON (`Stream::sourceDigestOf()`), so
 * timelines are read without `source`. NULL means not digested yet and
 * falls back to `source`; existing rows are filled in the background by
 * `SourceDigestBackfill`, as `occ upgrade` cannot afford it.
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
