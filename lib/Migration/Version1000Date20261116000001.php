<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use DateTime;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_req_queue.creation`: when a delivery was queued.
 *
 * A delivery whose host is held back by the circuit breaker is postponed
 * without spending a try, so the try count alone never ends the rows of an
 * instance that has gone away. The age does: `RequestQueueRequest::
 * abandonExhausted()` gives up on a row once it is older than the whole
 * retry schedule, read on (`status`, `creation`).
 *
 * (`author_prim`, `status`) answers whether a deleted account still has
 * deliveries waiting, which is what keeps its signing key until they have
 * gone, and serves `deleteByAuthor()`.
 *
 * Rows already queued are dated now, so the ones addressed to dead hosts
 * expire one schedule after the upgrade. Run again, it finds none undated.
 */
class Version1000Date20261116000001 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_REQUEST_QUEUE)) {
			return null;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_REQUEST_QUEUE);
		$changed = false;
		if (!$table->hasColumn('creation')) {
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$changed = true;
		}
		if (!$table->hasIndex('social_rq_sc')) {
			$table->addIndex(['status', 'creation'], 'social_rq_sc');
			$changed = true;
		}
		if (!$table->hasIndex('social_rq_as')) {
			$table->addIndex(['author_prim', 'status'], 'social_rq_as');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_REQUEST_QUEUE)
			->set('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->isNull('creation'));
		$dated = $qb->executeStatement();

		if ($dated > 0) {
			$output->info('dated ' . $dated . ' queued deliveries');
		}
	}
}
