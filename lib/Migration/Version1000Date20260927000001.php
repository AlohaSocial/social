<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops the tables of the feed subscriptions, a feature that shipped in
 * 0.26.60 and was removed in 0.26.94. Guarded, so an instance that never
 * had them is left alone.
 */
class Version1000Date20260927000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		foreach (['social_feed_item', 'social_feed'] as $table) {
			if ($schema->hasTable($table)) {
				$schema->dropTable($table);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
