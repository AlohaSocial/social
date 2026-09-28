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

/** Records email verification and an optional, versioned registration notice acceptance. */
class Version1000Date20260928000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('social_ext_user')) {
			$table = $schema->getTable('social_ext_user');
			if (!$table->hasColumn('email_verified')) {
				$table->addColumn('email_verified', Types::SMALLINT, ['length' => 1, 'notnull' => true, 'default' => -1]);
				$changed = true;
			}
		}

		if ($schema->hasTable('social_ext_signup')) {
			$table = $schema->getTable('social_ext_signup');
			if (!$table->hasColumn('email_verified')) {
				$table->addColumn('email_verified', Types::SMALLINT, ['length' => 1, 'notnull' => true, 'default' => 0]);
				$changed = true;
			}
			if (!$table->hasColumn('notice_version')) {
				$table->addColumn('notice_version', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
				$changed = true;
			}
			if (!$table->hasColumn('notice_accepted')) {
				$table->addColumn('notice_accepted', Types::BIGINT, ['length' => 11, 'notnull' => true, 'default' => 0]);
				$changed = true;
			}
			if (!$table->hasColumn('notice_snapshot')) {
				$table->addColumn('notice_snapshot', Types::TEXT, ['notnull' => false]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
