<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\ClearForeignFollowCollections;
use OCA\Social\Service\ConfigService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The repair step that empties the followers collection of a follow whose
 * collection is not on the followed actor's server.
 */
#[AllowMockObjectsWithoutExpectations]
class ClearForeignFollowCollectionsTest extends TestCase {
	private const MARKER = 'migration_foreign_follow_collections_cleared';

	private ConfigService&MockObject $configService;

	protected function setUp(): void {
		parent::setUp();
		$this->configService = $this->createMock(ConfigService::class);
	}

	private function repair(FakeConnection $connection): void {
		(new ClearForeignFollowCollections($connection, $this->configService))->run($this->createStub(IOutput::class));
	}

	/** @return array<string, string> a follow row */
	private static function row(string $idPrim, string $objectId, string $followId): array {
		return ['id_prim' => $idPrim, 'object_id' => $objectId, 'follow_id' => $followId];
	}

	public function testAFollowOfACollectionOnAnotherServerIsEmptied(): void {
		$connection = new FakeConnection([[
			self::row('a1', 'https://evil.example/users/x', 'https://victim.example/users/v/followers'),
			self::row('b2', 'https://evil.example/users/y', 'https://evil.example:8443/users/y/followers'),
		]]);

		$this->repair($connection);

		$writes = $connection->writes();
		$this->assertCount(2, $writes);
		$this->assertSame('social_follow', $writes[0]->table);
		$this->assertSame(['id_prim = a1'], $writes[0]->wheres);
		$this->assertSame(['follow_id' => '', 'follow_id_prim' => ''], $writes[0]->sets);
		$this->assertSame(['id_prim = b2'], $writes[1]->wheres, 'another port is another server');
	}

	public function testFollowsOfTheActorsOwnCollectionAreLeftAlone(): void {
		$connection = new FakeConnection([[
			self::row('a1', 'https://mastodon.social/users/alice', 'https://mastodon.social/users/alice/followers'),
			self::row('b2', 'https://bsky.app/profile/did:plc:abc', 'https://bsky.app/profile/did:plc:abc/followers'),
			// a Loopback row names the account itself
			self::row('c3', 'https://cloud.example/apps/social/@alice', 'https://cloud.example/apps/social/@alice'),
		]]);

		$this->repair($connection);

		$this->assertSame([], $connection->writes());
	}

	public function testRowsAreReadPagedOnThePrimaryKey(): void {
		$first = [];
		for ($i = 1; $i <= 500; $i++) {
			$first[] = self::row(sprintf('%032x', $i), 'https://remote.example/users/a', 'https://remote.example/users/a/followers');
		}
		$connection = new FakeConnection([$first, []]);

		$this->repair($connection);

		$reads = array_values(array_filter(
			$connection->queries,
			static fn (FakeQueryBuilder $query): bool => $query->statements === 0
		));
		$this->assertCount(2, $reads);
		$this->assertSame('social_follow', $reads[0]->table);
		$this->assertSame(['follow_id_prim <> ', 'id_prim > '], $reads[0]->wheres);
		$this->assertSame(['follow_id_prim <> ', 'id_prim > ' . sprintf('%032x', 500)], $reads[1]->wheres);
		$this->assertSame('id_prim asc', $reads[0]->orderBy);
	}

	public function testTheMarkerIsSetAndHonoured(): void {
		$this->configService->expects($this->once())->method('setAppValue')->with(self::MARKER, '1');
		$this->repair(new FakeConnection([[self::row('a1', 'https://evil.example/users/x', 'https://victim.example/users/v/followers')]]));

		$done = $this->createMock(ConfigService::class);
		$done->method('getAppValueInt')->with(self::MARKER)->willReturn(1);
		$connection = new FakeConnection([[self::row('a1', 'https://evil.example/users/x', 'https://victim.example/users/v/followers')]]);
		(new ClearForeignFollowCollections($connection, $done))->run($this->createStub(IOutput::class));
		$this->assertSame([], $connection->queries);
	}
}
