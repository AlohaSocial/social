<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Reader\BlueskySearch;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\DirectorySource;
use OCA\Social\Model\Details;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DirectoryService;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\FediverseService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Bluesky as a directory source: there while Bluesky is on, asked through
 * the typeahead, its accounts marked known when the cache holds them.
 */
#[AllowMockObjectsWithoutExpectations]
class FediverseDirectoryServiceBlueskyTest extends TestCase {
	/** @var BlueskySearch&MockObject */
	private BlueskySearch $bluesky;
	/** @var CacheActorService&MockObject */
	private CacheActorService $cacheActorService;

	protected function setUp(): void {
		$this->bluesky = $this->createMock(BlueskySearch::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
	}

	public function testTheSourceIsThereWhileBlueskyIsOn(): void {
		$this->bluesky->method('isCandidate')->willReturn(true);
		$hosts = array_map(static fn (DirectorySource $s): string => $s->getHost() . ':' . $s->getKind(), $this->service(true)->sources());
		$this->assertContains('bsky.app:bluesky', $hosts);

		$this->bluesky = $this->createMock(BlueskySearch::class);
		$this->bluesky->method('isCandidate')->willReturn(false);
		$hosts = array_map(static fn (DirectorySource $s): string => $s->getHost(), $this->service(true)->sources());
		$this->assertNotContains('bsky.app', $hosts, 'off: no source');
		$this->assertNotContains('bsky.app', array_map(static fn (DirectorySource $s): string => $s->getHost(), $this->service(false)->sources()), 'not wired: no source');
	}

	public function testASearchAsksTheTypeaheadAndMarksTheCachedOnesKnown(): void {
		$this->bluesky->method('isCandidate')->willReturn(true);
		$alice = new Person();
		$alice->setId('https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz');
		$alice->setAccount('alice.bsky.social');
		$alice->setName('Alice');
		$alice->setUrl('https://bsky.app/profile/alice.bsky.social');
		$alice->setDetailArray(Details::COUNT, ['followers' => 12, 'following' => 3, 'post' => 56]);
		$bob = new Person();
		$bob->setId('https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur');
		$bob->setAccount('bob.bsky.social');
		$this->bluesky->expects($this->once())->method('typeahead')->with('alice.b', 10)->willReturn([$alice, $bob]);
		$this->cacheActorService->method('getFromAccount')->willReturnCallback(static fn (string $acct): Person => $acct === 'alice.bsky.social' ? $alice : throw new CacheActorDoesNotExistException());

		$found = $this->service(true)->search('alice.b', 'bsky.app', 10);
		$this->assertCount(2, $found['accounts']);
		[$first, $second] = $found['accounts'];
		$this->assertSame('alice.bsky.social', $first->getAcct());
		$this->assertSame(DirectorySource::KIND_BLUESKY, $first->getKind());
		$this->assertSame('Alice', $first->getDisplayName());
		$this->assertSame(12, $first->getFollowersCount());
		$this->assertSame('https://bsky.app/profile/alice.bsky.social', $first->getUrl());
		$this->assertTrue($first->isKnown());
		$this->assertFalse($second->isKnown());
	}

	private function service(bool $withBluesky): FediverseDirectoryService {
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudHost')->willReturn('social.test');
		$configService->method('getAppValue')->willReturn('');
		$fediverse = $this->createStub(FediverseService::class);
		$fediverse->method('authorized')->willReturn(true);
		$fediverse->method('isSilenced')->willReturn(false);
		$stats = $this->createMock(InstanceStatsRequest::class);
		$stats->method('remoteHostCounts')->willReturn([]);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->createStub(ICache::class));

		return new FediverseDirectoryService(
			$this->createMock(CurlService::class),
			$configService,
			$this->createMock(DirectoryService::class),
			$this->cacheActorService,
			$fediverse,
			$stats,
			new NullLogger(),
			$factory,
			$withBluesky ? $this->bluesky : null,
		);
	}
}
