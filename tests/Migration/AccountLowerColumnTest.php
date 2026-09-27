<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20260925000002;
use OCP\DB\IResult;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * `social_cache_actor.account_lower`, the column an account search compares.
 */
#[AllowMockObjectsWithoutExpectations]
class AccountLowerColumnTest extends TestCase {
	/** @return array{columns: array, indexes: array, primary: array} */
	private function cacheActors(bool $twice = false): array {
		$stand = [IAppConfig::class => $this->createStub(IAppConfig::class)];
		$schema = MigrationReplay::run([Version1000Date20221118000002::class, Version1000Date20260925000002::class], $stand);
		if ($twice) {
			MigrationReplay::run([Version1000Date20260925000002::class], $stand, $schema);
		}

		return $schema->shape()['social_cache_actor'];
	}

	public function testTheHandleHasAnIndexedLowercaseCopy(): void {
		$table = $this->cacheActors();

		$this->assertSame(Types::STRING, $table['columns']['account_lower']['type']);
		$this->assertSame(127, $table['columns']['account_lower']['options']['length']);
		$this->assertContains(
			['columns' => ['account_lower'], 'name' => 'social_ca_al', 'unique' => false],
			array_map(static fn (array $index): array => [
				'columns' => $index['columns'], 'name' => $index['name'], 'unique' => $index['unique'],
			], $table['indexes'])
		);
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$this->assertSame($this->cacheActors(), $this->cacheActors(true));
	}

	/**
	 * The rows written before the column, walked on the primary key, each
	 * given its handle lowercased — whatever the case it was written in.
	 */
	public function testTheExistingHandlesAreBackfilled(): void {
		$rows = [];
		for ($nid = 1; $nid <= Version1000Date20260925000002::BATCH + 3; $nid++) {
			$rows[$nid] = ['nid' => $nid, 'account' => 'User' . $nid . '@Mastodon.Example', 'account_lower' => ''];
		}
		$rows[7]['account'] = 'Ärger@host.example';

		$connection = $this->createStub(IDBConnection::class);
		$connection->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use (&$rows): IResult {
				$this->assertStringContainsString('ORDER BY `nid` ASC LIMIT ' . Version1000Date20260925000002::BATCH, $sql);
				$page = array_values(array_filter(
					$rows, static fn (array $row): bool => $row['nid'] > (int)$params[0] && $row['account_lower'] === ''
				));
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(array_slice($page, 0, Version1000Date20260925000002::BATCH));

				return $result;
			}
		);
		$connection->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use (&$rows): int {
				$this->assertStringStartsWith('UPDATE `*PREFIX*social_cache_actor` SET `account_lower` = CASE `nid`', $sql);
				$count = substr_count($sql, 'WHEN');
				for ($i = 0; $i < $count; $i++) {
					$rows[(int)$params[2 * $i]]['account_lower'] = $params[2 * $i + 1];
				}

				return $count;
			}
		);

		(new Version1000Date20260925000002($connection))
			->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);

		$this->assertSame('user1@mastodon.example', $rows[1]['account_lower']);
		$this->assertSame('ärger@host.example', $rows[7]['account_lower']);
		$this->assertSame(
			'user' . (Version1000Date20260925000002::BATCH + 3) . '@mastodon.example',
			$rows[Version1000Date20260925000002::BATCH + 3]['account_lower'],
			'the second page is reached'
		);
	}
}
