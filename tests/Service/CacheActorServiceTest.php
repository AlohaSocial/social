<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Interfaces\Activity\FeaturedCollection;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\ProfileLinkVerifier;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class CacheActorServiceTest extends TestCase {
	private const BOB = 'https://remote.example/users/bob';
	private const ALICE = 'https://cloud.example.com/apps/social/@alice';
	private const NOW = 1_700_000_000;

	/** the annotations `CurlService::retrieveObject()` adds to a fetched actor */
	private const ACTIVITY_JSON = [
		'_host' => 'remote.example', '_contentType' => 'application/activity+json',
	];

	private ActorsRequest|MockObject $actorsRequest;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private CurlService|MockObject $curlService;
	private ConfigService|Stub $configService;
	private AP|MockObject $ap;
	private PersonInterface|MockObject $personInterface;
	private CacheActorService $service;

	protected function setUp(): void {
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getCloudHost')->willReturn('cloud.example.com');
		$this->configService->method('getSocialAddress')->willReturn('social.example.com');

		$this->ap = $this->createMock(AP::class);
		$this->personInterface = $this->createMock(PersonInterface::class);
		$this->ap->method('getInterfaceFromType')->with(Person::TYPE)->willReturn($this->personInterface);
		$this->ap->method('isActor')->willReturnCallback(fn ($item) => $item instanceof Person);
		AP::set($this->ap);

		$this->service = new CacheActorService(
			$this->actorsRequest,
			$this->cacheActorsRequest,
			$this->curlService,
			$this->configService,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	private function person(string $id, string $username = 'bob'): Person {
		$person = new Person();
		$person->setId($id);
		$person->setPreferredUsername($username);

		return $person;
	}

	public function testGetFromIdReturnsTheCachedActorAndIgnoresTheKeyFragment(): void {
		$bob = $this->person(self::BOB);
		$this->cacheActorsRequest->expects($this->once())->method('getFromId')->with(self::BOB)->willReturn($bob);
		$this->curlService->expects($this->never())->method('retrieveObject');

		$this->assertSame($bob, $this->service->getFromId(self::BOB . '#main-key'));
	}

	public function testGetFromIdAsksTheDatabaseOnceForTheSameActor(): void {
		$bob = $this->person(self::BOB);
		$this->cacheActorsRequest->expects($this->once())->method('getFromId')->willReturn($bob);

		$this->service->getFromId(self::BOB);
		$this->assertSame($bob, $this->service->getFromId(self::BOB));
	}

	/**
	 * cron.php is one process for every job it runs, so the memo is held to
	 * MEMO_LIMIT and gives up the least recently used actor first.
	 */
	public function testTheMemoForgetsTheLeastRecentlyUsedActorPastItsLimit(): void {
		$asked = [];
		$this->cacheActorsRequest->method('getFromId')
			->willReturnCallback(function (string $id) use (&$asked): Person {
				$asked[] = $id;

				return $this->person($id);
			});

		$this->service->getFromId('https://remote.example/users/0');
		$this->service->getFromId('https://remote.example/users/1');
		for ($i = 2; $i <= CacheActorService::MEMO_LIMIT; $i++) {
			$this->service->getFromId('https://remote.example/users/' . $i);
			// /0 is used all along and stays; /1 is the stalest when the limit is passed
			$this->service->getFromId('https://remote.example/users/0');
		}

		$asked = [];
		$this->service->getFromId('https://remote.example/users/0');
		$this->service->getFromId('https://remote.example/users/1');

		$this->assertSame(['https://remote.example/users/1'], $asked);
	}

	public function testForgettingTheMemoReadsTheActorAgain(): void {
		$this->cacheActorsRequest->expects($this->exactly(2))->method('getFromId')
			->willReturn($this->person(self::BOB));

		$this->service->getFromId(self::BOB);
		$this->service->forgetMemoised();
		$this->service->getFromId(self::BOB);
	}

	/** a route answered to anybody is not a reason to go and fetch an actor */
	public function testResolveDoesNotFetchAnUnknownUrlByDefault(): void {
		$this->cacheActorsRequest->method('getFromIds')->willReturn([]);
		$this->curlService->expects($this->never())->method('retrieveObject');

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->service->resolve(self::BOB);
	}

	public function testResolveAnswersACachedUrlWithoutFetching(): void {
		$bob = $this->person(self::BOB);
		$this->cacheActorsRequest->method('getFromIds')->with([self::BOB])->willReturn([self::BOB => $bob]);
		$this->curlService->expects($this->never())->method('retrieveObject');

		$this->assertSame($bob, $this->service->resolve(self::BOB));
	}

	/** a signed-in viewer naming a stranger is, and says so */
	public function testResolveFetchesAnUnknownUrlWhenAllowed(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$data = [
			'id' => self::BOB, 'type' => 'Person', 'preferredUsername' => 'bob',
			'_host' => 'remote.example', '_contentType' => 'application/activity+json',
		];
		$this->curlService->expects($this->once())->method('retrieveObject')->with(self::BOB)->willReturn($data);
		$bob = $this->person(self::BOB);
		$this->ap->method('getItemFromData')->willReturn($bob);

		$this->assertSame(self::BOB, $this->service->resolve(self::BOB, true)->getId());
	}

	public function testResolveRefusesAnEmptyOrNonPositiveReference(): void {
		foreach (['', '@', '0', '-3'] as $reference) {
			try {
				$this->service->resolve($reference);
				$this->fail('resolved ' . var_export($reference, true));
			} catch (CacheActorDoesNotExistException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testGetFromIdFetchesSavesAndReturnsAnUnknownActor(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$data = [
			'id' => self::BOB, 'type' => 'Person', 'preferredUsername' => 'bob',
			'_host' => 'remote.example', '_contentType' => 'application/activity+json',
		];
		$this->curlService->expects($this->once())->method('retrieveObject')->with(self::BOB)->willReturn($data);
		$bob = $this->person(self::BOB);
		$this->ap->expects($this->once())->method('getItemFromData')->with($data)->willReturn($bob);
		$this->personInterface->expects($this->once())->method('save')->with($this->identicalTo($bob));

		$actor = $this->service->getFromId(self::BOB);

		$this->assertSame($bob, $actor);
		$this->assertSame('bob@remote.example', $actor->getAccount());
	}

	public function testGetFromIdWithRefreshSkipsTheCache(): void {
		$this->cacheActorsRequest->expects($this->never())->method('getFromId');
		$this->curlService->method('retrieveObject')->willReturn(self::ACTIVITY_JSON);
		$this->ap->method('getItemFromData')->willReturn($this->person(self::BOB));

		$this->assertSame(self::BOB, $this->service->getFromId(self::BOB, true)->getId());
	}

	public function testGetFromIdRejectsADocumentThatIsNotAnActor(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')->willReturn(['type' => 'Note']);
		$this->ap->method('getItemFromData')->willReturn(new Note());
		$this->personInterface->expects($this->never())->method('save');

		$this->expectException(InvalidResourceException::class);
		$this->service->getFromId(self::BOB);
	}

	public function testGetFromIdRejectsAnActorClaimingAnotherHost(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')->willReturn(self::ACTIVITY_JSON);
		$this->ap->method('getItemFromData')->willReturn($this->person('https://evil.example/users/bob'));
		$this->personInterface->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);
		$this->service->getFromId(self::BOB);
	}

	/**
	 * The row is keyed by the actor's own id, so a document answering with a
	 * stranger's id rewrites the stranger's row — public key included. Matching
	 * hosts was not enough: any URL on a host that serves JSON somebody else
	 * wrote (on a Nextcloud, a public share) could claim that host's admin
	 * account, and an inbox POST naming such a URL as its `keyId` is what makes
	 * this instance go and fetch it.
	 */
	public function testGetFromIdRejectsAnActorClaimingAnotherIdOnTheSameHost(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')->willReturn(self::ACTIVITY_JSON);
		$this->ap->method('getItemFromData')->willReturn($this->person('https://remote.example/users/admin'));
		$this->personInterface->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);
		$this->service->getFromId(self::BOB);
	}

	/** An actor is only an actor when its host serves it as ActivityPub. */
	public function testGetFromIdRejectsADocumentNotServedAsActivityPub(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')
			->willReturn(['_host' => 'remote.example', '_contentType' => 'application/json; charset=utf-8']);
		$this->ap->method('getItemFromData')->willReturn($this->person(self::BOB));
		$this->personInterface->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);
		$this->service->getFromId(self::BOB);
	}

	public function testGetFromIdAcceptsLdJsonWithTheActivityStreamsProfile(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')->willReturn([
			'_host' => 'remote.example',
			'_contentType' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
		]);
		$this->ap->method('getItemFromData')->willReturn($this->person(self::BOB));

		$this->assertSame(self::BOB, $this->service->getFromId(self::BOB)->getId());
	}

	public function testGetFromIdWrapsSaveFailures(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')->willReturn(self::ACTIVITY_JSON);
		$this->ap->method('getItemFromData')->willReturn($this->person(self::BOB));
		$this->personInterface->method('save')->willThrowException(new ItemAlreadyExistsException('dup'));

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessage('dup');
		$this->service->getFromId(self::BOB);
	}

	public function testRefreshOfAGoneActorDeletesTheCachedCopy(): void {
		$cached = $this->person(self::BOB);
		$this->cacheActorsRequest->expects($this->once())->method('getFromId')->with(self::BOB)->willReturn($cached);
		$this->curlService->method('retrieveObject')->willThrowException(new RequestContentException('gone', 410));
		$this->personInterface->expects($this->once())->method('delete')->with($this->identicalTo($cached));

		$this->expectException(RequestContentException::class);
		$this->service->getFromId(self::BOB, true);
	}

	public function testFetchFailureWithoutRefreshIsPropagatedWithoutDeleting(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')->willThrowException(new RequestContentException('gone', 410));
		$this->personInterface->expects($this->never())->method('delete');

		$this->expectException(RequestContentException::class);
		$this->service->getFromId(self::BOB);
	}

	/** @return array<string, array{string}> */
	public static function localAccountProvider(): array {
		return [
			'bare username' => ['alice'],
			'leading at' => ['@alice'],
			'cloud host' => ['alice@cloud.example.com'],
			'social address' => ['@alice@social.example.com'],
			// a host name is case-insensitive
			'cloud host in capitals' => ['alice@CLOUD.example.com'],
			'social address in capitals' => ['alice@Social.Example.com'],
		];
	}

	#[DataProvider('localAccountProvider')]
	public function testGetFromLocalAccountResolvesLocalHandles(string $account): void {
		$this->actorsRequest->expects($this->once())->method('getFromUsername')->with('alice')->willReturn($this->person(self::ALICE, 'alice'));
		$cached = $this->person(self::ALICE, 'alice');
		$this->cacheActorsRequest->expects($this->once())->method('getFromLocalAccount')->with('alice')->willReturn($cached);

		$this->assertSame($cached, $this->service->getFromLocalAccount($account));
	}

	public function testGetFromLocalAccountRejectsRemoteHandles(): void {
		$this->actorsRequest->expects($this->never())->method('getFromUsername');

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->expectExceptionMessage('not local');
		$this->service->getFromLocalAccount('bob@remote.example');
	}

	public function testGetFromLocalAccountRejectsDeletedAccounts(): void {
		$deleted = $this->person(self::ALICE, 'alice');
		$deleted->setDeleted(time() - 60);
		$this->actorsRequest->method('getFromUsername')->willReturn($deleted);
		$this->cacheActorsRequest->expects($this->never())->method('getFromLocalAccount');

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->expectExceptionMessage('deleted');
		$this->service->getFromLocalAccount('alice');
	}

	public function testGetFromAccountPrefersALocalAccount(): void {
		$this->actorsRequest->method('getFromUsername')->willReturn($this->person(self::ALICE, 'alice'));
		$alice = $this->person(self::ALICE, 'alice');
		$this->cacheActorsRequest->method('getFromLocalAccount')->willReturn($alice);
		$this->cacheActorsRequest->expects($this->never())->method('getFromAccount');
		$this->curlService->expects($this->never())->method('retrieveAccount');

		$this->assertSame($alice, $this->service->getFromAccount('alice@cloud.example.com'));
	}

	public function testGetFromAccountReturnsACachedRemoteActor(): void {
		$bob = $this->person(self::BOB);
		$this->cacheActorsRequest->expects($this->once())->method('getFromAccount')->with('bob@remote.example')->willReturn($bob);
		$this->curlService->expects($this->never())->method('retrieveAccount');

		$this->assertSame($bob, $this->service->getFromAccount('bob@remote.example'));
	}

	public function testGetFromAccountWebfingersAndCachesAnUnknownRemoteActor(): void {
		$this->cacheActorsRequest->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$bob = $this->person(self::BOB);
		$this->curlService->expects($this->once())->method('retrieveAccount')->with('bob@remote.example')->willReturn($bob);
		$this->personInterface->expects($this->once())->method('save')->with($this->identicalTo($bob));

		$actor = $this->service->getFromAccount('bob@remote.example');

		$this->assertSame($bob, $actor);
		$this->assertSame('bob@remote.example', $actor->getAccount());
	}

	public function testGetFromAccountWithoutRetrieveFailsOnCacheMiss(): void {
		$this->cacheActorsRequest->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->expects($this->never())->method('retrieveAccount');

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->service->getFromAccount('bob@remote.example', false);
	}

	public function testGetFromAccountWrapsSaveFailures(): void {
		$this->cacheActorsRequest->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveAccount')->willReturn($this->person(self::BOB));
		$this->personInterface->method('save')->willThrowException(new ItemAlreadyExistsException('dup'));

		$this->expectException(InvalidResourceException::class);
		$this->service->getFromAccount('bob@remote.example');
	}

	public function testDelegatingLookups(): void {
		$bob = $this->person(self::BOB);
		$options = new ProbeOptions();
		$this->cacheActorsRequest->expects($this->once())->method('searchAccounts')->with('bo')->willReturn([$bob]);
		$this->cacheActorsRequest->expects($this->once())->method('getFromNids')->with([1, 2])->willReturn([$bob]);
		$this->cacheActorsRequest->expects($this->once())->method('probeActors')->with($this->identicalTo($options))->willReturn([$bob]);
		$this->cacheActorsRequest->expects($this->once())->method('setViewer')->with($this->identicalTo($bob));

		$this->assertSame([$bob], $this->service->searchCachedAccounts('bo'));
		$this->assertSame([$bob], $this->service->getFromNids([1, 2]));
		$this->assertSame([$bob], $this->service->probeActors($options));
		$this->service->setViewer($bob);
	}

	public function testManageCacheRemoteActorsRefreshesEachStaleActorAndSurvivesFailures(): void {
		$stale = [$this->person(self::BOB), $this->person('https://other.example/users/carol', 'carol')];
		$this->cacheActorsRequest->expects($this->once())->method('getRemoteActorsToUpdate')->with(true)->willReturn($stale);
		$retrieved = [];
		$this->curlService->expects($this->exactly(2))
			->method('retrieveObject')
			->willReturnCallback(function (string $id) use (&$retrieved) {
				$retrieved[] = $id;
				if ($id === self::BOB) {
					return self::ACTIVITY_JSON;
				}
				throw new RequestNetworkException('down');
			});
		$this->ap->method('getItemFromData')->willReturn($this->person(self::BOB));
		$this->personInterface->expects($this->once())->method('save');

		$this->assertSame(2, $this->service->manageCacheRemoteActors(true));
		$this->assertSame([self::BOB, 'https://other.example/users/carol'], $retrieved);
	}

	/**
	 * The refresh used to swallow a failure and write nothing at all, so the
	 * row was due again on the very next pass — and fifty rows on a dead
	 * instance were the fifty the refresh picked every twelve minutes, for as
	 * long as the instance stayed dead. Every attempt is now stamped, and the
	 * clock it is stamped with is the one the query selected on.
	 */
	public function testEveryRefreshAttemptIsRecordedWhetherItWorkedOrNot(): void {
		$carol = 'https://other.example/users/carol';
		$stale = [$this->person(self::BOB), $this->person($carol, 'carol')];
		$this->cacheActorsRequest->expects($this->once())->method('getRemoteActorsToUpdate')
			->with(false, self::NOW)->willReturn($stale);
		$this->curlService->method('retrieveObject')->willReturnCallback(
			function (string $id) use ($carol): array {
				if ($id === $carol) {
					throw new RequestNetworkException('down');
				}

				return self::ACTIVITY_JSON;
			}
		);
		$this->ap->method('getItemFromData')->willReturn($this->person(self::BOB));
		$recorded = [];
		$this->cacheActorsRequest->method('recordSyncAttempt')
			->willReturnCallback(function (string $id, bool $success, int $now) use (&$recorded): void {
				$recorded[] = [$id, $success, $now];
			});

		$this->assertSame(2, $this->serviceWithClock()->manageCacheRemoteActors());
		$this->assertSame(
			[[self::BOB, true, self::NOW], [$carol, false, self::NOW]],
			$recorded
		);
	}

	/**
	 * `details_update` only moves on success, so without a record of the
	 * attempt the fifty oldest details were the fifty unreachable ones on
	 * every pass, exactly as for the refresh above.
	 */
	public function testAFailedDetailsRefreshIsRecordedToo(): void {
		$bob = $this->person(self::BOB);
		$this->cacheActorsRequest->method('getRemoteActorsToUpdateDetails')
			->with(false, self::NOW)->willReturn([$bob]);
		$this->cacheActorsRequest->method('updateDetails')
			->willThrowException(new \RuntimeException('the peer is gone'));
		$this->cacheActorsRequest->expects($this->once())->method('recordSyncAttempt')
			->with(self::BOB, false, self::NOW);

		$this->assertSame(1, $this->serviceWithClock()->manageDetailsRemoteActors());
	}

	public function testAnActorThatCanBeFetchedAgainHasItsFailureCountCleared(): void {
		$bob = $this->person(self::BOB);
		$this->curlService->method('retrieveObject')->willReturn(self::ACTIVITY_JSON);
		$this->ap->method('getItemFromData')->willReturn($this->person(self::BOB));
		$this->cacheActorsRequest->expects($this->once())->method('recordSyncAttempt')
			->with(self::BOB, true, self::NOW);

		$this->assertTrue($this->serviceWithClock()->refreshRemoteActor($bob));
	}

	/** @return Person[] */
	private function remoteBatch(int $from, int $count): array {
		$batch = [];
		for ($i = $from; $i < $from + $count; $i++) {
			$batch[] = $this->person('https://other.example/users/u' . $i, 'u' . $i);
		}

		return $batch;
	}

	/**
	 * Given time, the refresh takes batch after batch: fifty a pass could not
	 * keep a ten-day lifetime past about 60,000 cached actors.
	 */
	public function testATimedRefreshTakesBatchesUntilNothingIsDue(): void {
		$this->cacheActorsRequest->expects($this->exactly(3))->method('getRemoteActorsToUpdate')
			->willReturnOnConsecutiveCalls($this->remoteBatch(0, 50), $this->remoteBatch(50, 50), []);
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException('down'));
		$this->cacheActorsRequest->expects($this->exactly(100))->method('recordSyncAttempt');

		$this->assertSame(100, $this->serviceWithClock()->manageCacheRemoteActors(false, time() + 60));
	}

	public function testATimedRefreshStopsAtItsDeadline(): void {
		$this->cacheActorsRequest->expects($this->once())->method('getRemoteActorsToUpdate')
			->willReturn($this->remoteBatch(0, 50));
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException('down'));

		$this->assertSame(1, $this->serviceWithClock()->manageCacheRemoteActors(false, time() - 1));
	}

	/**
	 * A stamp that could not be written leaves the same rows due: the pass
	 * handles each once and ends, rather than asking the same peers again
	 * until the deadline.
	 */
	public function testATimedRefreshHandlesAnActorOncePerPass(): void {
		$batch = $this->remoteBatch(0, 3);
		$this->cacheActorsRequest->expects($this->exactly(2))->method('getRemoteActorsToUpdate')->willReturn($batch);
		$this->curlService->expects($this->exactly(3))->method('retrieveObject')
			->willThrowException(new RequestNetworkException('down'));

		$this->assertSame(3, $this->serviceWithClock()->manageCacheRemoteActors(false, time() + 60));
	}

	public function testTheDetailsRefreshIsTimedTheSameWay(): void {
		$this->cacheActorsRequest->expects($this->exactly(3))->method('getRemoteActorsToUpdateDetails')
			->willReturnOnConsecutiveCalls($this->remoteBatch(0, 50), $this->remoteBatch(50, 20), []);
		$this->cacheActorsRequest->method('updateDetails')->willThrowException(new \RuntimeException('gone'));
		$this->cacheActorsRequest->expects($this->exactly(70))->method('recordSyncAttempt');

		$this->assertSame(70, $this->serviceWithClock()->manageDetailsRemoteActors(false, time() + 60));
	}

	/** The same service, with a clock a test can hold still. */
	private function serviceWithClock(int $now = self::NOW): CacheActorService {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		return new CacheActorService(
			$this->actorsRequest,
			$this->cacheActorsRequest,
			$this->curlService,
			$this->configService,
			new NullLogger(),
			null,
			$time,
		);
	}

	public function testAddRemoteActorDetailCountReadsTheCollectionTotals(): void {
		$bob = $this->person(self::BOB);
		$bob->setFollowers(self::BOB . '/followers');
		$bob->setFollowing(self::BOB . '/following');
		$bob->setOutbox(self::BOB . '/outbox');
		$totals = [self::BOB . '/followers' => 12, self::BOB . '/following' => 7, self::BOB . '/outbox' => 340];
		$this->curlService->method('retrieveObject')->willReturnCallback(fn (string $id) => ['type' => 'OrderedCollection', 'id' => $id]);
		$this->ap->method('getItemFromData')->willReturnCallback(function (array $data) use ($totals) {
			$collection = new OrderedCollection();
			$collection->setTotalItems($totals[$data['id']]);

			return $collection;
		});

		$this->service->addRemoteActorDetailCount($bob);

		$this->assertSame(['followers' => 12, 'following' => 7, 'post' => 340], $bob->getDetails('count'));
	}

	public function testAddRemoteActorDetailCountGivesUpWhenACollectionIsUnreachable(): void {
		$bob = $this->person(self::BOB);
		$bob->setFollowers(self::BOB . '/followers');
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException('down'));

		$this->service->addRemoteActorDetailCount($bob);

		$this->assertSame([], $bob->getDetails('count'));
	}

	public function testAddRemoteActorDetailCountRejectsNonCollections(): void {
		$bob = $this->person(self::BOB);
		$bob->setFollowers(self::BOB . '/followers');
		$this->curlService->method('retrieveObject')->willReturn(['type' => 'Note']);
		$this->ap->method('getItemFromData')->willReturn(new Note());

		$this->service->addRemoteActorDetailCount($bob);

		$this->assertSame([], $bob->getDetails('count'));
	}

	public function testManageDetailsRemoteActorsUpdatesEachActor(): void {
		$bob = $this->person(self::BOB);
		$this->cacheActorsRequest->method('getRemoteActorsToUpdateDetails')->with(false)->willReturn([$bob]);
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException('down'));
		$this->cacheActorsRequest->expects($this->once())->method('updateDetails')->with($this->identicalTo($bob));

		$this->assertSame(1, $this->service->manageDetailsRemoteActors());
	}

	public function testTheDetailsRefreshAlsoReadsThePinsAndChecksTheLinks(): void {
		$bob = $this->person(self::BOB);
		$this->cacheActorsRequest->method('getRemoteActorsToUpdateDetails')->willReturn([$bob]);
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException('down'));
		$featured = $this->createMock(FeaturedCollection::class);
		$featured->expects($this->once())->method('refresh')->with($this->identicalTo($bob));
		$verifier = $this->createMock(ProfileLinkVerifier::class);
		$verifier->expects($this->once())->method('verify')->with($this->identicalTo($bob));
		// resolved through the container at call time, not injected: injecting
		// FeaturedCollection would close a dependency cycle through StreamRequest
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[FeaturedCollection::class, $featured],
			[ProfileLinkVerifier::class, $verifier],
		]);
		$service = new CacheActorService(
			$this->actorsRequest,
			$this->cacheActorsRequest,
			$this->curlService,
			$this->configService,
			new NullLogger(),
			$container,
		);

		$this->assertSame(1, $service->manageDetailsRemoteActors());
	}
}
