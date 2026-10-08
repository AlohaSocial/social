<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Cron\StreamAuthorHosts;
use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261008000503;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** `social_stream.author_host`: the column, its index, and the queued backfill. */
class StreamAuthorHostColumnTest extends TestCase {
	/** @return array<string, object> */
	private function stand(): array {
		return [
			IAppConfig::class => $this->createStub(IAppConfig::class),
			IJobList::class => $this->createStub(IJobList::class),
		];
	}

	public function testTheColumnIsAnIndexableNullableHost(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261008000503::class], $this->stand(), $schema);

		$table = $schema->getTable('social_stream');
		[$type, $options] = $table->addedColumn('author_host');
		$this->assertSame(Types::STRING, $type);
		// what MySQL indexes whole in utf8mb4
		$this->assertLessThanOrEqual(191, $options['length']);
		$this->assertFalse($options['notnull']);
		$this->assertTrue($table->hasIndex('social_s_ahost'));
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261008000503::class], $this->stand(), $schema);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261008000503::class], $this->stand(), $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function testTheBackfillIsQueuedFromTheStart(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('add')->with(StreamAuthorHosts::class, ['after' => '0']);

		(new Version1000Date20261008000503($jobList))
			->postSchemaChange($this->createStub(IOutput::class), fn () => null, []);
	}
}
