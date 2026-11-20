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
 * Records, per revision of a status, the attachments as they were in that
 * version (a JSON list of MediaAttachment entities), as an edit's
 * `media_attributes` rewrites the attachment rows in place. Older revisions
 * read as null.
 */
class Version1000Date20261006000010 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_stream_rev')) {
			return null;
		}

		$table = $schema->getTable('social_stream_rev');
		if ($table->hasColumn('media')) {
			return null;
		}

		$table->addColumn('media', Types::TEXT, ['notnull' => false]);

		return $schema;
	}
}
