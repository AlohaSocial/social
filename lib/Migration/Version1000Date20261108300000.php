<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_rec_event (actor_key, created_at)`: what the daily trim of a
 * reader's event ledger reads newest first and deletes below a time.
 */
class Version1000Date20261108300000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_rec_event')) {
			return null;
		}
		$table = $schema->getTable('social_rec_event');
		if ($table->hasIndex('social_rec_event_actor_at')) {
			return null;
		}
		$table->addIndex(['actor_key', 'created_at'], 'social_rec_event_actor_at');

		return $schema;
	}
}
