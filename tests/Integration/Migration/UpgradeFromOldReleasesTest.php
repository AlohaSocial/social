<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261011500100;
use OCA\Social\Migration\Version1000Date20261114000001;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\IDBConnection;
use OCP\Migration\IMigrationStep;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The squash and the step after it on tables in the shape a release before
 * 0.23 left them, against the real database: the indexes of that shape are
 * put back, rows that shape allowed are written, and each step is run the way
 * `occ upgrade` runs it — `preSchemaChange()`, then the schema it asks for
 * migrated to, then `postSchemaChange()`.
 *
 * The tables are changed and put back here, so a failure leaves the schema
 * current: `tearDown()` runs the squash once more, which restores whatever
 * the test removed, and then the later steps that drop what the squash still
 * creates, so no table of a removed feature outlives the test.
 */
class UpgradeFromOldReleasesTest extends TestCase {
	private const PREFIX = 'itest-upgrade-old-';

	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = Server::get(IDBConnection::class);
	}

	protected function tearDown(): void {
		// a schema change that failed half way leaves its transaction open
		while ($this->connection->inTransaction()) {
			$this->connection->rollBack();
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->delete(CoreRequestBuilder::TABLE_FOLLOWS)
			->where($qb->expr()->like('id', $qb->createNamedParameter(self::PREFIX . '%')));
		$qb->executeStatement();

		$this->runStep(Server::get(Version1000Date20221118000002::class));
		$this->runStep(Server::get(Version1000Date20261011500100::class));
		// the squash still creates `social_portfolio`, which this step drops
		$this->runStep(Server::get(Version1000Date20261114000001::class));
		parent::tearDown();
	}

	private function schema(): SchemaWrapper {
		return new SchemaWrapper(Server::get(Connection::class));
	}

	/** One step, as `MigrationService::executeStep()` runs it. */
	private function runStep(IMigrationStep $step): void {
		$output = $this->createStub(IOutput::class);
		$step->preSchemaChange($output, fn (): SchemaWrapper => $this->schema(), []);
		$target = $step->changeSchema($output, fn (): SchemaWrapper => $this->schema(), []);
		if ($target instanceof SchemaWrapper) {
			$this->connection->migrateToSchema($target->getWrappedSchema());
			// a dropped table is only queued on the wrapper; the server's
			// MigrationService drops it here, after migrating
			$target->performDropTableCalls();
		}
		$step->postSchemaChange($output, fn (): SchemaWrapper => $this->schema(), []);
	}

	/** @param callable(\OCP\DB\Schema\ITable): void $change */
	private function alter(string $table, callable $change): void {
		$schema = $this->schema();
		$change($schema->getTable($table));
		$this->connection->migrateToSchema($schema->getWrappedSchema());
	}

	private function follow(string $name, string $object, string $actor, bool $accepted): void {
		RowSeeder::insert(CoreRequestBuilder::TABLE_FOLLOWS, [
			'id' => self::PREFIX . $name,
			'id_prim' => md5(self::PREFIX . $name),
			'type' => 'Follow',
			'object_id_prim' => md5(self::PREFIX . $object),
			'actor_id_prim' => md5(self::PREFIX . $actor),
			'follow_id_prim' => '',
			'accepted' => $accepted,
		]);
	}

	/** @return list<string> the names of the rows left for the pair */
	private function rowsOf(string $object, string $actor): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id')
			->from(CoreRequestBuilder::TABLE_FOLLOWS)
			->where($qb->expr()->eq('object_id_prim', $qb->createNamedParameter(md5(self::PREFIX . $object))))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5(self::PREFIX . $actor))))
			->orderBy('id_prim');
		$result = $qb->executeQuery();
		$ids = array_column($result->fetchAll(), 'id');
		$result->closeCursor();

		return array_map(static fn (string $id): string => substr($id, strlen(self::PREFIX)), $ids);
	}

	/**
	 * The unique indexes of the old `social_follow` lead with `accepted`, so
	 * one pair could be there pending and accepted at once. The squash makes
	 * the pair unique, which fails over such rows unless they are gone first;
	 * of each pair the accepted row stays, whichever `id_prim` it has.
	 */
	public function testDuplicateFollowsAreRemovedBeforeThePairIsMadeUnique(): void {
		$this->alter(CoreRequestBuilder::TABLE_FOLLOWS, static function ($table): void {
			$table->dropIndex('social_f_oa_u');
			$table->dropIndex('social_f_foa');
		});

		// the pending row of the first pair has the lower id_prim, of the
		// second the higher
		$this->follow('early-pending', 'alice', 'bob', false);
		$this->follow('early-accepted', 'alice', 'bob', true);
		$this->follow('late-pending', 'alice', 'carol', false);
		$this->follow('late-accepted', 'alice', 'carol', true);
		$this->follow('alone', 'carol', 'alice', false);

		$this->runStep(Server::get(Version1000Date20221118000002::class));

		$this->assertSame(['early-accepted'], $this->rowsOf('alice', 'bob'));
		$this->assertSame(['late-accepted'], $this->rowsOf('alice', 'carol'));
		$this->assertSame(['alone'], $this->rowsOf('carol', 'alice'));

		$table = $this->schema()->getTable(CoreRequestBuilder::TABLE_FOLLOWS);
		$this->assertTrue($table->hasIndex('social_f_oa_u'), 'the pair is unique now');
		$this->assertTrue($table->hasIndex('social_f_foa'));
	}

	/** On a table that has the unique index already, nothing is read or removed. */
	public function testACurrentFollowTableIsLeftAlone(): void {
		$this->follow('current', 'dave', 'erin', true);

		$this->runStep(Server::get(Version1000Date20221118000002::class));

		$this->assertSame(['current'], $this->rowsOf('dave', 'erin'));
	}

	/**
	 * The indexes the history dropped, the unnamed duplicate of the stream's
	 * key among them, are found under the names the database gave them and
	 * dropped; a second run asks for nothing.
	 */
	public function testTheIndexesTheHistoryDroppedAreDropped(): void {
		$this->alter(CoreRequestBuilder::TABLE_FOLLOWS, static function ($table): void {
			$table->addUniqueIndex(['accepted', 'follow_id_prim', 'object_id_prim', 'actor_id_prim'], 'afoa');
		});
		$this->alter(CoreRequestBuilder::TABLE_STREAM_DEST, static function ($table): void {
			$table->addIndex(['type', 'subtype'], 'ts');
		});
		$this->alter(CoreRequestBuilder::TABLE_STREAM, static function ($table): void {
			$table->addUniqueIndex(['nid']);
		});
		$this->assertTrue($this->hasUniqueIndexOnNid(), 'the duplicate of the key is there to begin with');

		$step = Server::get(Version1000Date20261011500100::class);
		$this->runStep($step);

		$schema = $this->schema();
		$this->assertFalse($schema->getTable(CoreRequestBuilder::TABLE_FOLLOWS)->hasIndex('afoa'));
		$this->assertFalse($schema->getTable(CoreRequestBuilder::TABLE_STREAM_DEST)->hasIndex('ts'));
		$this->assertFalse($this->hasUniqueIndexOnNid());
		$this->assertTrue($schema->getTable(CoreRequestBuilder::TABLE_STREAM)->hasPrimaryKey(), 'the key itself stays');

		$this->assertNull($step->changeSchema($this->createStub(IOutput::class), fn (): SchemaWrapper => $this->schema(), []));
	}

	private function hasUniqueIndexOnNid(): bool {
		foreach ($this->schema()->getTable(CoreRequestBuilder::TABLE_STREAM)->getIndexes() as $index) {
			if ($index->isUnique() && !$index->isPrimary() && $index->getColumns() === ['nid']) {
				return true;
			}
		}

		return false;
	}
}
