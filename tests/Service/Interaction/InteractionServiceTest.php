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
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class InteractionServiceTest extends TestCase {
	private static function person(string $id): Person {
		return (new Person())->setId($id);
	}

	public function testAReactionProfileWithoutAnAvatarIsRefreshedOutsideTheRequest(): void {
		$post = (new Note())->setId('https://social.test/@alice/1');
		$actorId = 'https://bsky.app/profile/did:plc:ann';
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([self::person($actorId)]);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->method('fillInteractions')->willReturn(false);
		$queue->expects($this->once())->method('refreshActors')->with([$actorId]);
		$service = new InteractionService(
			$actions,
			$this->createMock(CacheActorService::class),
			$this->createMock(StreamRequest::class),
			$durableCache,
			$queue,
			new NullLogger(),
		);

		$result = $service->reactedBy($post, 'Like', 10);

		$this->assertSame([$actorId], array_map(static fn (Person $actor): string => $actor->getId(), $result['accounts']));
	}

	public function testVisibleFediverseReactionPagesRemainAvailableWhenTheCollectionIsIncomplete(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$actorId = 'https://remote.example/users/ann';
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([]);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getCachedFromIds')->willReturnCallback(static fn (array $ids): array => array_combine($ids, array_map(static fn (string $id): Person => self::person($id), $ids)));
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->exactly(2))->method('fillInteractions')->with($post, 'Like')->willReturn(true);
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(true);
		$fediverse->expects($this->once())->method('actors')->with($post, 'Like', InteractionService::LIMIT, 3)->willReturn([$actorId]);
		$fediverse->expects($this->once())->method('hasMoreActors')->with($post, 'Like')->willReturn(true);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubInteractionSource::class, $fediverse], [BlueskyInteractionSource::class, $bluesky]]);
		$service = new InteractionService($actions, $cacheActors, $this->createMock(StreamRequest::class), $durableCache, $queue, new NullLogger(), $container);

		$result = $service->reactedBy($post, 'Like', 10);

		$this->assertSame([$actorId], array_map(static fn (Person $actor): string => $actor->getId(), $result['accounts']));
		$this->assertTrue($result['filling'], 'the partial list is shown while its background continuation is queued');
		$this->assertTrue($service->reactedBy($post, 'Like', 10)['filling'], 'a later visible poll can resume an incomplete background fill');
	}

	public function testOpeningReactionListReadsTheNetworkBeforeReturningAndKeepsLocalActorsFirst(): void {
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
		$queue->expects($this->once())->method('fillInteractions')->with($post, InteractionService::QUOTES)->willReturn(true);
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(false);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->exactly(2))->method('actors')
			->willReturnCallback(function (Note $seenPost, string $type, int $limit, ?int $timeout) use ($post): array {
				$this->assertSame($post, $seenPost);
				$this->assertContains($type, ['Like', 'Announce']);
				$this->assertSame(InteractionService::LIMIT, $limit);
				$this->assertSame(3, $timeout);

				return $type === 'Like'
					? ['https://bsky.app/profile/did:plc:carol', 'https://social.test/@bob']
					: [];
			});
		$bluesky->expects($this->once())->method('quotes')->with($post, InteractionService::LIMIT, 3)->willReturn(2);
		$bluesky->expects($this->once())->method('hasMoreQuotes')->with($post)->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubInteractionSource::class, $fediverse], [BlueskyInteractionSource::class, $bluesky]]);
		$service = new InteractionService($actions, $cacheActors, $streams, $durableCache, $queue, new NullLogger(), $container);

		$this->assertSame(
			['https://social.test/@bob', 'https://bsky.app/profile/did:plc:carol'],
			array_map(static fn (Person $p): string => $p->getId(), $service->reactedBy($post, 'Like', 10)['accounts']),
			'the visible request reads reactions before returning',
		);
		$this->assertTrue($service->askForQuotes($post), 'later quote pages are queued after the first visible page');

		$this->assertSame(
			['https://social.test/@bob', 'https://bsky.app/profile/did:plc:carol'],
			array_map(static fn (Person $p): string => $p->getId(), $service->reactedBy($post, 'Like', 10)['accounts']),
		);
		$this->assertSame(['https://social.test/@bob'], array_map(static fn (Person $p): string => $p->getId(), $service->reactedBy($post, 'Like', 1)['accounts']), 'within the limit');
		$this->assertSame([], array_map(static fn (Person $p): string => $p->getId(), array_slice($service->reactedBy($post, 'Announce', 10)['accounts'], 1)), 'each kind its own list');
	}

	public function testVisibleReactionsShareOneTimeoutBudgetAcrossFediverseAndBluesky(): void {
		$post = (new Note())->setId('https://social.test/@alice/dual-published');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($post);
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([]);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->never())->method('fillInteractions');
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(true);
		$fediverse->expects($this->once())->method('actors')->willReturnCallback(
			static function (Note $post, string $type, int $limit, ?int $timeout): array {
				usleep(1_200_000);

				return [];
			},
		);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->once())->method('actors')->willReturnCallback(
			function (Note $post, string $type, int $limit, ?int $timeout): array {
				$this->assertLessThan(3, $timeout, 'the second protocol receives only the remaining request budget');
				$this->assertGreaterThan(0, $timeout);

				return [];
			},
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubInteractionSource::class, $fediverse], [BlueskyInteractionSource::class, $bluesky]]);
		$service = new InteractionService(
			$actions,
			$this->createMock(CacheActorService::class),
			$streams,
			$durableCache,
			$queue,
			new NullLogger(),
			$container,
		);

		$this->assertFalse($service->reactedBy($post, 'Like', 10)['filling']);
	}

	public function testOpeningFediverseReactionListResolvesMissingProfilesWithinItsVisibleBudget(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$actorId = 'https://remote.example/users/ann';
		$actor = self::person($actorId);
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([]);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cachedReads = 0;
		$cacheActors->method('getCachedFromIds')->willReturnCallback(static function (array $ids) use (&$cachedReads, $actorId, $actor): array {
			$cachedReads++;

			return $cachedReads === 1 ? [] : [$actorId => $actor];
		});
		$cacheActors->expects($this->once())->method('getActivityPubActorWithTimeout')->with(
			$actorId,
			$this->logicalAnd($this->greaterThan(0), $this->lessThanOrEqual(3)),
		)->willReturn($actor);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->method('fillInteractions')->willReturn(false);
		$queue->expects($this->never())->method('resolveActors');
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(true);
		$fediverse->method('actors')->willReturn([$actorId]);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[ActivityPubInteractionSource::class, $fediverse],
			[BlueskyInteractionSource::class, $bluesky],
		]);
		$service = new InteractionService(
			$actions,
			$cacheActors,
			$this->createMock(StreamRequest::class),
			$durableCache,
			$queue,
			new NullLogger(),
			$container,
		);

		$result = $service->reactedBy($post, 'Like', 10);

		$this->assertSame([$actorId], array_map(static fn (Person $person): string => $person->getId(), $result['accounts']));
	}

	public function testMissingListedActorsKeepTheAvatarListFillingUntilTheirProfilesAreCached(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($post);
		$actions = $this->createMock(ActionService::class);
		$cacheActors = $this->createMock(CacheActorService::class);
		$resolved = false;
		$actorId = 'https://remote.example/users/alice';
		$cacheActors->method('getCachedFromIds')->willReturnCallback(static function (array $ids) use (&$resolved, $actorId): array {
			return $resolved ? [$actorId => self::person($actorId)] : [];
		});
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->method('fillInteractions')->willReturn(false);
		$queue->expects($this->once())->method('resolveActors')->with([$actorId]);
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(true);
		$fediverse->method('actors')->with($post, 'Like', InteractionService::LIMIT)->willReturn([$actorId]);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubInteractionSource::class, $fediverse], [BlueskyInteractionSource::class, $bluesky]]);
		$service = new InteractionService($actions, $cacheActors, $streams, $durableCache, $queue, new NullLogger(), $container);

		$service->fill($post->getId(), 'Like');
		$waiting = $service->reactedBy($post, 'Like', 12);
		$this->assertSame([], $waiting['accounts']);
		$this->assertTrue($waiting['filling']);

		$resolved = true;
		$ready = $service->reactedBy($post, 'Like', 12);
		$this->assertSame([$actorId], array_map(static fn (Person $person): string => $person->getId(), $ready['accounts']));
		$this->assertFalse($ready['filling']);
	}

	public function testAConcurrentVisibleReadUsesTheInFlightListInsteadOfAskingAgain(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([]);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->never())->method('fillInteractions');
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('isLocked')->with(
			'social-visible-reactions-' . md5('Like' . "\0" . $post->getId()),
			ILockingProvider::LOCK_EXCLUSIVE,
		)->willReturn(true);
		$service = new InteractionService(
			$actions,
			$this->createMock(CacheActorService::class),
			$this->createMock(StreamRequest::class),
			$durableCache,
			$queue,
			new NullLogger(),
			$this->createMock(ContainerInterface::class),
			$locks,
		);

		$this->assertSame(['accounts' => [], 'filling' => true], $service->reactedBy($post, 'Like', 12));
	}

	public function testAReadReactionListStandsForTheWindowOfThePostsAge(): void {
		$now = 1790000000;
		$post = (new Note())->setId('https://remote.example/notes/1');
		$post->setPublishedTime($now - 3 * 86400);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static function () use (&$now): int {
			return $now;
		});
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([]);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getCachedFromIds')->willReturn([]);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->exactly(2))->method('actors')->willReturn([]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[ActivityPubInteractionSource::class, $this->createStub(ActivityPubInteractionSource::class)],
			[BlueskyInteractionSource::class, $bluesky],
		]);
		$service = new InteractionService(
			$actions, $cacheActors, $this->createMock(StreamRequest::class), $durableCache,
			$this->createMock(RemoteFetchQueue::class), new NullLogger(), $container, null, $time
		);

		$service->reactedBy($post, 'Like', 12);
		$now += 600;
		$service->reactedBy($post, 'Like', 12);
		$now += 300;
		$service->reactedBy($post, 'Like', 12);
	}

	public function testFailedVisibleReadRetriesOnTheVisibleRefreshCadence(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$actorId = 'https://remote.example/users/alice';
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$now = 1790000000;
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static function () use (&$now): int {
			return $now;
		});
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->with($post->getId())->willReturn($post);
		$actions = $this->createMock(ActionService::class);
		$actions->method('reactedBy')->willReturn([]);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getCachedFromIds')->willReturnCallback(static fn (array $ids): array => array_combine($ids, array_map(static fn (string $id): Person => self::person($id), $ids)));
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->exactly(2))->method('fillInteractions')->with($post, 'Like')->willReturnOnConsecutiveCalls(true, false);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->exactly(2))->method('actors')->willReturnCallback(static function () use ($actorId): array {
			static $calls = 0;
			if (++$calls === 1) {
				throw new \RuntimeException('AppView unavailable');
			}

			return [$actorId];
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[ActivityPubInteractionSource::class, $this->createStub(ActivityPubInteractionSource::class)],
			[BlueskyInteractionSource::class, $bluesky],
		]);
		$service = new InteractionService($actions, $cacheActors, $streams, $durableCache, $queue, new NullLogger(), $container);

		$waiting = $service->reactedBy($post, 'Like', 12);
		$this->assertSame([], $waiting['accounts']);
		$this->assertTrue($waiting['filling']);
		$this->assertTrue($service->reactedBy($post, 'Like', 12)['filling'], 'polling does not repeat the failed AppView request while the queued fill is active');

		$now += \OCA\Social\Service\Counts\CountService::VISIBLE_INTERVAL;
		$ready = $service->reactedBy($post, 'Like', 12);
		$this->assertSame([$actorId], array_map(static fn (Person $person): string => $person->getId(), $ready['accounts']));
		$this->assertFalse($ready['filling'], 'a visible poll retries after the short pending window');
	}

	public function testVisibleReadKeepsActorsFromAProtocolThatAnsweredWhenAnotherFails(): void {
		$post = (new Note())->setId('https://social.test/@alice/dual-published');
		$actorId = 'https://remote.example/users/bob';
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->once())->method('fillInteractions')->with($post, 'Like')->willReturn(true);
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(true);
		$fediverse->expects($this->once())->method('actors')->with($post, 'Like', InteractionService::LIMIT, 3)->willReturn([$actorId]);
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->once())->method('actors')->willReturnCallback(
			function (Note $seenPost, string $type, int $limit, ?int $timeout) use ($post): array {
				$this->assertSame($post, $seenPost);
				$this->assertSame('Like', $type);
				$this->assertSame(InteractionService::LIMIT, $limit);
				$this->assertGreaterThan(0, $timeout);
				$this->assertLessThanOrEqual(3, $timeout);

				throw new \RuntimeException('AppView unavailable');
			},
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[ActivityPubInteractionSource::class, $fediverse],
			[BlueskyInteractionSource::class, $bluesky],
		]);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getCachedFromIds')->willReturnCallback(
			static fn (array $ids): array => array_combine($ids, array_map(static fn (string $id): Person => self::person($id), $ids)),
		);
		$service = new InteractionService(
			$this->createMock(ActionService::class),
			$cacheActors,
			$this->createMock(StreamRequest::class),
			$durableCache,
			$queue,
			new NullLogger(),
			$container,
		);

		$result = $service->reactedBy($post, 'Like', 10);

		$this->assertSame([$actorId], array_map(static fn (Person $person): string => $person->getId(), $result['accounts']));
		$this->assertTrue($result['filling'], 'the failed protocol remains queued while the successful protocol is visible');
	}

	public function testFailedBackgroundRefreshKeepsTheLastKnownReactionActors(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$oldActor = 'https://remote.example/users/alice';
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->with($post->getId())->willReturn($post);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$key = md5('Like' . "\0" . $post->getId());
		$durableCache->setShared('social.reactors', $key, [$oldActor], 3600);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->never())->method('fillInteractions');
		$bluesky = $this->createMock(BlueskyInteractionSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->once())->method('actors')->willThrowException(new \RuntimeException('AppView unavailable'));
		$fediverse = $this->createMock(ActivityPubInteractionSource::class);
		$fediverse->method('supports')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[ActivityPubInteractionSource::class, $fediverse],
			[BlueskyInteractionSource::class, $bluesky],
		]);
		$service = new InteractionService(
			$this->createMock(ActionService::class),
			$this->createMock(CacheActorService::class),
			$streams,
			$durableCache,
			$queue,
			new NullLogger(),
			$container,
		);

		$service->fill($post->getId(), 'Like');

		$this->assertSame([$oldActor], $durableCache->getShared('social.reactors', $key));
	}
}
