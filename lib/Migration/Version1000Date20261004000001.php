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
 * When a post's interaction counts were last read from the server that hosts it.
 *
 * A post's `details` carried what its origin said about likes, boosts and
 * replies when this instance first stored the document, and nothing ever asked
 * again — a post federated in with five likes still said five a year and four
 * thousand later, and a reply written here never appeared in the count of the
 * post it answered. `Cron\Cache` asks on an interval now, and this is the
 * schedule: NULL has never been asked and is due, and a timestamp is not due
 * again until it is old enough.
 *
 * A column rather than a key in `details` because the rows that are due are
 * found by a range predicate, which wants an index: on the JSON blob it would
 * be a scan of the largest table in the app on every pass, on three databases
 * that each spell a JSON lookup differently. The index is on `local` first,
 * because the rows asked about are the remote ones and that is the half of the
 * table the query does not want to read.
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
