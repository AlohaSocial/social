<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * How the recipient rows of a post reach the database.
 *
 * They are written inside the transaction that stores the post, so each
 * statement is a round trip with the post's locks held. A post's recipients
 * are one statement on the three databases the app supports, each in the
 * multi-row form of the conflict-skipping insert `insertIgnoreConflict()`
 * issues for one row; anything else is written a row at a time through it.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamDestInsertTest extends TestCase {
	private const POST = 'https://cloud.example/@alice/1';

	/** @var list<array{string, list<int|string>}> */
	private array $statements = [];
	/** @var list<array<string, int|string>> */
	private array $singleRows = [];

	private function request(string $platform): StreamDestRequest {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($this->createStub(IQueryBuilder::class));
		$connection->method('getDatabaseProvider')->willReturn($platform);
		$connection->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->statements[] = [$sql, $params];

				return 0;
			}
		);
		$connection->method('insertIgnoreConflict')->willReturnCallback(
			function (string $table, array $values): int {
				$this->singleRows[] = $values;

				return 1;
			}
		);

		return new StreamDestRequest(
			$connection,
			new NullLogger(),
			$this->createStub(IURLGenerator::class),
			$this->createStub(CacheActorsRequest::class),
			$this->createStub(ConfigService::class),
			$this->createStub(MiscService::class),
		);
	}

	/** @return array<string, array{string, string}> account => [type, subtype] */
	private function recipients(int $count): array {
		$recipients = [];
		for ($i = 0; $i < $count; $i++) {
			$recipients['https://other.example/@user' . $i] = ['recipient', ($i === 0) ? 'to' : 'cc'];
		}

		return $recipients;
	}

	/** @return iterable<string, array{string, string, string}> */
	public static function platforms(): iterable {
		yield 'MySQL' => [IDBConnection::PLATFORM_MYSQL, 'INSERT IGNORE INTO `*PREFIX*social_stream_dest`', ''];
		yield 'MariaDB' => [IDBConnection::PLATFORM_MARIADB, 'INSERT IGNORE INTO `*PREFIX*social_stream_dest`', ''];
		yield 'PostgreSQL' => [IDBConnection::PLATFORM_POSTGRES, 'INSERT INTO `*PREFIX*social_stream_dest`', ' ON CONFLICT DO NOTHING'];
		yield 'SQLite' => [IDBConnection::PLATFORM_SQLITE, 'INSERT OR IGNORE INTO `*PREFIX*social_stream_dest`', ''];
	}

	#[DataProvider('platforms')]
	public function testEveryRecipientIsOneStatement(string $platform, string $head, string $tail): void {
		$this->request($platform)->createRecipients(self::POST, $this->recipients(3), '42');

		$this->assertCount(1, $this->statements);
		$this->assertSame([], $this->singleRows);
		[$sql, $params] = $this->statements[0];

		$this->assertSame(
			$head . ' (`stream_id`, `actor_id`, `type`, `subtype`, `nid`) VALUES '
			. '(?, ?, ?, ?, ?), (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)' . $tail,
			$sql
		);
		$this->assertSame([
			md5(self::POST), md5('https://other.example/@user0'), 'recipient', 'to', '42',
			md5(self::POST), md5('https://other.example/@user1'), 'recipient', 'cc', '42',
			md5(self::POST), md5('https://other.example/@user2'), 'recipient', 'cc', '42',
		], $params);
	}

	public function testALongListIsChunkedInsideTheParameterLimit(): void {
		$this->request(IDBConnection::PLATFORM_SQLITE)
			->createRecipients(self::POST, $this->recipients(CoreRequestBuilder::INSERT_IGNORE_CHUNK + 1));

		$this->assertCount(2, $this->statements);
		$this->assertCount(CoreRequestBuilder::INSERT_IGNORE_CHUNK * 5, $this->statements[0][1]);
		$this->assertCount(5, $this->statements[1][1]);
	}

	public function testAnotherDatabaseIsWrittenARowAtATime(): void {
		$this->request(IDBConnection::PLATFORM_ORACLE)->createRecipients(self::POST, $this->recipients(2), 7);

		$this->assertSame([], $this->statements);
		$this->assertSame([
			['stream_id' => md5(self::POST), 'actor_id' => md5('https://other.example/@user0'), 'type' => 'recipient', 'subtype' => 'to', 'nid' => 7],
			['stream_id' => md5(self::POST), 'actor_id' => md5('https://other.example/@user1'), 'type' => 'recipient', 'subtype' => 'cc', 'nid' => 7],
		], $this->singleRows);
	}

	public function testNoRecipientsIsNoStatement(): void {
		$this->request(IDBConnection::PLATFORM_POSTGRES)->createRecipients(self::POST, []);

		$this->assertSame([], $this->statements);
	}

	/** A failure is the post's transaction's to roll back, not this method's to swallow. */
	public function testAFailureIsRaised(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($this->createStub(IQueryBuilder::class));
		$connection->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$connection->method('executeStatement')->willThrowException(new DBException('boom'));
		$request = new StreamDestRequest(
			$connection,
			new NullLogger(),
			$this->createStub(IURLGenerator::class),
			$this->createStub(CacheActorsRequest::class),
			$this->createStub(ConfigService::class),
			$this->createStub(MiscService::class),
		);

		$this->expectException(DBException::class);
		$request->createRecipients(self::POST, $this->recipients(1));
	}
}
