<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use DateTime;
use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261116000001;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Queued deliveries carry the time they were queued, so the rows of a host
 * the breaker keeps holding back expire by age instead of never.
 */
class RequestQueueCreationColumnTest extends TestCase {
	private function migrated(): FakeSchema {
		return MigrationReplay::run(
			[Version1000Date20221118000002::class, Version1000Date20261116000001::class],
			[IDBConnection::class => new FakeConnection()]
		);
	}

	public function testTheQueueGetsItsCreationAndTheIndexesReadingIt(): void {
		$queue = $this->migrated()->getTable('social_req_queue');

		$this->assertSame(Types::DATETIME, $queue->addedColumn('creation')[0]);
		$this->assertFalse($queue->addedColumn('creation')[1]['notnull'], 'a nullable column is added without rewriting a large table');

		$indexes = [];
		foreach ($queue->addedIndexes() as $index) {
			$indexes[(string)$index['name']] = $index['columns'];
		}
		$this->assertSame(['status', 'creation'], $indexes['social_rq_sc'] ?? null);
		$this->assertSame(['author_prim', 'status'], $indexes['social_rq_as'] ?? null);
	}

	public function testRunningItAgainChangesNothing(): void {
		$schema = $this->migrated();

		$this->assertNull(
			(new Version1000Date20261116000001(new FakeConnection()))
				->changeSchema($this->createStub(IOutput::class), static fn (): FakeSchema => $schema, [])
		);
	}

	/** The rows already queued are dated now: the dead ones expire one schedule after the upgrade. */
	public function testRowsQueuedBeforeTheUpgradeAreDatedNow(): void {
		$connection = new FakeConnection();

		(new Version1000Date20261116000001($connection))
			->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);

		$writes = $connection->writes();
		$this->assertCount(1, $writes);
		$this->assertSame('social_req_queue', $writes[0]->table);
		$this->assertInstanceOf(DateTime::class, $writes[0]->sets['creation']);
		$this->assertEqualsWithDelta(time(), $writes[0]->sets['creation']->getTimestamp(), 2);
		$this->assertSame(['creation IS NULL'], $writes[0]->wheres);
	}
}
