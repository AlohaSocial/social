<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Moderation;

use InvalidArgumentException;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Moderation\BlocklistManager;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Db\AtprotoBlocklistRequest;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ModerationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlocklistTest extends TestCase {
	private const ALICE = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	/** @var list<array{kind: string, value: string, reason: string, creation: int}> */
	private array $rows = [];
	/** @var AtprotoBlocklistRequest&MockObject */
	private AtprotoBlocklistRequest $request;
	private Blocklist $blocklist;

	protected function setUp(): void {
		$this->request = $this->createMock(AtprotoBlocklistRequest::class);
		$this->request->method('getAll')->willReturnCallback(fn (): array => $this->rows);
		$this->request->method('add')->willReturnCallback(function (string $kind, string $value, string $reason): bool {
			$this->rows[] = ['kind' => $kind, 'value' => $value, 'reason' => $reason, 'creation' => 1];

			return true;
		});
		$this->request->method('remove')->willReturnCallback(function (string $kind, string $value): bool {
			$before = count($this->rows);
			$this->rows = array_values(array_filter($this->rows, static fn (array $r): bool => !($r['kind'] === $kind && $r['value'] === $value)));

			return count($this->rows) < $before;
		});
		$this->blocklist = new Blocklist($this->request);
	}

	public function testATargetIsADidOrAHost(): void {
		$this->assertSame(['did', self::ALICE], Blocklist::classify(' ' . strtoupper('DID:PLC:') . 'ewvi7nxzyoun6zhxrhs64oiz '));
		$this->assertSame(['host', 'pds.example.com'], Blocklist::classify('PDS.example.com'));
		$this->assertSame(['host', 'pds.example.com'], Blocklist::classify('https://pds.example.com/xrpc/x'));
		$this->expectException(InvalidArgumentException::class);
		Blocklist::classify('not a target');
	}

	public function testAHostBlocksItsAccountsAndTheHostsUnderIt(): void {
		$this->rows = [['kind' => 'host', 'value' => 'evil.example', 'reason' => '', 'creation' => 1], ['kind' => 'did', 'value' => self::BOB, 'reason' => '', 'creation' => 1]];

		$this->assertTrue($this->blocklist->isBlockedHost('evil.example'));
		$this->assertTrue($this->blocklist->isBlockedHost('pds.evil.example'), 'a host under a blocked one');
		$this->assertFalse($this->blocklist->isBlockedHost('notevil.example'));
		$this->assertTrue($this->blocklist->isBlockedAccount(self::ALICE, 'https://pds.evil.example'));
		$this->assertFalse($this->blocklist->isBlockedAccount(self::ALICE, 'https://pds.good.example'));
		$this->assertTrue($this->blocklist->isBlockedAccount(self::BOB));
		$actor = new Person();
		$actor->setId('https://bsky.app/profile/' . self::ALICE);
		$actor->setDetailArray(ActorMapper::DETAIL, ['pds' => 'https://pds.evil.example']);
		$this->assertTrue($this->blocklist->isBlockedActor($actor));
		$fediverse = new Person();
		$fediverse->setId('https://evil.example/users/x');
		$this->assertFalse($this->blocklist->isBlockedActor($fediverse), 'a Fediverse account is the domain block\'s business');
	}

	public function testBlockingPurgesTheFollowedAccountsOfItAndUnblockingLiftsIt(): void {
		$watches = $this->createMock(AtprotoWatchRequest::class);
		$watches->method('getAll')->willReturn([new Watch(self::ALICE, 'alice.evil.example', '', 0, 0, 0, '', 0), new Watch(self::BOB, 'bob.good.example', '', 0, 0, 0, '', 0)]);
		$cache = $this->createMock(CacheActorsRequest::class);
		$cache->method('getFromId')->willReturnCallback(static function (string $id): Person {
			$p = new Person();
			$p->setId($id);
			$p->setDetailArray(ActorMapper::DETAIL, ['pds' => str_contains($id, self::ALICE) ? 'https://pds.evil.example' : 'https://pds.good.example']);

			return $p;
		});
		$moderation = $this->createMock(ModerationService::class);
		$moderation->expects($this->once())->method('purgeActor')->with('https://bsky.app/profile/' . self::ALICE);
		$watches->expects($this->once())->method('remove')->with(self::ALICE);
		$manager = new BlocklistManager($this->blocklist, $this->request, $watches, $cache, $moderation, new NullLogger());

		$this->assertSame(1, $manager->block('evil.example', 'spam host'));
		$this->assertTrue($this->blocklist->isBlockedAccount(self::ALICE, 'https://pds.evil.example'), 'the change is seen at once');
		$this->assertSame('spam host', $this->blocklist->list()[0]['reason']);
		$this->assertTrue($manager->unblock('evil.example'));
		$this->assertFalse($this->blocklist->isBlockedHost('evil.example'));
		$this->assertFalse($manager->unblock('evil.example'));
	}

	public function testBlockingAnUnfollowedButCachedAccountPurgesIt(): void {
		$watches = $this->createMock(AtprotoWatchRequest::class);
		$watches->method('getAll')->willReturn([]);
		$cache = $this->createMock(CacheActorsRequest::class);
		$cache->method('getFromId')->willReturnCallback(static fn (string $id): Person => str_contains($id, self::BOB) ? new Person() : throw new CacheActorDoesNotExistException());
		$moderation = $this->createMock(ModerationService::class);
		$moderation->expects($this->once())->method('purgeActor')->with('https://bsky.app/profile/' . self::BOB);
		$manager = new BlocklistManager($this->blocklist, $this->request, $watches, $cache, $moderation, new NullLogger());

		$this->assertSame(1, $manager->block(self::BOB));
		$this->assertSame(0, $manager->block(self::ALICE), 'not here: nothing to purge');
	}
}
