<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DomainPurgeService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\NotificationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Purging what a blocked instance already sent.
 *
 * The three properties an administrator has to be able to rely on are pinned
 * here: it is bounded (a pass never reads or deletes more than a batch), it is
 * idempotent (a pass finds its work by asking what is still stored, so
 * repeating one is free and an interrupted one resumes), and it terminates
 * only when all three places an account can be left behind are empty.
 */
#[AllowMockObjectsWithoutExpectations]
class DomainPurgeServiceTest extends TestCase {
	private const DOMAIN = 'spam.example';

	private CacheActorsRequest|Stub $cacheActorsRequest;
	private StreamRequest|Stub $streamRequest;
	private FollowsRequest|Stub $followsRequest;
	private ModerationService|MockObject $moderationService;
	private ConfigService|Stub $configService;
	private NotificationService|Stub $notificationService;

	/** @var array<int, array<string, mixed>> who was told their follows were cut */
	private array $severed = [];
	private DomainPurgeService $service;
	/** what the tables still hold, keyed by the source that reads them */
	private array $state = ['cached' => [], 'authors' => [], 'follows' => []];

	protected function setUp(): void {
		$this->cacheActorsRequest = $this->createStub(CacheActorsRequest::class);
		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->followsRequest = $this->createStub(FollowsRequest::class);
		$this->moderationService = $this->createMock(ModerationService::class);
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getSocialAddress')->willReturn('cloud.example');
		$this->configService->method('getCloudHost')->willReturn('cloud.example');

		$this->notificationService = $this->createStub(NotificationService::class);
		$this->notificationService->method('onRelationshipsSevered')->willReturnCallback(
			function (string $actorId, string $domain, int $lost): void {
				$this->severed[] = compact('actorId', 'domain', 'lost');
			}
		);

		$this->service = new DomainPurgeService(
			$this->cacheActorsRequest,
			$this->streamRequest,
			$this->followsRequest,
			$this->moderationService,
			$this->configService,
			$this->notificationService,
			new NullLogger()
		);
	}

	/**
	 * A stand-in for the tables: each source hands back what it still holds,
	 * and purging an account removes it from all of them — which is what the
	 * per-account deletes do for real.
	 *
	 * @param string[] $cached
	 * @param string[] $authors
	 * @param string[] $follows
	 */
	private function withRemaining(array $cached, array $authors = [], array $follows = []): void {
		$this->state = ['cached' => $cached, 'authors' => $authors, 'follows' => $follows];

		$page = function (string $key): callable {
			return function (string $domain, int $limit) use ($key): array {
				return array_slice(array_values($this->state[$key]), 0, $limit);
			};
		};

		$this->cacheActorsRequest->method('getIdsFromDomain')->willReturnCallback($page('cached'));
		$this->streamRequest->method('getAuthorsFromDomain')->willReturnCallback($page('authors'));
		$this->followsRequest->method('getActorIdsFromDomain')->willReturnCallback($page('follows'));

		$this->moderationService->method('purgeActor')
			->willReturnCallback(function (string $actorId): void {
				foreach ($this->state as $key => $ids) {
					$this->state[$key] = array_values(array_diff($ids, [$actorId]));
				}
			});
	}

	private function ids(string $prefix, int $count): array {
		return array_map(
			static fn (int $i): string => 'https://' . self::DOMAIN . '/users/' . $prefix . $i,
			range(1, $count)
		);
	}

	public function testEveryAccountOfTheDomainIsDetached(): void {
		$this->withRemaining($this->ids('a', 3));

		$this->assertSame(3, $this->service->purge(self::DOMAIN));
		$this->assertFalse($this->service->hasRemains(self::DOMAIN));
	}

	public function testAccountsAreFoundThroughTheirPostsAndFollowsToo(): void {
		// an actor can be gone from the cache and still have posts here, and a
		// follow row can name one this instance never cached at all
		$this->withRemaining(
			[],
			['https://' . self::DOMAIN . '/users/poster'],
			['https://' . self::DOMAIN . '/users/follower']
		);

		$purged = [];
		$this->moderationService->method('purgeActor')
			->willReturnCallback(function (string $actorId) use (&$purged): void {
				$purged[] = $actorId;
			});

		$this->service->purgeStep(self::DOMAIN);

		$this->assertSame(
			['https://' . self::DOMAIN . '/users/poster', 'https://' . self::DOMAIN . '/users/follower'],
			$purged
		);
	}

	public function testAnAccountNamedByTwoSourcesIsDetachedOnce(): void {
		$actor = 'https://' . self::DOMAIN . '/users/both';
		$this->withRemaining([$actor], [$actor], [$actor]);

		$this->moderationService->expects($this->once())->method('purgeActor')->with($actor);

		$this->assertSame(1, $this->service->purgeStep(self::DOMAIN));
	}

	public function testOneStepNeverDetachesMoreThanABatch(): void {
		$this->withRemaining($this->ids('a', DomainPurgeService::BATCH + 20));

		// a pass has to be able to stop: a purge that read a whole instance
		// into memory would be unbounded in exactly the case it matters
		$this->assertSame(DomainPurgeService::BATCH, $this->service->purgeStep(self::DOMAIN));
	}

	public function testTheNumberOfStepsCanBeCapped(): void {
		$this->withRemaining($this->ids('a', DomainPurgeService::BATCH * 3));

		$this->assertSame(DomainPurgeService::BATCH * 2, $this->service->purge(self::DOMAIN, 2));
		$this->assertTrue($this->service->hasRemains(self::DOMAIN), 'the rest is left for the next pass');
	}

	public function testPurgingADomainWithNothingLeftDoesNothing(): void {
		$this->withRemaining([]);
		$this->moderationService->expects($this->never())->method('purgeActor');

		$this->assertSame(0, $this->service->purge(self::DOMAIN));
	}

	public function testAnInterruptedPurgeIsResumedByRunningItAgain(): void {
		$this->withRemaining($this->ids('a', 5));

		$this->service->purge(self::DOMAIN, 0);
		// idempotent: the second run finds nothing because the work is looked
		// up rather than counted off, so nothing is skipped or repeated
		$this->assertSame(0, $this->service->purge(self::DOMAIN));
	}

	public function testAPurgeThatDeletesNothingStopsInsteadOfSpinning(): void {
		// detaching an account swallows the failure of each table it touches,
		// so a database that refuses the delete hands back the same accounts
		// for ever; without a guard, purge() would never return
		$this->cacheActorsRequest->method('getIdsFromDomain')->willReturn($this->ids('a', 2));
		$this->streamRequest->method('getAuthorsFromDomain')->willReturn([]);
		$this->followsRequest->method('getActorIdsFromDomain')->willReturn([]);
		$this->moderationService->expects($this->exactly(2))->method('purgeActor');

		$this->assertSame(2, $this->service->purge(self::DOMAIN));
	}

	public function testAPurgeOfThisInstanceIsRefused(): void {
		$this->moderationService->expects($this->never())->method('purgeActor');

		$this->expectException(InvalidResourceException::class);
		$this->service->purge('cloud.example');
	}

	public function testTheDomainIsNormalisedBeforeAnythingIsDeleted(): void {
		$seen = [];
		$this->cacheActorsRequest->method('getIdsFromDomain')
			->willReturnCallback(function (string $domain) use (&$seen): array {
				$seen[] = $domain;

				return [];
			});
		$this->streamRequest->method('getAuthorsFromDomain')->willReturn([]);
		$this->followsRequest->method('getActorIdsFromDomain')->willReturn([]);

		$this->service->purge('https://Spam.EXAMPLE:8443/@someone');

		$this->assertSame([self::DOMAIN], $seen);
	}

	public function testSomethingThatIsNotADomainIsRefused(): void {
		$this->expectException(InvalidResourceException::class);
		$this->service->purge('not a domain');
	}
}
