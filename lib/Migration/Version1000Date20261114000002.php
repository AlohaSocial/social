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
 * What a registration started from an app needs on its way through email
 * confirmation and approval: where to send the person once the account
 * exists (`return_to`), and the confirmation code typed into the page instead
 * of following the link (`code_hash`, an HMAC, and `code_attempts`).
 */
class Version1000Date20261114000002 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_ext_signup')) {
			return null;
		}

		$table = $schema->getTable('social_ext_signup');
		$changed = false;
		if (!$table->hasColumn('return_to')) {
			$table->addColumn('return_to', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}
		if (!$table->hasColumn('code_hash')) {
			$table->addColumn('code_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
			$changed = true;
		}
		if (!$table->hasColumn('code_attempts')) {
			$table->addColumn('code_attempts', Types::SMALLINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
