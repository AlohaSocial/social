<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Bluesky branches of the actor cache: a handle with no `@` and an id
 * on bsky.app go to the Bluesky resolver, one of this instance's own
 * handles to the local account that owns it, and nothing of the Fediverse
 * path (webfinger, actor documents) is touched for either.
 */
#[AllowMockObjectsWithoutExpectations]
class CacheActorServiceBlueskyTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var BlueskyActorService&MockObject */
	private BlueskyActorService $bluesky;
	/** @var AtprotoIdentityRequest&MockObject */
	private AtprotoIdentityRequest $identities;
	/** @var CacheActorsRequest&MockObject */
	private CacheActorsRequest $cache;
	/** @var CurlService&MockObject */
	private CurlService $curl;
	private CacheActorService $service;

	protected function setUp(): void {
		$this->bluesky = $this->createMock(BlueskyActorService::class);
		$this->identities = $this->createMock(AtprotoIdentityRequest::class);
		$this->cache = $this->createMock(CacheActorsRequest::class);
		$this->curl = $this->createMock(CurlService::class);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('handleHost')->willReturn('social.example.com');
		$this->service = new CacheActorService(
			$this->createMock(ActorsRequest::class),
			$this->cache,
			$this->curl,
			$this->createStub(ConfigService::class),
			new NullLogger(),
			null,
			null,
			$this->bluesky,
			$this->identities,
			$config,
		);
		$this->curl->expects($this->never())->method('retrieveAccount');
		$this->curl->expects($this->never())->method('retrieveObject');
	}

	public function testAHandleGoesToTheBlueskyResolver(): void {
		$alice = $this->person('https://bsky.app/profile/' . self::DID);
		$this->bluesky->expects($this->once())->method('resolve')->with('alice.bsky.social')->willReturn($alice);
		$this->assertSame($alice, $this->service->getFromAccount('Alice.bsky.social'));
	}

	public function testWithoutRetrievalOnlyTheCacheAnswers(): void {
		$this->bluesky->expects($this->never())->method('resolve');
		$this->bluesky->method('cached')->willReturn(null);
		$this->expectException(CacheActorDoesNotExistException::class);
		$this->service->getFromAccount('alice.bsky.social', false);
	}

	public function testAnIdOnBskyAppIsResolvedByDid(): void {
		$alice = $this->person('https://bsky.app/profile/' . self::DID);
		$this->bluesky->expects($this->once())->method('resolve')->with(self::DID, true)->willReturn($alice);
		$this->assertSame($alice, $this->service->getFromId('https://bsky.app/profile/' . self::DID . '#main-key', true));
		$this->assertSame($alice, $this->service->getFromId('https://bsky.app/profile/' . self::DID), 'and then remembered for the request');
	}

	public function testOneOfOurOwnHandlesIsTheLocalAccount(): void {
		$local = $this->person('https://social.example.com/@alice');
		$local->setLocal(true);
		$this->identities->method('getByHandle')->with('alice.social.example.com')->willReturn(new Identity(1, $local->getId(), self::DID, 'alice.social.example.com', '', '', '', Identity::STATE_ACTIVE, '', 0));
		$this->cache->method('getFromId')->with($local->getId())->willReturn($local);
		$this->bluesky->expects($this->never())->method('resolve');
		$this->assertSame($local, $this->service->getFromAccount('alice.social.example.com'));
	}

	public function testAnUnknownHandleUnderOurHostIsNobody(): void {
		$this->identities->method('getByHandle')->willThrowException(new AtprotoIdentityNotFoundException());
		$this->bluesky->expects($this->never())->method('resolve');
		$this->expectException(CacheActorDoesNotExistException::class);
		$this->service->getFromAccount('nobody.social.example.com');
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}
}
