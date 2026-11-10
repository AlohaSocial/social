<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261102000001;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** When a stored copy was written, which the media cache purge goes by. */
class CacheDocCachedColumnTest extends TestCase {
	/** @return array<string, object> */
	private function stand(): array {
		return [IAppConfig::class => $this->createStub(IAppConfig::class)];
	}

	public function testTheColumnIsANullableIndexedTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261102000001::class], [], $schema);

		$table = $schema->getTable('social_cache_doc');
		[$type, $options] = $table->addedColumn('cached');

		$this->assertSame(Types::DATETIME, $type);
		// null is "no copy here", which is what a purged row is
		$this->assertFalse($options['notnull']);
		$this->assertTrue($table->hasIndex('social_cd_cached'));
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261102000001::class], [], $schema);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261102000001::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function testItDoesNothingWhereTheTableIsNotThere(): void {
		$schema = MigrationReplay::run([Version1000Date20261102000001::class]);

		$this->assertArrayNotHasKey('social_cache_doc', $schema->shape());
	}

	/**
	 * Every row holding a file is dated: by when its fetch started where that
	 * was recorded, else when the row was written, else now. Rows with no
	 * file, and the placeholder names, stay null.
	 */
	public function testTheCopiesAlreadyStoredAreDatedBestFirst(): void {
		$statements = [];
		$connection = new class($statements) extends FakeConnection {
			/** @param list<array{0: string, 1: array}> $statements */
			public function __construct(
				private array &$statements,
			) {
				parent::__construct();
			}

			public function executeStatement($sql, ?array $params = null, ?array $types = null): int {
				$this->statements[] = [(string)$sql, $params ?? []];

				return 1;
			}
		};

		(new Version1000Date20261102000001($connection))
			->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);

		$this->assertCount(3, $statements);
		$this->assertStringContainsString('SET `cached` = `caching`', $statements[0][0]);
		$this->assertStringContainsString('SET `cached` = `creation`', $statements[1][0]);
		$this->assertStringContainsString('SET `cached` = ?', $statements[2][0]);
		foreach ($statements as [$sql, $params]) {
			$this->assertStringContainsString('`cached` IS NULL', $sql, 'a row already dated is left alone');
			$this->assertContains('avatar', $params);
			$this->assertContains('stream', $params);
			$this->assertContains('', $params);
		}
	}
}
