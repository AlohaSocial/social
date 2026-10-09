<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Interaction;

use OCA\Social\Atproto\Reader\BlueskyInteractionSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\Interaction\ActivityPubInteractionSource;
use OCA\Social\Service\Interaction\InteractionService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class InteractionServiceTest extends TestCase {
	private static function person(string $id): Person {
		return (new Person())->setId($id);
	}

	public function testThoseFromHereFirstThenWhoTheNetworksListedWithNothingTwice(): void {
		$post = (new Note())->setId('https://social.test/@alice/1');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($post);
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([self::person('https://social.test/@bob')]);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getCachedFromIds')->willReturnCallback(static fn (array $ids): array => array_combine($ids, array_map(static fn (string $id): Person => self::person($id), $ids)));
		// a memcache only the background job's process sees, as APCu is: what
		// it listed must still reach the web request
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($this->createStub(ICache::class));
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->method('fillInteractions')->willReturn(false);
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(false);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->method('actors')->willReturn(['https://bsky.app/profile/did:plc:carol', 'https://social.test/@bob']);
		$bluesky->expects($this->once())->method('quotes')->with($post, InteractionService::LIMIT)->willReturn(2);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubInteractionSource::class, $fediverse], [BlueskyInteractionSource::class, $bluesky]]);
		$service = new InteractionService($actions, $cacheActors, $streams, $durableCache, $queue, new NullLogger(), $container);

		$this->assertSame(['https://social.test/@bob'], array_map(static fn (Person $p): string => $p->getId(), $service->reactedBy($post, 'Like', 10)['accounts']), 'nothing read yet');
		$service->fill($post->getId(), 'Like');
		$service->fill($post->getId(), InteractionService::QUOTES);

		$this->assertSame(
			['https://social.test/@bob', 'https://bsky.app/profile/did:plc:carol'],
			array_map(static fn (Person $p): string => $p->getId(), $service->reactedBy($post, 'Like', 10)['accounts']),
		);
		$this->assertSame(['https://social.test/@bob'], array_map(static fn (Person $p): string => $p->getId(), $service->reactedBy($post, 'Like', 1)['accounts']), 'within the limit');
		$this->assertSame([], array_map(static fn (Person $p): string => $p->getId(), array_slice($service->reactedBy($post, 'Announce', 10)['accounts'], 1)), 'each kind its own list');
	}
}
