<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_report.rules`: the instance rules a reporter said were broken, as a
 * JSON list of `{id, text}` taken when the report was filed. Rows filed before
 * it have none.
 */
class Version1000Date20261108400001 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_report')) {
			return null;
		}

		$table = $schema->getTable('social_report');
		if ($table->hasColumn('rules')) {
			return null;
		}

		$table->addColumn('rules', Types::TEXT, ['notnull' => false]);

		return $schema;
	}
}
