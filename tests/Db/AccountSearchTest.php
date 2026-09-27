<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The account search behind the mention picker, which runs on every keystroke.
 *
 * `account LIKE ?` was compared `COLLATE utf8mb4_general_ci` on MySQL to fold
 * case, over a column with no index: `EXPLAIN` said `ca ALL`, every cached
 * actor per keystroke. The handle has a lowercased, indexed copy now, and the
 * search compares a prefix of it as it stands.
 */
#[AllowMockObjectsWithoutExpectations]
class AccountSearchTest extends TestCase {
	/** @var string[] */
	private array $where = [];

	private function builder(): SocialQueryBuilder {
		$connection = $this->createStub(IDBConnection::class);
		$connection->method('escapeLikeParameter')->willReturnCallback(
			static fn (string $value): string => addcslashes($value, '\\%_')
		);

		$qb = $this->getMockBuilder(SocialQueryBuilder::class)
			->disableOriginalConstructor()
			->onlyMethods(['getType', 'getDefaultSelectAlias', 'getConnection', 'expr', 'createNamedParameter', 'andWhere', 'func'])
			->getMock();
		$qb->method('getType')->willReturn(SocialQueryBuilder::SELECT);
		$qb->method('getDefaultSelectAlias')->willReturn('ca');
		$qb->method('getConnection')->willReturn($connection);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('func')->willThrowException(new \LogicException('no function over the searched column'));
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => "'" . $value . "'");
		$qb->method('andWhere')->willReturnCallback(function ($predicate) use ($qb) {
			$this->where[] = (string)$predicate;

			return $qb;
		});

		return $qb;
	}

	public function testAHandleIsSearchedAsAPrefixOfItsLowercaseCopy(): void {
		$this->builder()->searchInAccount('Alice@Mastodon');

		$this->assertSame(["ca.account_lower LIKE 'alice@mastodon%'"], $this->where);
	}

	public function testWhatWasTypedIsNeverAPattern(): void {
		$this->builder()->searchInAccount('a_b%');

		$this->assertSame(["ca.account_lower LIKE 'a\\_b\\%%'"], $this->where);
	}

	public function testTheLowercaseFormFoldsMoreThanAscii(): void {
		$this->assertSame('ärger@host.example', CacheActorsRequest::lowerAccount('ÄRGER@Host.Example'));
	}

	/** Every write of the handle writes its copy, and the lookup by handle compares the copy. */
	public function testTheCopyIsWrittenAndReadWhereTheHandleIs(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../lib/Db/CacheActorsRequest.php');

		foreach (['save', 'update'] as $method) {
			$body = preg_split('/\n\t\}\n/', preg_split('/function ' . $method . '\(/', $source, 2)[1] ?? '', 2)[0];
			$this->assertStringContainsString("'account_lower', \$qb->createNamedParameter(self::lowerAccount(\$actor->getAccount()))", $body, $method);
		}

		$body = preg_split('/\n\t\}\n/', preg_split('/function getFromAccount\(/', $source, 2)[1] ?? '', 2)[0];
		$this->assertStringContainsString("limitToDBField('account_lower', self::lowerAccount(\$account))", $body);
		$this->assertStringNotContainsString('limitToAccount(', $body);
	}
}
