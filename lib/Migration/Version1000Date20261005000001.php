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
 * Records, per cached picture, the machine-generation provenance its metadata
 * stated when it was stored: `0` nothing, `1` IPTC's `trainedAlgorithmicMedia`,
 * `2` its `compositeWithTrainedAlgorithmicMedia`.
 *
 * On the row because it cannot stay in the file: the XMP it is read from is
 * stripped before the copy is written, and the stored bytes no longer say it.
 * Rows from before this column existed read as `0` — their metadata is gone
 * and nothing can be learnt about them now.
 */
class Version1000Date20261005000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_cache_doc')) {
			return null;
		}

		$table = $schema->getTable('social_cache_doc');
		if ($table->hasColumn('ai_source')) {
			return null;
		}

		$table->addColumn('ai_source', Types::SMALLINT, ['default' => 0, 'notnull' => true]);

		return $schema;
	}
}
