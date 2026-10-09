<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261009004200;
use OCP\DB\IResult;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The quote counter and the indexed hash of what a post quotes, and both
 * filled from what is stored.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamQuoteCountTest extends TestCase {
	private PDO $pdo;

	public function testTheCounterAndTheIndexedHashAreAddedOnce(): void {
		$schema = MigrationReplay::run(
			[Version1000Date20221118000002::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)]
		);
		MigrationReplay::run([Version1000Date20261009004200::class], [], $schema);

		[$type, $options] = $schema->getTable('social_stream')->addedColumn('count_quotes');
		$this->assertSame(Types::INTEGER, $type);
		$this->assertSame(0, $options['default']);
		[$type, $options] = $schema->getTable('social_stream')->addedColumn('quote_prim');
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length']);
		$this->assertContains(['quote_prim'], array_column($schema->shape()['social_stream']['indexes'], 'columns'));

		$once = $schema->shape();
		MigrationReplay::run([Version1000Date20261009004200::class], [], $schema);
		$this->assertSame($once, $schema->shape(), 'run twice, nothing asked the second time');
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testTheQuotesStoredAreHashedAndCountedOnWhatTheyQuote(): void {
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE social_stream (nid INTEGER PRIMARY KEY, id_prim TEXT, quote TEXT, quote_prim TEXT DEFAULT \'\', count_quotes INTEGER NOT NULL DEFAULT 0)');
		$insert = $this->pdo->prepare('INSERT INTO social_stream (nid, id_prim, quote) VALUES (?, ?, ?)');
		$quoted = 'https://social.test/@alice/1';
		$insert->execute([1, md5($quoted), '']);
		$insert->execute([2, md5('https://social.test/@bob/2'), $quoted]);
		$insert->execute([3, md5('https://bsky.app/profile/did:plc:c/post/3k'), $quoted]);
		$insert->execute([4, md5('https://social.test/@dan/4'), null]);

		$this->backfill();

		$rows = $this->pdo->query('SELECT nid, quote_prim, count_quotes FROM social_stream ORDER BY nid')->fetchAll(PDO::FETCH_ASSOC);
		$this->assertSame(2, (int)$rows[0]['count_quotes'], 'the post quoted twice');
		$this->assertSame([md5($quoted), md5($quoted)], [$rows[1]['quote_prim'], $rows[2]['quote_prim']]);
		$this->assertSame('', (string)$rows[3]['quote_prim'], 'a post that quotes nothing');
	}

	private function backfill(): void {
		$pdo = $this->pdo;
		$test = $this;
		$connection = new class($pdo, $test) extends FakeConnection {
			public function __construct(
				private PDO $pdo,
				private StreamQuoteCountTest $test,
			) {
				parent::__construct();
			}

			public function executeQuery(string $sql, ?array $params = null, $types = null): IResult {
				$statement = $this->pdo->prepare(str_replace(['*PREFIX*', '`'], ['', '"'], $sql));
				$statement->execute($params ?? []);

				return $this->test->rowsAsResult($statement->fetchAll(PDO::FETCH_ASSOC));
			}

			public function executeStatement($sql, ?array $params = null, ?array $types = null): int {
				$statement = $this->pdo->prepare(str_replace(['*PREFIX*', '`'], ['', '"'], (string)$sql));
				$statement->execute($params ?? []);

				return $statement->rowCount();
			}

			public function quote($input, $type = null) {
				return $this->pdo->quote((string)$input);
			}
		};

		(new Version1000Date20261009004200($connection))
			->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);
	}

	/** @param list<array<string, mixed>> $rows */
	public function rowsAsResult(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		return $result;
	}
}
