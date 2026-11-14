<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261114000001;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** The table of the portfolio pages, which are gone. */
class PortfolioTableDropTest extends TestCase {
	private function squashed(): FakeSchema {
		return MigrationReplay::run(
			[Version1000Date20221118000002::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)]
		);
	}

	public function testTheTableIsDropped(): void {
		$schema = $this->squashed();
		$this->assertTrue($schema->hasTable('social_portfolio'));

		MigrationReplay::run([Version1000Date20261114000001::class], [], $schema);

		$this->assertFalse($schema->hasTable('social_portfolio'));
	}

	public function testOnlyThatTableGoes(): void {
		$schema = $this->squashed();
		$before = $schema->shape();
		unset($before['social_portfolio']);

		MigrationReplay::run([Version1000Date20261114000001::class], [], $schema);

		$this->assertSame($before, $schema->shape());
	}

	public function testAnInstanceWithoutTheTableIsLeftAlone(): void {
		$schema = new FakeSchema();

		MigrationReplay::run([Version1000Date20261114000001::class], [], $schema);

		$this->assertSame([], $schema->shape());
	}
}
