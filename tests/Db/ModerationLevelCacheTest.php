<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\Moderation;
use OCP\DB\IResult;
use OCP\ICache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The silenced accounts are read on every public timeline page, so they are
 * cached, and every moderation decision drops the cache.
 */
#[AllowMockObjectsWithoutExpectations]
class ModerationLevelCacheTest extends TestCase {
	/** @var array<string, mixed> */
	private array $cached = [];
	private int $reads = 0;
	/** @var string[] */
	private array $rows = ['https://a.example/users/x'];

	private function request(): ModerationRequest {
		$request = $this->getMockBuilder(ModerationRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(function (): SocialQueryBuilder {
			$qb = $this->createMock(SocialQueryBuilder::class);
			foreach (['select', 'from', 'where', 'delete', 'insert', 'setValue'] as $method) {
				$qb->method($method)->willReturnSelf();
			}
			$qb->method('expr')->willReturn(new FakeExpressions());
			$qb->method('createNamedParameter')->willReturnArgument(0);
			$qb->method('prim')->willReturnCallback('md5');
			$qb->method('executeQuery')->willReturnCallback(function (): IResult {
				$this->reads++;
				$rows = array_map(static fn (string $id): array => ['actor_id' => $id], $this->rows);
				$result = $this->createStub(IResult::class);
				$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
					return array_shift($rows) ?? false;
				});

				return $result;
			});

			return $qb;
		});

		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->cached[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->cached[$key] = $value;

			return true;
		});
		$cache->method('remove')->willReturnCallback(function (string $key): bool {
			unset($this->cached[$key]);

			return true;
		});
		(new \ReflectionProperty(ModerationRequest::class, 'cache'))->setValue($request, $cache);

		return $request;
	}

	public function testTheListIsReadOnceAndDroppedByADecision(): void {
		$request = $this->request();

		$this->assertSame(['https://a.example/users/x'], $request->getActorIdsAt(Moderation::SILENCE));
		$this->assertSame(['https://a.example/users/x'], $request->getActorIdsAt(Moderation::SILENCE));
		$this->assertSame(1, $this->reads);

		$this->rows = [];
		$request->delete('https://a.example/users/x');

		$this->assertSame([], $request->getActorIdsAt(Moderation::SILENCE));
		$this->assertSame(2, $this->reads);
	}
}
