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
 * Where `DurableCache` keeps its entries on an instance with no memcache.
 *
 * Short-lived state — a rate-limit counter, a signature already accepted —
 * that the app used to keep in `createDistributed()` alone, which on such an
 * instance is a cache that forgets every write. One row per key, with the
 * moment it stops counting; `Cron\Cache` deletes what has expired.
 *
 * A file of its own rather than a few lines in the squashed migration: the
 * squash is already recorded as run on every instance that has upgraded, so a
 * table added there would exist on fresh installs only.
 */
class Version1000Date20260925000010 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_durable_cache')) {
			$table = $schema->createTable('social_durable_cache');
			// a hash of namespace and key, so a key of any length fits
			$table->addColumn('cache_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('cache_value', Types::TEXT, ['notnull' => false]);
			// unix time; a row at or past it is gone as far as a read is concerned
			$table->addColumn('expires', Types::BIGINT, ['length' => 20, 'notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['cache_key']);
			$table->addIndex(['expires'], 'social_dcache_exp');
		}

		return $schema;
	}
}
