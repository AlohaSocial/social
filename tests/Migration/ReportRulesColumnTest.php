<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261108400001;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class ReportRulesColumnTest extends TestCase {
	public function testTheColumnIsAddedOnceAndIsOptional(): void {
		$stand = [IAppConfig::class => $this->createStub(IAppConfig::class)];
		$schema = MigrationReplay::run([Version1000Date20221118000002::class, Version1000Date20261108400001::class], $stand);
		$once = $schema->shape();
		MigrationReplay::run([Version1000Date20261108400001::class], $stand, $schema);

		$this->assertSame($once, $schema->shape());
		$this->assertTrue($schema->getTable('social_report')->hasColumn('rules'));
		$this->assertArrayHasKey('rules', $once['social_report']['columns']);
	}
}
