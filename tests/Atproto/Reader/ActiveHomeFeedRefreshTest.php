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
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FollowService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class ActiveHomeFeedRefreshTest extends TestCase {
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
}
