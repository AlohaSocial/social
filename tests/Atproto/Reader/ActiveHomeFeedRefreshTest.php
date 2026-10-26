<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Reader\ActiveHomeFeedRefresh;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FollowService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class ActiveHomeFeedRefreshTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	public function testAnUnavailableRefreshThrottleCannotFailTheHomeFeed(): void {
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$cache = $this->createMock(DurableCache::class);
		$cache->expects(self::once())
			->method('addShared')
			->with('social.atproto.home-refresh', self::anything(), '1', ActiveHomeFeedRefresh::COOLDOWN)
			->willThrowException(new RuntimeException('cache unavailable'));

		$refresh = new ActiveHomeFeedRefresh(
			$config,
			$this->createStub(FollowService::class),
			$this->createStub(AtprotoWatchRequest::class),
			$this->createStub(BlueskyActorService::class),
			$this->createStub(Blocklist::class),
			$cache,
			$this->createStub(ITimeFactory::class),
			new NullLogger(),
		);

		self::assertNull($refresh->wakeFollowedFeeds(new Person()), 'a cache failure is a best-effort refresh failure, not a timeline failure');
	}

	public function testFollowSamplingPersistsAStableKeysetCursor(): void {
		$viewer = new Person();
		$viewer->setId('https://social.example/@alice');
		$namespace = 'social.atproto.home-refresh';
		$cursorKey = 'follow-cursor:' . hash('sha256', $viewer->getId());
		$follows = [];
		for ($index = 0; $index < ActiveHomeFeedRefresh::FOLLOW_PAGE; $index++) {
			$follow = new Follow();
			$follow->setId('https://social.example/@alice/follows/' . $index);
			$follow->setCreation(2000 - $index);
			$follow->setObjectId('https://bsky.app/profile/' . self::DID);
			$follows[] = $follow;
		}
		$expectedCursor = FollowsRequest::cursorAfter($follows[array_key_last($follows)]);

		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$followService = $this->createMock(FollowService::class);
		$followService->expects(self::once())->method('getFollowingAfter')
			->with($viewer, ActiveHomeFeedRefresh::FOLLOW_PAGE, '')
			->willReturn($follows);
		$watches = $this->createMock(AtprotoWatchRequest::class);
		$watches->expects(self::once())->method('getByDids')->with([self::DID])->willReturn([]);
		$cache = $this->createMock(DurableCache::class);
		$cache->expects(self::once())->method('addShared')->with($namespace, hash('sha256', $viewer->getId()), '1', ActiveHomeFeedRefresh::COOLDOWN)->willReturn(true);
		$cache->expects(self::once())->method('getShared')->with($namespace, $cursorKey)->willReturn(null);
		$cache->expects(self::once())->method('setShared')->with($namespace, $cursorKey, $expectedCursor, 30 * 86400);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(2000);

		$refresh = new ActiveHomeFeedRefresh(
			$config,
			$followService,
			$watches,
			$this->createStub(BlueskyActorService::class),
			$this->createStub(Blocklist::class),
			$cache,
			$time,
			new NullLogger(),
		);

		$refresh->wakeFollowedFeeds($viewer);
	}

	public function testFollowSamplingWrapsToTheFirstPageWhenTheSavedCursorIsAtTheEnd(): void {
		$viewer = new Person();
		$viewer->setId('https://social.example/@alice');
		$namespace = 'social.atproto.home-refresh';
		$cursorKey = 'follow-cursor:' . hash('sha256', $viewer->getId());
		$cursor = '1800-' . str_repeat('a', 32);
		$follow = new Follow();
		$follow->setId('https://social.example/@alice/follows/last');
		$follow->setCreation(1700);
		$follow->setObjectId('https://bsky.app/profile/' . self::DID);

		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$followService = $this->createMock(FollowService::class);
		$followService->expects(self::exactly(2))->method('getFollowingAfter')
			->willReturnCallback(static function (Person $actor, int $limit, string $after) use (&$cursor, $follow): array {
				if ($after === $cursor) {
					return [];
				}
				self::assertSame('', $after, 'an exhausted keyset rotation restarts at the first page');
				return [$follow];
			});
		$watches = $this->createMock(AtprotoWatchRequest::class);
		$watches->expects(self::once())->method('getByDids')->with([self::DID])->willReturn([]);
		$cache = $this->createMock(DurableCache::class);
		$cache->expects(self::once())->method('addShared')->willReturn(true);
		$cache->expects(self::once())->method('getShared')->with($namespace, $cursorKey)->willReturnCallback(static function () use (&$cursor): string {
			return $cursor;
		});
		$cache->expects(self::once())->method('setShared')
			->with($namespace, $cursorKey, '', 30 * 86400)
			->willReturnCallback(static function (string $namespace, string $key, string $next) use (&$cursor): void {
				$cursor = $next;
			});
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(2000);

		$refresh = new ActiveHomeFeedRefresh(
			$config,
			$followService,
			$watches,
			$this->createStub(BlueskyActorService::class),
			$this->createStub(Blocklist::class),
			$cache,
			$time,
			new NullLogger(),
		);

		$refresh->wakeFollowedFeeds($viewer);
		self::assertSame('', $cursor, 'the stored cursor is cleared after wrapping');
	}
}
