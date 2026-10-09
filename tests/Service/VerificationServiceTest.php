<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\BlueskyVerifications;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Db\VerificationsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\VerificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The instance verifying accounts: stored here and shown on the account
 * entity for any account, published in the verifying account's repository
 * for one with a DID, and published again when what the record names
 * changes.
 */
#[AllowMockObjectsWithoutExpectations]
class VerificationServiceTest extends TestCase {
	private const COMPANY = 'https://social.test/@company';
	private const ANNA = 'https://social.test/@anna';
	private const BOB = 'https://bsky.app/profile/did:plc:bob';
	private const CAROL = 'https://mastodon.example/users/carol';

	/** @var array<string, array{actorId: string, did: string, handle: string, displayName: string, verifiedBy: string, creation: int}> */
	private array $rows = [];
	private int $indexReads = 0;
	/** @var array<string, string> app values */
	private array $values = [];
	private bool $enabled = true;
	/** @var array<string, Person> */
	private array $actors = [];
	/** @var array<string, Identity> by actor id */
	private array $identities = [];
	/** @var list<string> what was published and withdrawn, in order */
	private array $published = [];
	/** @var IJobList&MockObject */
	private IJobList $jobs;
	/** @var IdentityService&MockObject */
	private IdentityService $identityService;
	/** @var Publisher&MockObject */
	private Publisher $publisher;

	protected function setUp(): void {
		$this->actors = [
			self::COMPANY => self::local(self::COMPANY, 'company', 'Example Inc'),
			self::ANNA => self::local(self::ANNA, 'anna', 'Anna'),
			self::BOB => self::bluesky('bob.bsky.social', 'Bob'),
			self::CAROL => self::remote(),
		];
		$this->identities = [
			self::COMPANY => self::identity(self::COMPANY, 'did:plc:company', 'company.social.test'),
			self::ANNA => self::identity(self::ANNA, 'did:plc:anna', 'anna.social.test'),
		];
	}

	private function service(): VerificationService {
		$request = $this->createMock(VerificationsRequest::class);
		$request->method('get')->willReturnCallback(fn (string $id): ?array => $this->rows[$id] ?? null);
		$request->method('add')->willReturnCallback(function (string $actorId, string $did, string $handle, string $displayName, string $by, int $time): void {
			$this->rows[$actorId] = ['actorId' => $actorId, 'did' => $did, 'handle' => $handle, 'displayName' => $displayName, 'verifiedBy' => $by, 'creation' => $time];
		});
		$request->method('updateSubject')->willReturnCallback(function (string $actorId, string $did, string $handle, string $displayName): void {
			$this->rows[$actorId] = ['did' => $did, 'handle' => $handle, 'displayName' => $displayName] + $this->rows[$actorId];
		});
		$request->method('remove')->willReturnCallback(function (string $actorId): bool {
			$had = isset($this->rows[$actorId]);
			unset($this->rows[$actorId]);

			return $had;
		});
		$request->method('getAll')->willReturnCallback(fn (): array => array_values(array_reverse($this->rows)));
		$request->method('getIndex')->willReturnCallback(function (): array {
			$this->indexReads++;

			return array_map(static fn (array $row): int => $row['creation'], $this->rows);
		});

		$config = $this->createMock(ConfigService::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->values[$key] ?? '');
		$config->method('setAppValue')->willReturnCallback(function (string $key, string $value): void {
			$this->values[$key] = $value;
		});

		$cache = $this->createMock(CacheActorService::class);
		$cache->method('getFromId')->willReturnCallback(fn (string $id): Person => $this->actors[$id] ?? throw new CacheActorDoesNotExistException());
		$cache->method('resolve')->willReturnCallback(function (string $reference): Person {
			foreach ($this->actors as $actor) {
				if ($actor->getPreferredUsername() === $reference || $actor->getId() === $reference) {
					return $actor;
				}
			}
			throw new CacheActorDoesNotExistException();
		});
		$cache->method('getCachedFromIds')->willReturnCallback(fn (array $ids): array => array_intersect_key($this->actors, array_flip($ids)));

		$atproto = $this->createMock(AtprotoConfig::class);
		$atproto->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('theming', 'name')->willReturn('Cloud of Example');
		$this->jobs = $this->createMock(IJobList::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);

		$bluesky = $this->createMock(BlueskyVerifications::class);
		$bluesky->method('publish')->willReturnCallback(function (Person $verifier, string $did, string $handle, string $name): bool {
			$this->published[] = 'publish ' . $verifier->getPreferredUsername() . ' ' . $did . ' ' . $handle . ' ' . $name;

			return true;
		});
		$bluesky->method('withdraw')->willReturnCallback(function (string $did): bool {
			$this->published[] = 'withdraw ' . $did;

			return true;
		});
		$this->identityService = $this->createMock(IdentityService::class);
		$this->identityService->method('forActor')->willReturnCallback(fn (Person $actor): ?Identity => $this->enabled ? ($this->identities[$actor->getId()] ?? null) : null);
		$this->publisher = $this->createMock(Publisher::class);
		$services = [BlueskyVerifications::class => $bluesky, IdentityService::class => $this->identityService, Publisher::class => $this->publisher];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		return new VerificationService($request, $config, $cache, $atproto, $appConfig, $this->jobs, $time, new NullLogger(), $container);
	}

	private static function local(string $id, string $username, string $name): Person {
		$actor = (new Person())->setId($id)->setPreferredUsername($username)->setName($name);
		$actor->setLocal(true);

		return $actor;
	}

	private static function bluesky(string $handle, string $name): Person {
		$actor = (new Person())->setId(self::BOB)->setPreferredUsername($handle)->setName($name);
		$actor->setAccount($handle);
		$actor->setLocal(false);
		$actor->setDetailArray(ActorMapper::DETAIL, ['did' => 'did:plc:bob', 'handle' => $handle]);

		return $actor;
	}

	private static function remote(): Person {
		$actor = (new Person())->setId(self::CAROL)->setPreferredUsername('carol')->setName('Carol');
		$actor->setAccount('carol@mastodon.example');
		$actor->setLocal(false);

		return $actor;
	}

	private static function identity(string $actorId, string $did, string $handle): Identity {
		return new Identity(1, $actorId, $did, $handle, 'sealed', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0);
	}

	private function withVerifier(): void {
		$this->values[ConfigService::VERIFICATION_ACCOUNT] = self::COMPANY;
	}

	public function testALocalAccountIsVerifiedAndPublishedFromTheVerifyingAccount(): void {
		$this->withVerifier();
		$service = $this->service();

		$row = $service->verify($this->actors[self::ANNA], 'admin');

		$this->assertSame(['actorId' => self::ANNA, 'did' => 'did:plc:anna', 'handle' => 'anna.social.test', 'displayName' => 'Anna', 'verifiedBy' => 'admin', 'creation' => 1760000000], $row);
		$this->assertSame(['publish company did:plc:anna anna.social.test Anna'], $this->published);
		$this->assertSame(['by' => 'Example Inc', 'issuer' => 'did:plc:company', 'created_at' => '2025-10-09T08:53:20.000Z'], $service->exportOf(self::ANNA));
		$this->assertTrue($service->isVerified(self::ANNA));
	}

	public function testABlueskyAccountIsPublishedUnderItsHandleAndName(): void {
		$this->withVerifier();
		$this->service()->verify($this->actors[self::BOB], 'admin');

		$this->assertSame(['publish company did:plc:bob bob.bsky.social Bob'], $this->published);
	}

	/** Most of the Fediverse has no DID: the check is this instance's alone. */
	public function testAnAccountWithoutADidIsVerifiedHereOnly(): void {
		$this->withVerifier();
		$service = $this->service();

		$row = $service->verify($this->actors[self::CAROL], 'admin');

		$this->assertSame('', $row['did']);
		$this->assertSame('carol@mastodon.example', $row['handle']);
		$this->assertSame([], $this->published);
		$this->assertSame('Example Inc', $service->exportOf(self::CAROL)['by'] ?? null);
	}

	public function testWithoutAVerifyingAccountTheChecksAreTheInstancesAndNothingIsPublished(): void {
		$service = $this->service();
		$service->verify($this->actors[self::ANNA], 'admin');

		$this->assertSame([], $this->published);
		$this->assertSame(['by' => 'Cloud of Example', 'issuer' => '', 'created_at' => '2025-10-09T08:53:20.000Z'], $service->exportOf(self::ANNA));
		$this->assertSame(['verifier' => null, 'publishing' => false], $service->state());
	}

	public function testWithBlueskyOffNothingIsPublished(): void {
		$this->enabled = false;
		$this->withVerifier();
		$service = $this->service();
		$service->verify($this->actors[self::BOB], 'admin');

		$this->assertSame([], $this->published);
		$this->assertSame('', $service->exportOf(self::BOB)['issuer'] ?? null);
		$this->assertFalse($service->state()['publishing']);
	}

	public function testVerifyingTwiceKeepsTheFirstVerification(): void {
		$this->withVerifier();
		$service = $this->service();
		$service->verify($this->actors[self::ANNA], 'admin');

		$this->assertSame('admin', $service->verify($this->actors[self::ANNA], 'moderator')['verifiedBy']);
		$this->assertCount(1, $this->published);
	}

	/** An account entity per post of a page asks; the table is read once. */
	public function testTheEntityOfAnAccountNobodyVerifiedSaysNothingAndTheTableIsReadOnce(): void {
		$this->rows[self::ANNA] = ['actorId' => self::ANNA, 'did' => '', 'handle' => 'anna', 'displayName' => 'Anna', 'verifiedBy' => 'admin', 'creation' => 1760000000];
		$service = $this->service();

		$this->assertNull($service->exportOf(self::CAROL));
		$this->assertNull($service->exportOf(self::BOB));
		$this->assertNotNull($service->exportOf(self::ANNA));
		$this->assertSame(1, $this->indexReads);
	}

	public function testTakingAVerificationBackWithdrawsItsRecord(): void {
		$this->withVerifier();
		$service = $this->service();
		$service->verify($this->actors[self::ANNA], 'admin');
		$this->assertNotNull($service->exportOf(self::ANNA));

		$this->assertTrue($service->unverify(self::ANNA));
		$this->assertFalse($service->unverify(self::ANNA), 'nothing left to take back');
		$this->assertSame('withdraw did:plc:anna', $this->published[1]);
		$this->assertNull($service->exportOf(self::ANNA));
	}

	public function testANewNameIsPublishedAgainAndAnUnchangedOneIsNot(): void {
		$this->withVerifier();
		$service = $this->service();
		$service->verify($this->actors[self::ANNA], 'admin');

		$service->refresh($this->actors[self::ANNA]);
		$this->assertCount(1, $this->published, 'nothing changed');

		$this->actors[self::ANNA]->setName('Anna Example');
		$service->refresh($this->actors[self::ANNA]);
		$this->assertSame('publish company did:plc:anna anna.social.test Anna Example', $this->published[1]);
		$this->assertSame('Anna Example', $this->rows[self::ANNA]['displayName']);
	}

	public function testANewHandleIsPublishedAgain(): void {
		$this->withVerifier();
		$service = $this->service();
		$service->verify($this->actors[self::BOB], 'admin');

		$service->refresh(self::bluesky('bob.example.com', 'Bob'));

		$this->assertSame('publish company did:plc:bob bob.example.com Bob', $this->published[1]);
	}

	public function testAnAccountNobodyVerifiedIsLeftAlone(): void {
		$this->withVerifier();
		$this->service()->refresh($this->actors[self::ANNA]);

		$this->assertSame([], $this->published);
		$this->assertSame([], $this->rows);
	}

	/** A local account that got a Bluesky identity only after it was verified. */
	public function testADidThatCameLaterIsPublished(): void {
		$this->withVerifier();
		$identity = $this->identities[self::ANNA];
		unset($this->identities[self::ANNA]);
		$service = $this->service();
		$service->verify($this->actors[self::ANNA], 'admin');
		$this->assertSame([], $this->published);

		$this->identities[self::ANNA] = $identity;
		$service->refresh($this->actors[self::ANNA]);

		$this->assertSame(['publish company did:plc:anna anna.social.test Anna'], $this->published);
	}

	public function testOnlyALocalAccountCanVerify(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->setVerifier(self::CAROL);
	}

	public function testAnUnknownAccountCannotVerify(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->setVerifier('nobody');
	}

	public function testTheVerifyingAccountIsGivenAnIdentityAndTheVerificationsMoveToIt(): void {
		$service = $this->service();
		$this->identityService->expects($this->atLeastOnce())->method('forActor')->with($this->actors[self::COMPANY]);
		$this->publisher->expects($this->once())->method('publishProfile')->with($this->actors[self::COMPANY]);
		$this->jobs->expects($this->once())->method('add')->with(AtprotoPublish::class, ['action' => 'verifications', 'id' => 'all']);

		$this->assertSame($this->actors[self::COMPANY], $service->setVerifier('company'));
		$this->assertSame(self::COMPANY, $this->values[ConfigService::VERIFICATION_ACCOUNT]);
		$this->assertSame([
			'verifier' => ['actor_id' => self::COMPANY, 'account' => 'company', 'name' => 'Example Inc', 'did' => 'did:plc:company'],
			'publishing' => true,
		], $service->state());
	}

	public function testChoosingTheSameAccountAgainMovesNothing(): void {
		$this->withVerifier();
		$service = $this->service();
		$this->jobs->expects($this->never())->method('add');

		$service->setVerifier('company');
	}

	public function testNoVerifyingAccountWithdrawsEveryRecord(): void {
		$this->withVerifier();
		$service = $this->service();
		$service->verify($this->actors[self::ANNA], 'admin');
		$service->verify($this->actors[self::CAROL], 'admin');
		$this->jobs->expects($this->once())->method('add');

		$this->assertNull($service->setVerifier(''));
		$this->assertSame(0, $service->republishAll());

		$this->assertSame(['publish company did:plc:anna anna.social.test Anna', 'withdraw did:plc:anna'], $this->published, 'an account without a DID had no record');
	}

	public function testAnotherVerifyingAccountPublishesEveryVerificationAgain(): void {
		$this->withVerifier();
		$service = $this->service();
		$service->verify($this->actors[self::ANNA], 'admin');
		$service->verify($this->actors[self::BOB], 'admin');
		$this->actors['https://social.test/@board'] = self::local('https://social.test/@board', 'board', 'The Board');
		$this->identities['https://social.test/@board'] = self::identity('https://social.test/@board', 'did:plc:board', 'board.social.test');
		$this->published = [];

		$service->setVerifier('board');

		$this->assertSame(2, $service->republishAll());
		$this->assertSame(['publish board did:plc:bob bob.bsky.social Bob', 'publish board did:plc:anna anna.social.test Anna'], $this->published);
	}

	public function testTheListNamesEachAccountAndWhoVerifiedIt(): void {
		$service = $this->service();
		$service->verify($this->actors[self::ANNA], 'admin');
		$service->verify($this->actors[self::CAROL], 'mod');

		$this->assertSame([
			['actor_id' => self::CAROL, 'account' => 'carol@mastodon.example', 'name' => 'Carol', 'did' => '', 'local' => false, 'verified_by' => 'mod', 'created_at' => '2025-10-09T08:53:20.000Z'],
			['actor_id' => self::ANNA, 'account' => 'anna', 'name' => 'Anna', 'did' => 'did:plc:anna', 'local' => true, 'verified_by' => 'admin', 'created_at' => '2025-10-09T08:53:20.000Z'],
		], $service->list());
	}

	public function testAVerifyingAccountThatWentAwayIsNone(): void {
		$this->values[ConfigService::VERIFICATION_ACCOUNT] = 'https://social.test/@gone';

		$this->assertNull($this->service()->verifier());
	}
}
