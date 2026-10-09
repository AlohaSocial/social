<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20261009004100;
use PHPUnit\Framework\TestCase;

/**
 * The accounts this instance verified: one row per account, found by the
 * hash of its id, with what its published record names.
 */
class VerificationTableTest extends TestCase {
	public function testTheTableHoldsWhatTheRequestReadsAndIsAddedOnce(): void {
		$schema = MigrationReplay::run([Version1000Date20261009004100::class]);
		$shape = $schema->shape()['social_verification'];

		$columns = array_keys($shape['columns']);
		sort($columns);
		$declared = CoreRequestBuilder::$tables[CoreRequestBuilder::TABLE_VERIFICATIONS];
		sort($declared);
		$this->assertSame($declared, $columns);
		$this->assertSame(['id'], $shape['primary']);
		$this->assertSame([['columns' => ['actor_id_prim'], 'name' => 'social_verif_actor', 'unique' => true]], $shape['indexes']);

		$before = $schema->shape();
		MigrationReplay::run([Version1000Date20261009004100::class], [], $schema);
		$this->assertSame($before, $schema->shape(), 'run twice, nothing asked the second time');
	}
}
