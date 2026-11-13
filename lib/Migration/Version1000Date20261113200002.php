<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The hourly hashtag buckets the trends are counted from.
 *
 * `social_tag_hour` holds, per hashtag and hour (`hour_start`, the Unix time
 * it starts), how many public statuses published in that hour carry the tag;
 * `social_tag_dirty` the hours a deletion or an edit may have changed since
 * they were counted. `hashtag` is as wide as `social_stream_tag.hashtag`,
 * which it is counted from.
 *
 * The buckets of the current ten-day window are built once, by the first
 * trends pass that finds no `StreamRequest::TREND_BUCKETS_MARK`; clearing it
 * here makes that pass the one after this step.
 */
class Version1000Date20261113200002 extends SimpleMigrationStep {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_TAG_HOURS)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_TAG_HOURS);
			$table->addColumn('hashtag', Types::STRING, ['notnull' => true, 'length' => 127]);
			$table->addColumn('hour_start', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('uses', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->setPrimaryKey(['hashtag', 'hour_start']);
			$table->addIndex(['hour_start'], 'social_th_hour');
			$changed = true;
		}

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_TAG_HOURS_DIRTY)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_TAG_HOURS_DIRTY);
			$table->addColumn('hour_start', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['hour_start']);
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->appConfig->deleteKey(Application::APP_ID, StreamRequest::TREND_BUCKETS_MARK);
	}
}
