<?php

declare(strict_types=1);

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261007000023 extends SimpleMigrationStep {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_atpds_event_clock')) {
			return null;
		}
		$table = $schema->createTable('social_atpds_event_clock');
		$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('last_seq', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		return $schema;
	}
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		if ($qb->select('id')->from('social_atpds_event_clock')->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne() !== false) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$last = (int)$qb->select($qb->func()->max('seq'))->from('social_atpds_event')->executeQuery()->fetchOne();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atpds_event_clock')->values(['id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT), 'last_seq' => $qb->createNamedParameter($last, IQueryBuilder::PARAM_INT)])->executeStatement();
	}
}
