<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RemoteMediaQuotaService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * How much of this instance's disk one other server may occupy.
 *
 * Every picture on a post somebody here follows is fetched and kept, and
 * nothing bounded that by where it came from: one server posting large images
 * at a high rate fills the disk of every instance that follows anybody on it.
 */
#[AllowMockObjectsWithoutExpectations]
class RemoteMediaQuotaServiceTest extends TestCase {
	private const MB = 1024 * 1024;

	private ConfigService|Stub $configService;
	private RemoteMediaQuotaService $service;

	/** @var array<string, string> the app values */
	private array $appValues = [];
	/** @var array<string, mixed> the day's counters */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->appValues[$key] ?? '');

		$this->service = $this->build($this->cacheFactory(true));
	}

	private function build(ICacheFactory|MockObject $factory): RemoteMediaQuotaService {
		return new RemoteMediaQuotaService($this->configService, $factory);
	}

	private function cacheFactory(bool $available): ICacheFactory|MockObject {
		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $k) => $this->store[$k] ?? null);
		$cache->method('set')->willReturnCallback(function (string $k, $v): bool {
			$this->store[$k] = $v;

			return true;
		});
		$cache->method('remove')->willReturnCallback(function (string $k): bool {
			unset($this->store[$k]);

			return true;
		});

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($available);
		$factory->method('createDistributed')->willReturn($cache);

		return $factory;
	}

	private function quota(int $megabytes): void {
		$this->appValues[ConfigService::SOCIAL_DOMAIN_MEDIA_QUOTA] = (string)$megabytes;
	}

	/** @param array<string, int> $domains */
	private function measured(array $domains): void {
		$this->appValues[ConfigService::SOCIAL_MEDIA_USAGE] = (string)json_encode([
			'domains' => $domains, 'measured' => time(),
		]);
	}

	/**
	 * An instance that has been federating for a year and acquires a quota on
	 * upgrade would start refusing the pictures of the servers it talks to
	 * most, which is not an upgrade note anybody reads in time.
	 */
	public function testThereIsNoQuotaUntilAnAdministratorSetsOne(): void {
		$this->measured(['remote.example' => 900 * self::MB]);

		$this->assertTrue($this->service->fits('remote.example', 100 * self::MB));
		$this->assertSame(RemoteMediaQuotaService::UNLIMITED, $this->service->quota());
	}

	public function testAHostUnderItsQuotaIsAllowedMore(): void {
		$this->quota(100);
		$this->measured(['remote.example' => 50 * self::MB]);

		$this->assertTrue($this->service->fits('remote.example', 10 * self::MB));
	}

	public function testAHostThatWouldGoPastItIsRefused(): void {
		$this->quota(100);
		$this->measured(['remote.example' => 95 * self::MB]);

		$this->assertFalse($this->service->fits('remote.example', 10 * self::MB));
	}

	/** One server's disk is not another's. */
	public function testAQuotaIsPerHost(): void {
		$this->quota(100);
		$this->measured(['remote.example' => 99 * self::MB]);

		$this->assertFalse($this->service->fits('remote.example', 5 * self::MB));
		$this->assertTrue($this->service->fits('other.example', 5 * self::MB));
	}

	/**
	 * The stored figure is a day old at worst, so what has been cached since
	 * has to count — otherwise a host could spend its whole quota again
	 * between two measurements.
	 */
	public function testWhatWasCachedSinceTheLastWalkCountsToo(): void {
		$this->quota(10);
		$this->measured(['remote.example' => 5 * self::MB]);

		$this->service->record('remote.example', 4 * self::MB);

		$this->assertSame(9 * self::MB, $this->service->used('remote.example'));
		$this->assertFalse($this->service->fits('remote.example', 2 * self::MB));
	}

	/**
	 * Everything the walk counted is in the figure it wrote; leaving the day's
	 * counter would charge those bytes twice and shrink the quota with every
	 * pass.
	 */
	public function testTheDaysCounterStartsAgainWhenAWalkHasAccountedForIt(): void {
		$this->quota(10);
		$this->service->record('remote.example', 4 * self::MB);

		$this->service->forgetAll(['remote.example']);
		$this->measured(['remote.example' => 4 * self::MB]);

		$this->assertSame(4 * self::MB, $this->service->used('remote.example'));
	}

	/**
	 * Without a memcache there is nothing to count the day in, so the quota
	 * holds a day at a time rather than not at all.
	 */
	public function testWithoutACacheTheDailyFigureStillHolds(): void {
		$service = $this->build($this->cacheFactory(false));
		$this->quota(10);
		$this->measured(['remote.example' => 11 * self::MB]);

		$service->record('remote.example', 5 * self::MB);

		$this->assertSame(11 * self::MB, $service->used('remote.example'));
		$this->assertFalse($service->fits('remote.example', 1));
	}

	public function testAnInstanceThatHasNeverMeasuredRefusesNobody(): void {
		$this->quota(10);

		$this->assertTrue($this->service->fits('remote.example', 5 * self::MB));
	}

	/** An address with no host in it is nobody's quota to spend. */
	public function testAnAddressWithNoHostIsNotCharged(): void {
		$this->quota(1);
		$this->measured(['' => 100 * self::MB]);

		$this->assertTrue($this->service->fits('', 5 * self::MB));
	}

	public function testTheHostIsReadOffTheAddressAndLowerCased(): void {
		$this->assertSame(
			'remote.example',
			RemoteMediaQuotaService::chargedHost('https://Remote.Example/media/1.jpg', '')
		);
		$this->assertSame('', RemoteMediaQuotaService::chargedHost('not a url', 'neither'));
	}

	/**
	 * An attachment without an id of its own is given one under this
	 * instance's address; charged by that id, every such picture from every
	 * server landed on this instance's own name, as one domain.
	 */
	public function testADocumentIsChargedToTheHostItsBytesCameFrom(): void {
		$this->assertSame('social.b.example', RemoteMediaQuotaService::chargedHost(
			'https://social.b.example/apps/social/media/0bef598f-ef65-4857-a108-b3766689b78c.jpeg',
			'https://social.a.example/documents/g/1d2c3b4a-0000-4000-8000-000000000000'
		));
	}

	public function testADocumentWithNoUrlIsChargedToTheHostOfItsId(): void {
		$this->assertSame(
			'remote.example',
			RemoteMediaQuotaService::chargedHost('', 'https://remote.example/media/1')
		);
	}
}
