<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AlohaDirectory\DirectoryLists;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Model\Client\DirectorySource;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DirectoryService;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\FediverseService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The search providers of the Aloha Directory as sources of the people search.
 */
class FediverseDirectoryServiceCuratedTest extends TestCase {
	/** @var array<string, string> */
	private array $values = [];
	/** @var list<array{domain: string, languages: list<string>, weight: float}> */
	private array $providers = [];
	/** @var array<string, int> */
	private array $peers = [];

	private function service(): FediverseDirectoryService {
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->values[$key] ?? '');
		$config->method('getCloudHost')->willReturn('cloud.example.com');
		$stats = $this->createStub(InstanceStatsRequest::class);
		$stats->method('remoteHostCounts')->willReturnCallback(fn (): array => $this->peers);
		$lists = $this->createStub(DirectoryLists::class);
		$lists->method('curatedSearchProviders')->willReturnCallback(fn (): array => $this->providers);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->createStub(ICache::class));

		return new FediverseDirectoryService(
			$this->createStub(CurlService::class),
			$config,
			$this->createStub(DirectoryService::class),
			$this->createStub(CacheActorService::class),
			$this->createStub(FediverseService::class),
			$stats,
			new NullLogger(),
			$factory,
			null,
			$lists,
		);
	}

	/**
	 * @return array<string, string> host => origin, without this instance
	 */
	private function sources(): array {
		$sources = [];
		foreach ($this->service()->sources() as $source) {
			if (!$source->isLocal()) {
				$sources[$source->getHost()] = $source->getOrigin() . '/' . $source->getKind();
			}
		}

		return $sources;
	}

	public function testCuratedProvidersReplaceTheBuiltInSources(): void {
		$this->providers = [
			['domain' => 'suche.example.org', 'languages' => ['de'], 'weight' => 1.0],
			['domain' => 'search.example.net', 'languages' => ['en'], 'weight' => 1.0],
		];
		$this->peers = ['peer.example.org' => 10];

		$this->assertSame([
			'suche.example.org' => DirectorySource::ORIGIN_CURATED . '/' . DirectorySource::KIND_MASTODON,
			'search.example.net' => DirectorySource::ORIGIN_CURATED . '/' . DirectorySource::KIND_MASTODON,
		], $this->sources(), 'no built-in source, and peers and discovery are off by default');
	}

	public function testWithoutAVerifiedListTheBuiltInSourcesStay(): void {
		$this->assertArrayHasKey('mastodon.social', $this->sources());
	}

	public function testTheAdministratorsSourcesWin(): void {
		$this->providers = [['domain' => 'suche.example.org', 'languages' => ['de'], 'weight' => 1.0]];
		$this->values[FediverseDirectoryService::CONFIG_KEY] = (string)json_encode([['host' => 'mine.example.org', 'kind' => 'mastodon']]);

		$this->assertSame(['mine.example.org' => DirectorySource::ORIGIN_CONFIGURED . '/mastodon'], $this->sources());
	}
}
