<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20261113400001;
use PHPUnit\Framework\TestCase;

/**
 * The reasons for an instance-wide block: one row per domain, holding what
 * the request reads.
 */
class ServerBlockTableTest extends TestCase {
	public function testTheTableHoldsWhatTheRequestReadsAndIsAddedOnce(): void {
		$schema = MigrationReplay::run([Version1000Date20261113400001::class]);
		$shape = $schema->shape()['social_server_block'];

		$columns = array_keys($shape['columns']);
		sort($columns);
		$declared = CoreRequestBuilder::$tables[CoreRequestBuilder::TABLE_SERVER_BLOCKS];
		sort($declared);
		$this->assertSame($declared, $columns);
		$this->assertSame(['id'], $shape['primary']);
		$this->assertSame([['columns' => ['domain'], 'name' => 'social_srvblk_domain', 'unique' => true]], $shape['indexes']);

		$before = $schema->shape();
		MigrationReplay::run([Version1000Date20261113400001::class], [], $schema);
		$this->assertSame($before, $schema->shape(), 'run twice, nothing asked the second time');
	}
}
