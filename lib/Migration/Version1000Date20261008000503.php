<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Cron\StreamAuthorHosts;
use OCA\Social\Db\DomainBlocksRequestBuilder;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_stream.author_host`: the host of a post's author, as an indexed
 * column.
 *
 * A domain block and a silenced instance were matched by `LIKE` patterns on
 * `attributed_to`, an unindexed text column — two per domain, on every
 * candidate row of every timeline read, so an imported blocklist made every
 * timeline slower for whoever imported it. With the host as a column they are
 * one `NOT IN`.
 *
 * NULL until a row is reached. Every post stored from now on writes its own;
 * the ones already there are filled in by `Cron\StreamAuthorHosts`, queued
 * here rather than run here because the table is the largest this app has.
 * Until it has finished, the filters match a row with no host by its id, as
 * before.
 */
class Version1000Date20261008000503 extends SimpleMigrationStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_stream')) {
			return null;
		}

		$table = $schema->getTable('social_stream');
		$changed = false;

		if (!$table->hasColumn('author_host')) {
			$table->addColumn('author_host', Types::STRING, [
				'length' => DomainBlocksRequestBuilder::AUTHOR_HOST_LENGTH, 'notnull' => false,
			]);
			$changed = true;
		}

		if (!$table->hasIndex('social_s_ahost')) {
			$table->addIndex(['author_host'], 'social_s_ahost');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->jobList->add(StreamAuthorHosts::class, ['after' => '0']);
	}
}
