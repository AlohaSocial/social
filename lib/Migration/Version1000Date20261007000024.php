<?php

declare(strict_types=1);

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Keep native PDS storage separate from the existing external-account connector. */
class Version1000Date20261007000024 extends SimpleMigrationStep {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$sharedSchema = static fn (): ISchemaWrapper => $schema;
		(new Version1000Date20261007000020())->changeSchema($output, $sharedSchema, $options);
		(new Version1000Date20261007000021())->changeSchema($output, $sharedSchema, $options);
		(new Version1000Date20261007000022())->changeSchema($output, $sharedSchema, $options);
		(new Version1000Date20261007000023($this->db))->changeSchema($output, $sharedSchema, $options);
		return $schema;
	}
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		// Only earlier native draft schemas have recovery_public. Never copy the
		// unrelated connector identity/key/watch schemas into native PDS tables.
		if ($schema->hasTable('social_atproto_identity') && $schema->getTable('social_atproto_identity')->hasColumn('recovery_public')) {
			$this->db->beginTransaction();
			try {
				foreach (['identity', 'instance_key', 'repo', 'record', 'block', 'blob', 'event', 'plc_log', 'watch', 'notify_cursor', 'labeler', 'blocklist', 'session', 'recovery', 'outbox'] as $suffix) {
					$this->copyDraftTable($schema, $suffix);
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		}
		(new Version1000Date20261007000023($this->db))->postSchemaChange($output, $schemaClosure, $options);
		$qb = $this->db->getQueryBuilder();
		$last = (int)$qb->select($qb->func()->max('seq'))->from('social_atpds_event')->executeQuery()->fetchOne();
		$qb = $this->db->getQueryBuilder();
		$qb->update('social_atpds_event_clock')->set('last_seq', $qb->createNamedParameter($last, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->lt('last_seq', $qb->createNamedParameter($last, IQueryBuilder::PARAM_INT)))->executeStatement();
		if ($schema->hasTable('social_atproto_event_clock') && $schema->hasTable('social_atproto_identity')
			&& $schema->getTable('social_atproto_identity')->hasColumn('recovery_public')) {
			$qb = $this->db->getQueryBuilder();
			$previous = (int)$qb->select('last_seq')->from('social_atproto_event_clock')->executeQuery()->fetchOne();
			$qb = $this->db->getQueryBuilder();
			$qb->update('social_atpds_event_clock')->set('last_seq', $qb->createNamedParameter($previous, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->lt('last_seq', $qb->createNamedParameter($previous, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
	}
	private function copyDraftTable(ISchemaWrapper $schema, string $suffix): void {
		$old = 'social_atproto_' . $suffix;
		$new = 'social_atpds_' . $suffix;
		if (!$schema->hasTable($old)) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		if ((int)$qb->select($qb->func()->count('*'))->from($new)->executeQuery()->fetchOne() !== 0) {
			return;
		}
		$columns = array_values(array_filter(array_map(static fn (\OCP\DB\Schema\IColumn $column): string => $column->getName(), $schema->getTable($new)->getColumns()), static fn (string $column): bool => $column !== 'id'));
		for ($offset = 0; ; $offset += 500) {
			$qb = $this->db->getQueryBuilder();
			$rows = $qb->select(...$columns)->from($old)->orderBy($suffix === 'event' ? 'seq' : 'id')->setFirstResult($offset)->setMaxResults(500)->executeQuery()->fetchAll();
			foreach ($rows as $row) {
				$qb = $this->db->getQueryBuilder();
				$values = [];
				foreach ($row as $column => $value) {
					$values[$column] = $qb->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : ($column === 'bytes' ? IQueryBuilder::PARAM_LOB : IQueryBuilder::PARAM_STR));
				}
				$qb->insert($new)->values($values)->executeStatement();
			}
			if (count($rows) < 500) {
				break;
			}
		}
	}
}
