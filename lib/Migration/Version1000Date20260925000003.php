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
 * `social_host_breaker`: which peers delivery is leaving alone, and until when.
 *
 * The circuit breaker lived in the distributed cache, which is a `NullCache`
 * on an instance without a memcache — so there it held nothing, every pass
 * rediscovered every dead peer one thirty-second timeout at a time, and the
 * cron's whole budget went on servers that were known to be gone. A row per
 * failing host is what every delivery path can read whatever the server has
 * configured.
 *
 * A file of its own for the reason `Version1000Date20260924000001` gives: the
 * squash is recorded as run on every instance that has upgraded, so a table
 * added to it would exist only on fresh installs.
 */
class Version1000Date20260925000003 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_host_breaker')) {
			$table = $schema->createTable('social_host_breaker');
			$table->addColumn('host_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('host', Types::STRING, ['length' => 255, 'notnull' => true]);
			// consecutive failures, which is what the wait doubles on
			$table->addColumn('strikes', Types::INTEGER, ['default' => 0, 'notnull' => true, 'unsigned' => true]);
			// unix seconds, not DATETIME: compared with `time()` in PHP and in
			// SQL, with no time zone between the two
			$table->addColumn('open_until', Types::BIGINT, ['default' => 0, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('last_failure', Types::BIGINT, ['default' => 0, 'notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['host_prim']);
			// what a drain loads: the hosts that failed recently enough to count
			$table->addIndex(['last_failure'], 'social_hb_lf');
		}

		return $schema;
	}
}
