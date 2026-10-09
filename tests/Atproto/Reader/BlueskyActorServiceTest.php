<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyPins;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Details;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\VerificationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyActorServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var AppViewClient&MockObject */
	private AppViewClient $appView;
	/** @var PlcClient&MockObject */
	private PlcClient $plc;
	/** @var CacheActorsRequest&MockObject */
	private CacheActorsRequest $cache;
	/** @var ActorService&MockObject */
	private ActorService $actors;
	/** @var AtprotoConfig&MockObject */
	private AtprotoConfig $config;
	private BlueskyActorService $service;
	/** @var array<string, Person> */
	private array $rows = [];

	protected function setUp(): void {
		$this->config = $this->createMock(AtprotoConfig::class);
		$this->config->method('isEnabled')->willReturn(true);
		$this->appView = $this->createMock(AppViewClient::class);
		$this->plc = $this->createMock(PlcClient::class);
		$this->cache = $this->createMock(CacheActorsRequest::class);
		$this->cache->method('getFromId')->willReturnCallback(fn (string $id): Person => $this->rows[$id] ?? throw new CacheActorDoesNotExistException());
		$this->cache->method('getFromAccount')->willReturnCallback(function (string $account): Person {
			foreach ($this->rows as $row) {
				if ($row->getAccount() === $account) {
					return $row;
				}
			}
			throw new CacheActorDoesNotExistException();
		});
		$this->actors = $this->createMock(ActorService::class);
		$mapper = $this->createMock(ActorMapper::class);
		$mapper->method('person')->willReturnCallback(static function (array $profile, string $pds): Person {
			$person = new Person();
			$person->setId('https://bsky.app/profile/' . $profile['did']);
			$person->setAccount($profile['handle']);
			$person->setDetailArray(Details::COUNT, ['followers' => 1, 'following' => 2, 'post' => 3]);
			$person->setDetailArray(ActorMapper::DETAIL, ['did' => $profile['did'], 'pds' => $pds, 'limited' => ($profile['limited'] ?? false)]);

			return $person;
		});
		$this->service = new BlueskyActorService($this->config, $this->appView, $this->plc, $mapper, $this->cache, $this->actors, $this->createMock(Blocklist::class), new NullLogger());
	}

	public function testAHandleIsResolvedThroughTheAppViewAndSavedOnce(): void {
		$this->appView->expects($this->once())->method('query')->with('app.bsky.actor.getProfile', ['actor' => 'alice.bsky.social'])->willReturn(['did' => self::DID, 'handle' => 'alice.bsky.social']);
		$this->plc->expects($this->once())->method('document')->with(self::DID)->willReturn(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example']]]);
		$this->actors->expects($this->once())->method('save')->with($this->callback(static fn (Person $p): bool => $p->getId() === 'https://bsky.app/profile/' . self::DID));
		$this->actors->expects($this->never())->method('update');
		$this->cache->expects($this->once())->method('setCounts')->with('https://bsky.app/profile/' . self::DID, ['followers' => 1, 'following' => 2, 'post' => 3]);

		$person = $this->service->resolve('Alice.bsky.social ');
		$this->assertSame('https://pds.example', $person->getDetails(ActorMapper::DETAIL)['pds']);
		$this->assertTrue(BlueskyActorService::isBluesky($person));
		$this->assertFalse(BlueskyActorService::isLimited($person));
	}

	public function testACachedAccountIsNotAskedForAgainUnlessRefreshed(): void {
		$cached = new Person();
		$cached->setId('https://bsky.app/profile/' . self::DID);
		$cached->setAccount('alice.bsky.social');
		$this->rows[$cached->getId()] = $cached;
		$this->appView->expects($this->once())->method('query')->willReturn(['did' => self::DID, 'handle' => 'alice.bsky.social']);
		$this->actors->expects($this->once())->method('update');
		$this->actors->expects($this->never())->method('save');

		$this->assertSame($cached, $this->service->resolve('alice.bsky.social'), 'by handle, from the cache');
		$this->assertSame($cached, $this->service->resolve(self::DID), 'by DID, from the cache');
		$this->assertSame($cached, $this->service->cached(self::DID));
		$this->assertNotSame($cached, $this->service->resolve(self::DID, true), 'a refresh re-reads the profile');
	}

	public function testAnUnknownAccountIsNotACachedActor(): void {
		$this->appView->method('query')->willThrowException(new AppViewNotFoundException('Profile not found'));
		$this->expectException(CacheActorDoesNotExistException::class);
		$this->service->resolve('nobody.bsky.social');
	}

	public function testNothingIsResolvedWhileBlueskyIsOff(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(false);
		$service = new BlueskyActorService($config, $this->appView, $this->plc, $this->createMock(ActorMapper::class), $this->cache, $this->actors, $this->createMock(Blocklist::class), new NullLogger());
		$this->appView->expects($this->never())->method('query');
		$this->expectException(CacheActorDoesNotExistException::class);
		$service->resolve('alice.bsky.social');
	}

	public function testADidWebAccountHasNoPlcDocument(): void {
		$this->appView->method('query')->willReturn(['did' => 'did:web:example.com', 'handle' => 'example.com']);
		$this->plc->expects($this->never())->method('document');
		$this->assertSame('', $this->service->resolve('example.com')->getDetails(ActorMapper::DETAIL)['pds']);
	}

	public function testABlockedAccountIsNotResolvedByDidOrByItsHost(): void {
		$blocklist = $this->createMock(Blocklist::class);
		$blocklist->method('isBlockedDid')->willReturnCallback(static fn (string $did): bool => $did === 'did:plc:blockedblockedblockedbl');
		$blocklist->method('isBlockedAccount')->willReturnCallback(static fn (string $did, string $pds): bool => $pds === 'https://evil.example');
		$mapper = $this->createMock(ActorMapper::class);
		$service = new BlueskyActorService($this->config, $this->appView, $this->plc, $mapper, $this->cache, $this->actors, $blocklist, new NullLogger());
		$this->appView->method('query')->willReturn(['did' => self::DID, 'handle' => 'alice.evil.example']);
		$this->plc->method('document')->willReturn(['service' => [['id' => '#atproto_pds', 'serviceEndpoint' => 'https://evil.example']]]);
		$this->actors->expects($this->never())->method('save');

		foreach (['did:plc:blockedblockedblockedbl', 'alice.evil.example'] as $target) {
			try {
				$service->resolve($target);
				$this->fail('resolved ' . $target);
			} catch (CacheActorDoesNotExistException) {
			}
		}
	}

	public function testTheProfilesPinnedPostIsKeptAsAPin(): void {
		$this->appView->method('query')->willReturn(['did' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'handle' => 'bob.bsky.social', 'pinnedPost' => ['uri' => 'at://did:plc:ewvi7nxzyoun6zhxrhs64oiz/app.bsky.feed.post/3kpin', 'cid' => 'x']]);
		$pins = $this->createMock(BlueskyPins::class);
		$pins->expects($this->once())->method('keep')->with($this->isInstanceOf(Person::class), 'at://did:plc:ewvi7nxzyoun6zhxrhs64oiz/app.bsky.feed.post/3kpin');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $id === BlueskyPins::class ? $pins : $this->createMock(VerificationService::class));
		$mapper = $this->createMock(ActorMapper::class);
		$mapper->method('person')->willReturn(new Person());
		$service = new BlueskyActorService($this->config, $this->appView, $this->plc, $mapper, $this->cache, $this->actors, $this->createMock(Blocklist::class), new NullLogger(), $container);

		$service->resolve('bob.bsky.social', true);
	}

	/** An account read again may have another handle or name, which a verification of it has to name. */
	public function testAStoredAccountIsHandedToTheVerificationsToBeIssuedAgain(): void {
		$person = (new Person())->setId('https://bsky.app/profile/' . self::DID);
		$verifications = $this->createMock(VerificationService::class);
		$verifications->expects($this->once())->method('refresh')->with($this->identicalTo($person));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(VerificationService::class)->willReturn($verifications);
		$service = new BlueskyActorService($this->config, $this->appView, $this->plc, $this->createMock(ActorMapper::class), $this->cache, $this->actors, $this->createMock(Blocklist::class), new NullLogger(), $container);

		$service->store($person);
	}

	public function testAFailingVerificationDoesNotKeepTheAccountFromBeingStored(): void {
		$person = (new Person())->setId('https://bsky.app/profile/' . self::DID);
		$verifications = $this->createMock(VerificationService::class);
		$verifications->method('refresh')->willThrowException(new \RuntimeException('no repository'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($verifications);
		$this->actors->expects($this->once())->method('save')->with($person);
		$service = new BlueskyActorService($this->config, $this->appView, $this->plc, $this->createMock(ActorMapper::class), $this->cache, $this->actors, $this->createMock(Blocklist::class), new NullLogger(), $container);

		$service->store($person);
	}
}
