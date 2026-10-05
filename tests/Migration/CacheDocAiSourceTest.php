<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261005000001;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** Where a cached picture records the provenance its metadata stated. */
class CacheDocAiSourceTest extends TestCase {
	/** @return array<string, object> */
	private function stand(): array {
		return [IAppConfig::class => $this->createStub(IAppConfig::class)];
	}

	public function testTheColumnIsASmallDefaultedNumber(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261005000001::class], [], $schema);

		[$type, $options] = $schema->getTable('social_cache_doc')->addedColumn('ai_source');

		$this->assertSame(Types::SMALLINT, $type);
		// every row from before the column stated nothing, and nothing can be
		// learnt about it now: 0 rather than null is the honest value
		$this->assertSame(0, $options['default']);
		$this->assertTrue($options['notnull']);
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261005000001::class], [], $schema);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261005000001::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function testItDoesNothingWhereTheTableIsNotThere(): void {
		$schema = MigrationReplay::run([Version1000Date20261005000001::class]);

		$this->assertArrayNotHasKey('social_cache_doc', $schema->shape());
	}
}
