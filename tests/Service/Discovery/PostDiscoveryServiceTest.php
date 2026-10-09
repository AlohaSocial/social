<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Discovery;

use OCA\Social\Atproto\Reader\BlueskyPostSource;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\Discovery\FediversePostSource;
use OCA\Social\Service\Discovery\PostDiscoveryService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PostDiscoveryServiceTest extends TestCase {
	public function testEveryNetworkIsAskedAsThePersonSearching(): void {
		$alice = (new Person())->setId('https://social.test/@alice');
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getFromId')->with($alice->getId())->willReturn($alice);
		$fediverse = $this->createMock(FediversePostSource::class);
		$fediverse->expects($this->once())->method('tagged')->with('nextcloud', PostDiscoveryService::LIMIT, null)->willReturn(3);
		$fediverse->expects($this->once())->method('matching')->with('open source', PostDiscoveryService::LIMIT, $alice)->willReturn(0);
		$bluesky = $this->createMock(BlueskyPostSource::class);
		$bluesky->method('tagged')->willReturn(4);
		$bluesky->expects($this->once())->method('matching')->with('open source', PostDiscoveryService::LIMIT, $alice)->willReturn(2);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[FediversePostSource::class, $fediverse], [BlueskyPostSource::class, $bluesky]]);
		$service = new PostDiscoveryService($cacheActors, new NullLogger(), $container);

		$this->assertSame(7, $service->fill(PostDiscoveryService::TAG, 'nextcloud'));
		$this->assertSame(2, $service->fill(PostDiscoveryService::SEARCH, 'open source', $alice->getId()));
	}
}
