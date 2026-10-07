<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20261007000020;
use OCA\Social\Migration\Version1000Date20261007000021;
use OCA\Social\Migration\Version1000Date20261007000022;
use PHPUnit\Framework\TestCase;

class AtprotoTablesTest extends TestCase {
	public function testSchemaReplaysAndEveryColumnParticipatesInReset(): void {
		$steps = [Version1000Date20261007000020::class, Version1000Date20261007000021::class, Version1000Date20261007000022::class];
		$schema = MigrationReplay::run($steps);
		$shape = $schema->shape();
		MigrationReplay::run($steps, [], $schema);
		self::assertSame($shape, $schema->shape());
		foreach ($shape as $table => $details) {
			self::assertArrayHasKey($table, CoreRequestBuilder::$tables);
			$actual = CoreRequestBuilder::$tables[$table];
			$expected = array_keys($details['columns']);
			sort($actual);
			sort($expected);
			self::assertSame($expected, $actual);
		}
		self::assertSame('string', $shape['social_atpds_identity']['columns']['actor_id']['type']);
		self::assertSame('blob', $shape['social_atpds_event']['columns']['bytes']['type']);
	}
}
