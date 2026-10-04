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
 * Records the `redirect_uri` an authorization code was issued for, so the
 * token exchange can require the same one (RFC 6749 §4.1.3).
 */
class Version1000Date20261003000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_client_auth')) {
			return null;
		}

		$table = $schema->getTable('social_client_auth');
		if ($table->hasColumn('redirect_uri')) {
			return null;
		}

		$table->addColumn('redirect_uri', Types::TEXT, ['notnull' => false]);

		return $schema;
	}
}
