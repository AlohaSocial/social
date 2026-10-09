<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\FollowList;

use OCA\Social\Atproto\Reader\BlueskyFollowListSource;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FollowList\ActivityPubFollowListSource;
use OCA\Social\Service\FollowList\FollowListService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class FollowListServiceTest extends TestCase {
	private const BOB = 'https://remote.example/users/bob';

	private Person $bob;
	/** @var array<string, Person> what is cached here, by id */
	private array $cached = [];
	/** @var Person[] what this server knows follows bob */
	private array $local = [];
	private CacheActorService&MockObject $cacheActors;
	private FollowsRequest&MockObject $follows;
	private RemoteFetchQueue&MockObject $queue;
	private ActivityPubFollowListSource&MockObject $fediverse;
	private BlueskyFollowListSource&MockObject $bluesky;
	private FollowListService $service;

	protected function setUp(): void {
		$this->bob = self::person(self::BOB, 1);
		$this->cached = [self::BOB => $this->bob];
		foreach (['ann' => 30, 'cy' => 12, 'dee' => 11, 'eve' => 10, 'fay' => 9] as $name => $nid) {
			$this->cached['https://other.example/users/' . $name] = self::person('https://other.example/users/' . $name, $nid);
		}
		$this->cached['https://other.example/users/nonid'] = self::person('https://other.example/users/nonid', 0);
		$this->local = [$this->cached['https://other.example/users/ann']];

		$this->cacheActors = $this->createMock(CacheActorService::class);
		$this->cacheActors->method('getCachedFromIds')->willReturnCallback(fn (array $ids): array => array_intersect_key($this->cached, array_flip($ids)));
		$this->cacheActors->method('probeActors')->willReturnCallback(fn (ProbeOptions $o): array => array_values(array_filter(
			$this->local,
			static fn (Person $p): bool => (string)$o->getMaxId() === '0' || $p->getNid() < $o->getMaxId(),
		)));
		$this->follows = $this->createMock(FollowsRequest::class);
		$this->follows->method('getBetweenMany')->willReturnCallback(function (string $actorId, array $others): array {
			$followedBy = [];
			foreach ($this->local as $person) {
				if (in_array($person->getId(), $others, true)) {
					$followedBy[$person->getId()] = (new Follow())->setAccepted(true);
				}
			}

			return ['following' => [], 'followedBy' => $followedBy];
		});
		// a memcache only the background job's process sees, as APCu is: what
		// it listed must still reach the web request
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($this->createStub(ICache::class));
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$this->queue = $this->createMock(RemoteFetchQueue::class);
		$this->fediverse = $this->createMock(ActivityPubFollowListSource::class);
		$this->bluesky = $this->createMock(BlueskyFollowListSource::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubFollowListSource::class, $this->fediverse], [BlueskyFollowListSource::class, $this->bluesky]]);
		$this->service = new FollowListService($this->cacheActors, $this->follows, $durableCache, $this->queue, new NullLogger(), $container);
	}

	private static function person(string $id, int $nid): Person {
		$person = (new Person())->setId($id);
		$person->setNid($nid);

		return $person;
	}

	private static function options(int $limit, int|string $maxId = 0, int|string $minId = 0): ProbeOptions {
		return (new ProbeOptions())->setProbe(ProbeOptions::FOLLOWERS)->setAccountId(self::BOB)->setLimit($limit)->setMaxId($maxId)->setMinId($minId);
	}

	/** @param array{accounts: Person[], next: string|null, filling: bool} $page */
	private static function names(array $page): array {
		return array_map(static fn (Person $p): string => basename($p->getId()), $page['accounts']);
	}

	private function listed(?array $ids): void {
		$this->bluesky->method('supports')->willReturn(false);
		$this->fediverse->method('supports')->willReturn(true);
		$this->fediverse->method('accounts')->with($this->bob, FollowListService::FOLLOWERS, FollowListService::LIMIT)->willReturn($ids);
		$this->service->fill(self::BOB, FollowListService::FOLLOWERS);
	}

	public function testThoseKnownHereFirstThenWhoTheNetworkListedWithNothingTwice(): void {
		$this->queue->method('fillFollowList')->willReturn(true);
		$first = $this->service->page($this->bob, FollowListService::FOLLOWERS, self::options(5));
		$this->assertSame(['ann'], self::names($first), 'nothing read yet');
		$this->assertNull($first['next'], 'paged as always');
		$this->assertTrue($first['filling']);

		$this->listed([
			'https://other.example/users/cy',
			'https://other.example/users/ann',
			self::BOB,
			'https://other.example/users/nobody-cached',
			'https://other.example/users/nonid',
			'https://other.example/users/dee',
		]);
		$page = $this->service->page($this->bob, FollowListService::FOLLOWERS, self::options(5));

		$this->assertSame(['ann', 'cy', 'dee'], self::names($page), 'known here first; nobody twice, not the account itself, only who can be shown');
		$this->assertSame('', $page['next'], 'the end of the list');
	}

	public function testAPageReachingIntoTheListedOnesIsContinuedAfterItsLastAccount(): void {
		$this->listed(array_map(static fn (string $n): string => 'https://other.example/users/' . $n, ['cy', 'dee', 'eve', 'fay']));

		$first = $this->service->page($this->bob, FollowListService::FOLLOWERS, self::options(2));
		$this->assertSame(['ann', 'cy'], self::names($first));
		$this->assertSame('12', $first['next']);

		$second = $this->service->page($this->bob, FollowListService::FOLLOWERS, self::options(2, $first['next']));
		$this->assertSame(['dee', 'eve'], self::names($second), 'after cy, whatever the ids');
		$this->assertSame('10', $second['next']);

		$third = $this->service->page($this->bob, FollowListService::FOLLOWERS, self::options(2, $second['next']));
		$this->assertSame(['fay'], self::names($third));
		$this->assertSame('', $third['next']);
	}

	public function testAFullPageOfThoseKnownHerePagesAsAlwaysAndTheListedOnesFollowIt(): void {
		$this->listed(['https://other.example/users/cy']);

		$first = $this->service->page($this->bob, FollowListService::FOLLOWERS, self::options(1));
		$this->assertSame(['ann'], self::names($first));
		$this->assertNull($first['next']);

		$second = $this->service->page($this->bob, FollowListService::FOLLOWERS, self::options(1, 30));
		$this->assertSame(['cy'], self::names($second), 'after the last account known here');
	}

	public function testANewerPageIsThoseKnownHereAlone(): void {
		$this->listed(['https://other.example/users/cy']);
		$this->local = [];

		$this->assertSame([], self::names($this->service->page($this->bob, FollowListService::FOLLOWERS, self::options(5, 0, 40))));
	}

	public function testAListTheAccountHidesWhereItLivesIsHiddenHere(): void {
		$this->listed(null);
		$this->cacheActors->expects($this->never())->method('probeActors');

		$page = $this->service->page($this->bob, FollowListService::FOLLOWERS, self::options(5));

		$this->assertSame([], $page['accounts'], 'not even those known here');
		$this->assertSame('', $page['next']);
	}

	public function testEachDirectionIsItsOwnList(): void {
		$this->listed(['https://other.example/users/cy']);

		$page = $this->service->page($this->bob, FollowListService::FOLLOWING, self::options(5));

		$this->assertSame(['ann'], self::names($page));
	}

	public function testOnlyAnAccountOnAnotherServerIsRead(): void {
		$this->cached[self::BOB] = $this->bob->setLocal(true);
		$this->fediverse->method('supports')->willReturn(true);
		$this->fediverse->expects($this->never())->method('accounts');

		$this->service->fill(self::BOB, FollowListService::FOLLOWERS);
		$this->service->fill('https://nobody.example/users/x', FollowListService::FOLLOWERS);
	}

	public function testANetworkThatFailsLeavesTheOthers(): void {
		$this->fediverse->method('supports')->willReturn(true);
		$this->fediverse->method('accounts')->willThrowException(new \RuntimeException('down'));
		$this->bluesky->method('supports')->willReturn(true);
		$this->bluesky->method('accounts')->willReturn(['https://other.example/users/cy']);
		$this->service->fill(self::BOB, FollowListService::FOLLOWERS);

		$this->assertSame(['ann', 'cy'], self::names($this->service->page($this->bob, FollowListService::FOLLOWERS, self::options(5))));
	}
}
