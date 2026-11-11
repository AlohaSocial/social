<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261110000001;
use OCP\DB\IResult;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/** The indexed keys `/media/{uuid}` finds a document by, and their backfill. */
#[AllowMockObjectsWithoutExpectations]
class CacheDocCopyPrimTest extends TestCase {
	private PDO $pdo;
	public int $statements = 0;

	public function testBothKeysAreIndexedShortStringsAddedOnce(): void {
		$schema = MigrationReplay::run(
			[Version1000Date20221118000002::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)]
		);
		MigrationReplay::run([Version1000Date20261110000001::class], [], $schema);

		$table = $schema->getTable('social_cache_doc');
		foreach (['local_copy_prim' => 'social_cd_lcp', 'resized_copy_prim' => 'social_cd_rcp'] as $column => $index) {
			[$type, $options] = $table->addedColumn($column);
			$this->assertSame(Types::STRING, $type);
			$this->assertSame(32, $options['length']);
			$this->assertTrue($table->hasIndex($index));
			$this->assertLessThanOrEqual(30, strlen($index));
		}

		$once = $schema->shape();
		MigrationReplay::run([Version1000Date20261110000001::class], [], $schema);
		$this->assertSame($once, $schema->shape(), 'run twice, nothing asked the second time');
	}

	public function testItDoesNothingWhereTheTableIsNotThere(): void {
		$schema = MigrationReplay::run([Version1000Date20261110000001::class]);

		$this->assertArrayNotHasKey('social_cache_doc', $schema->shape());
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testEveryStoredNameIsKeyedAcrossPages(): void {
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE social_cache_doc (nid INTEGER PRIMARY KEY, local_copy TEXT, resized_copy TEXT,'
			. ' local_copy_prim TEXT DEFAULT \'\', resized_copy_prim TEXT DEFAULT \'\')');
		$insert = $this->pdo->prepare('INSERT INTO social_cache_doc (nid, local_copy, resized_copy) VALUES (?, ?, ?)');
		// more than one page of 500, so the keyset has to move on
		for ($nid = 1; $nid <= 1203; $nid++) {
			$insert->execute([$nid, 'full-' . $nid, ($nid % 2 === 0) ? 'small-' . $nid : '']);
		}
		$insert->execute([2000, '', '']);
		$insert->execute([2001, null, null]);
		$insert->execute([2002, 'stream', 'poster']);

		$this->backfill();

		$rows = $this->pdo->query('SELECT nid, local_copy_prim, resized_copy_prim FROM social_cache_doc')
			->fetchAll(PDO::FETCH_ASSOC);
		$byNid = array_column($rows, null, 'nid');
		$this->assertSame(md5('full-1'), $byNid[1]['local_copy_prim']);
		$this->assertSame('', $byNid[1]['resized_copy_prim'], 'no resized copy, no key');
		$this->assertSame(md5('small-1202'), $byNid[1202]['resized_copy_prim']);
		$this->assertSame(md5('full-1203'), $byNid[1203]['local_copy_prim']);
		$this->assertSame(['', ''], [$byNid[2000]['local_copy_prim'], $byNid[2000]['resized_copy_prim']]);
		$this->assertSame(['', ''], [(string)$byNid[2001]['local_copy_prim'], (string)$byNid[2001]['resized_copy_prim']]);
		$this->assertSame(md5('poster'), $byNid[2002]['resized_copy_prim']);

		$this->statements = 0;
		$this->backfill();
		$this->assertSame(0, $this->statements, 'run again, every row is keyed already');
	}

	private function backfill(): void {
		$pdo = $this->pdo;
		$test = $this;
		$connection = new class($pdo, $test) extends FakeConnection {
			public function __construct(
				private PDO $pdo,
				private CacheDocCopyPrimTest $test,
			) {
				parent::__construct();
			}

			public function executeQuery(string $sql, ?array $params = null, $types = null): IResult {
				$statement = $this->pdo->prepare(str_replace(['*PREFIX*', '`'], ['', '"'], $sql));
				$statement->execute($params ?? []);

				return $this->test->rowsAsResult($statement->fetchAll(PDO::FETCH_ASSOC));
			}

			public function executeStatement($sql, ?array $params = null, ?array $types = null): int {
				$this->test->statements++;
				$statement = $this->pdo->prepare(str_replace(['*PREFIX*', '`'], ['', '"'], (string)$sql));
				$statement->execute($params ?? []);

				return $statement->rowCount();
			}

			public function quote($input, $type = null) {
				return $this->pdo->quote((string)$input);
			}
		};

		(new Version1000Date20261110000001($connection))
			->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);
	}

	/** @param list<array<string, mixed>> $rows */
	public function rowsAsResult(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		return $result;
	}
}
