<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261110000002;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** The sanitizer stamp on stored posts and cached bios. */
class SanitizedStampColumnsTest extends TestCase {
	private function schema(): FakeSchema {
		$schema = MigrationReplay::run(
			[Version1000Date20221118000002::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)]
		);
		MigrationReplay::run([Version1000Date20261110000002::class], [], $schema);

		return $schema;
	}

	public function testTheStampIsAZeroDefaultedSmallInteger(): void {
		$schema = $this->schema();

		foreach (['social_stream', 'social_cache_actor'] as $name) {
			[$type, $options] = $schema->getTable($name)->addedColumn('sanitized');
			$this->assertSame(Types::SMALLINT, $type, $name);
			$this->assertSame(0, $options['default'], 'a row from before reads as never stamped');
			$this->assertTrue($options['notnull']);
		}
		[$type, $options] = $schema->getTable('social_cache_actor')->addedColumn('description');
		$this->assertSame(Types::TEXT, $type);
		$this->assertFalse($options['notnull']);
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = $this->schema();
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261110000002::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function testItDoesNothingWhereTheTablesAreNotThere(): void {
		$schema = MigrationReplay::run([Version1000Date20261110000002::class]);

		$this->assertArrayNotHasKey('social_stream', $schema->shape());
	}
}
