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
 * When a post's interaction counts were last read from its origin, the
 * schedule `Cron\Cache` asks again on: NULL is due, a timestamp once old
 * enough. A column rather than a key in `details` so the due rows are an
 * index range; the index leads with `local`, since only remote rows are
 * asked about.
 */
class Version1000Date20261004000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_stream')) {
			return null;
		}

		$table = $schema->getTable('social_stream');
		if ($table->hasColumn('counts_at')) {
			return null;
		}

		$table->addColumn('counts_at', Types::DATETIME, ['notnull' => false]);
		$table->addIndex(['local', 'counts_at'], 'social_s_lca');

		return $schema;
	}
}
