<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\AccessBlocksRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Model\AccessBlock;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An INSERT a unique index may refuse, inside and outside a transaction.
 *
 * PostgreSQL aborts a whole transaction at its first failed statement, so a
 * refused row inside a repository batch must be rolled back to a savepoint of
 * its own; the rows that fall back to an update then run in a transaction
 * that still works.
 */
#[AllowMockObjectsWithoutExpectations]
class InsertUnlessDuplicateTest extends TestCase {
	/** @var list<string> the statements the save paths ran, by kind */
	private array $ran = [];

	private static function duplicate(): DBException {
		return new class('duplicate key') extends DBException {
			public function getReason(): ?int {
				return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
	}

	/** @param list<string> $calls the transaction calls, appended to */
	private function helper(bool $inTransaction, array &$calls): CoreRequestBuilder {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('inTransaction')->willReturn($inTransaction);
		foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
			$connection->method($method)->willReturnCallback(function () use ($method, &$calls): bool {
				$calls[] = $method;

				return true;
			});
		}

		return new class($connection, new NullLogger(), $this->createStub(IURLGenerator::class), $this->createStub(ConfigService::class), $this->createStub(MiscService::class)) extends CoreRequestBuilder {
			public function insert(IQueryBuilder $insert): bool {
				return $this->insertUnlessDuplicate($insert);
			}
		};
	}

	private function insert(?\Throwable $failure, array &$calls): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('executeStatement')->willReturnCallback(function () use ($failure, &$calls): int {
			$calls[] = 'insert';
			if ($failure !== null) {
				throw $failure;
			}

			return 1;
		});

		return $qb;
	}

	public function testOutsideATransactionTheInsertRunsOnItsOwn(): void {
		$calls = [];
		$this->assertTrue($this->helper(false, $calls)->insert($this->insert(null, $calls)));
		$this->assertSame(['insert'], $calls);
	}

	public function testOutsideATransactionARefusedRowIsReportedNotThrown(): void {
		$calls = [];
		$this->assertFalse($this->helper(false, $calls)->insert($this->insert(self::duplicate(), $calls)));
		$this->assertSame(['insert'], $calls);
	}

	public function testInsideATransactionTheInsertGetsASavepointItReleases(): void {
		$calls = [];
		$this->assertTrue($this->helper(true, $calls)->insert($this->insert(null, $calls)));
		$this->assertSame(['beginTransaction', 'insert', 'commit'], $calls);
	}

	public function testInsideATransactionARefusedRowIsRolledBackToItsSavepoint(): void {
		$calls = [];
		$this->assertFalse($this->helper(true, $calls)->insert($this->insert(self::duplicate(), $calls)));
		$this->assertSame(['beginTransaction', 'insert', 'rollBack'], $calls, 'the enclosing transaction stays usable');
	}

	public function testAnyOtherFailureIsRolledBackToItsSavepointAndThrown(): void {
		$calls = [];
		$failure = new RuntimeException('disk full');
		try {
			$this->helper(true, $calls)->insert($this->insert($failure, $calls));
			$this->fail('the failure was swallowed');
		} catch (RuntimeException $e) {
			$this->assertSame($failure, $e);
		}
		$this->assertSame(['beginTransaction', 'insert', 'rollBack'], $calls);
	}

	/**
	 * A request whose INSERT the unique index refuses or not, recording every
	 * other statement it runs.
	 *
	 * @template T of CoreRequestBuilder
	 * @param class-string<T> $class
	 * @return T
	 */
	private function request(string $class, bool $inserted): CoreRequestBuilder {
		$request = $this->getMockBuilder($class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder', 'insertUnlessDuplicate'])
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(fn (): SocialQueryBuilder => $this->builder());
		$request->method('insertUnlessDuplicate')->willReturnCallback(function () use ($inserted): bool {
			$this->ran[] = 'insert';

			return $inserted;
		});

		return $request;
	}

	private function builder(): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$kind = 'statement';
		foreach (['insert', 'update'] as $method) {
			$qb->method($method)->willReturnCallback(function () use ($qb, $method, &$kind): SocialQueryBuilder {
				$kind = $method;

				return $qb;
			});
		}
		foreach (['setValue', 'set', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturn($qb);
		}
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => md5($id));
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => is_scalar($value) ? (string)$value : ':p');
		$qb->method('executeStatement')->willReturnCallback(function () use (&$kind): int {
			$this->ran[] = $kind;

			return 1;
		});

		return $qb;
	}

	public static function saves(): array {
		return [
			'stream action' => [static function (CoreRequestBuilder $request): void {
				$action = new StreamAction('https://cloud.example/@alice', 'https://remote.example/notes/1');
				$action->updateValueBool(StreamAction::LIKED, true);
				/** @var StreamActionsRequest $request */
				$request->save($action);
			}, StreamActionsRequest::class],
			'actor relation' => [static function (CoreRequestBuilder $request): void {
				/** @var ActorRelationRequest $request */
				$request->save('https://cloud.example/@alice', 'https://remote.example/@bob', 'mute', false);
			}, ActorRelationRequest::class],
			'mute expiry' => [static function (CoreRequestBuilder $request): void {
				/** @var MuteExpiryRequest $request */
				$request->save('https://cloud.example/@alice', 'https://remote.example/@bob', 2_000_000_000);
			}, MuteExpiryRequest::class],
			'access block' => [static function (CoreRequestBuilder $request): void {
				/** @var AccessBlocksRequest $request */
				$request->save(new AccessBlock(AccessBlock::TYPE_IP, '192.0.2.0/24'));
			}, AccessBlocksRequest::class],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('saves')]
	public function testARowThatIsThereAlreadyIsUpdatedAfterTheRefusedInsert(\Closure $save, string $class): void {
		$save($this->request($class, false));

		$this->assertSame(['insert', 'update'], $this->ran);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('saves')]
	public function testANewRowIsOnlyInserted(\Closure $save, string $class): void {
		$save($this->request($class, true));

		$this->assertSame(['insert'], $this->ran);
	}
}
