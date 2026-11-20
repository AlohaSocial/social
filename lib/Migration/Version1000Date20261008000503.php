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
 * column, so domain blocks and silenced instances are one `NOT IN` instead of
 * `LIKE` patterns on `attributed_to`. NULL until a row is reached: new posts
 * write their own, and `Cron\StreamAuthorHosts`, queued here as the table is
 * the largest this app has, fills the rest. Until then the filters match a
 * row with no host by its id.
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
		$this->fill();
	}

	/**
	 * Queues the backfill, which raises `stream_author_hosts_filled` when it
	 * is done; `InstallBackfills` calls this on a fresh install, which runs
	 * migrations schema-only.
	 */
	public function fill(): void {
		if (!$this->jobList->has(StreamAuthorHosts::class, ['after' => '0'])) {
			$this->jobList->add(StreamAuthorHosts::class, ['after' => '0']);
		}
	}
}
