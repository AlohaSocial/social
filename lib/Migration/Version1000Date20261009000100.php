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
 * Whether a Bluesky app password is privileged: an app signed in with one
 * reaches the account's direct messages, as Bluesky's own privileged app
 * passwords do. Off for every password made before, as Bluesky's are.
 */
class Version1000Date20261009000100 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_atproto_app_password')) {
			return null;
		}
		$table = $schema->getTable('social_atproto_app_password');
		if ($table->hasColumn('privileged')) {
			return null;
		}
		$table->addColumn('privileged', Types::SMALLINT, ['notnull' => true, 'default' => 0, 'length' => 1, 'unsigned' => true]);

		return $schema;
	}
}
