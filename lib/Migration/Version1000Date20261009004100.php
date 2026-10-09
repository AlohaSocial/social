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
 * `social_verification`: the accounts this instance verified
 * (`VerificationService`), one row each — the account, who verified it and
 * when, and the DID, handle and display name the published verification
 * record names, so a change to either is noticed and the record issued
 * again. `did` is '' for an account that has none: verified here only.
 */
class Version1000Date20261009004100 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_verification')) {
			return null;
		}
		$table = $schema->createTable('social_verification');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
		$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
		$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
		$table->addColumn('handle', Types::STRING, ['length' => 253, 'notnull' => true, 'default' => '']);
		$table->addColumn('display_name', Types::STRING, ['length' => 640, 'notnull' => true, 'default' => '']);
		$table->addColumn('verified_by', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
		$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['actor_id_prim'], 'social_verif_actor');

		return $schema;
	}
}
