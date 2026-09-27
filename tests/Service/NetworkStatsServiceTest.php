<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\NetworkStatsService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The one set of figures on the statistics page this server did not count.
 *
 * What is asserted here is what happens when somebody else's survey is the
 * source: that it is quoted with its date, that an instance which will not
 * make the request says so by having no section at all rather than zeros, and
 * that a survey which is down is not asked again for every reader.
 */
class NetworkStatsServiceTest extends TestCase {
	private CurlService|Stub $curlService;
	private ConfigService|Stub $configService;
	private InstanceStatsRequest|Stub $instanceStatsRequest;
	private NetworkStatsService $service;

	/** what the survey answers with, or a Throwable it raises */
	private mixed $answer = null;
	/** and what it answers about the platforms */
	private mixed $platforms = null;
	/** the `network_stats` app value */
	private string $configured = '';
	/** how many requests were made */
	private int $asked = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->curlService = $this->createStub(CurlService::class);
		$this->curlService->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url): array {
				$this->asked++;
				if (str_contains($url, '/software')) {
					if ($this->platforms instanceof \Throwable) {
						throw $this->platforms;
					}

					return is_array($this->platforms) ? $this->platforms : [];
				}

				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return is_array($this->answer) ? $this->answer : [];
			});

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string
				=> ($key === NetworkStatsService::CONFIG_KEY) ? $this->configured : '');

		$this->instanceStatsRequest = $this->createStub(InstanceStatsRequest::class);
		$this->instanceStatsRequest->method('countRemoteDomains')->willReturn(2841);

		$this->service = $this->build($this->createStub(ICache::class));
	}

	private function build(ICache|MockObject $cache): NetworkStatsService {
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new NetworkStatsService(
			$this->curlService,
			$this->configService,
			$this->instanceStatsRequest,
			new NullLogger(),
			$factory
		);
	}

	/** The survey's answer, in the shape fedidb actually sends. */
	private function survey(array $extra = []): array {
		return array_merge([
			'total_users' => 14375788,
			'total_statuses' => 1652970897,
			'total_instances' => 42890,
			'monthly_active_users' => 1306773,
			'last_updated_at' => '2026-09-19T22:15:03+00:00',
		], $extra);
	}

	public function testTheNetworkIsReportedWithThisInstancesOwnReachBesideIt(): void {
		$this->answer = $this->survey();

		$network = $this->service->network();

		$this->assertSame(42890, $network['servers']);
		$this->assertSame(14375788, $network['accounts']);
		$this->assertSame(1306773, $network['active']);
		$this->assertSame(1652970897, $network['posts']);
		// counted here, not quoted: it is the only number in the section that
		// is about this server
		$this->assertSame(2841, $network['peers']);
	}

	public function testTheSurveyIsNamedAndDated(): void {
		$this->answer = $this->survey();

		$network = $this->service->network();

		// a figure about the whole fediverse is somebody's survey, and a page
		// that does not say whose is claiming it
		$this->assertSame('FediDB', $network['source']);
		$this->assertSame('https://fedidb.org', $network['source_url']);
		$this->assertSame('2026-09-19T22:15:03+00:00', $network['measured']);
	}

	public function testAnAdministratorCanRefuseTheOutboundRequest(): void {
		$this->configured = '0';
		$this->answer = $this->survey();

		$this->assertNull($this->service->network());
		$this->assertSame(0, $this->asked, 'the request was made anyway');
	}

	public function testASurveyThatDoesNotAnswerLeavesTheSectionOut(): void {
		$this->answer = new RuntimeException('down');

		$this->assertNull($this->service->network());
	}

	/**
	 * An answer with no count of servers is not an answer: that figure is the
	 * one the section exists to put beside "the servers this one talks to".
	 */
	public function testAnAnswerMissingTheOneFigureThatMattersIsRefused(): void {
		$this->answer = ['total_users' => 100];

		$this->assertNull($this->service->network());
	}

	public function testTheAnswerIsKeptRatherThanAskedForEveryReader(): void {
		$this->answer = $this->survey();
		$kept = [];
		$cache = $this->createStub(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, $value) use (&$kept): bool {
			$kept[$key] = $value;

			return true;
		});
		$cache->method('get')->willReturnCallback(function (string $key) use (&$kept) {
			return is_string($kept[$key] ?? null) ? $kept[$key] : null;
		});
		$this->service = $this->build($cache);

		$first = $this->service->network();
		$second = $this->service->network();

		$this->assertSame($first, $second);
		$this->assertSame(1, $this->asked);
	}

	public function testASurveyThatIsDownIsNotAskedAgainForTheNextReader(): void {
		$this->answer = new RuntimeException('down');
		$kept = [];
		$cache = $this->createStub(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, $value) use (&$kept): bool {
			$kept[$key] = $value;

			return true;
		});
		$cache->method('get')->willReturnCallback(function (string $key) use (&$kept) {
			return is_string($kept[$key] ?? null) ? $kept[$key] : null;
		});
		$this->service = $this->build($cache);

		$this->service->network();
		$this->service->network();

		// a statistics page that cannot reach the survey must not spend four
		// seconds finding that out again
		$this->assertSame(1, $this->asked);
	}

	/** One row of the platform list, in the shape fedidb sends it. */
	private function platform(string $name, int $accounts, int $servers = 10): array {
		return [
			'name' => $name,
			'user_count' => $accounts,
			'instance_count' => $servers,
			'monthly_active_users' => (int)($accounts / 10),
			'status_count' => $accounts * 100,
		];
	}

	/**
	 * The totals say how big the fediverse is and nothing about what it is.
	 * "Forty thousand servers" is an abstraction; the platforms are a picture
	 * of a place.
	 */
	public function testThePlatformsComeBackLargestFirstWithTheirShare(): void {
		$this->platforms = [
			$this->platform('Pixelfed', 1000000),
			$this->platform('Mastodon', 8000000),
			$this->platform('PeerTube', 1000000),
		];

		$software = $this->service->software();

		$this->assertSame(
			['Mastodon', 'Pixelfed', 'PeerTube'],
			array_column($software['platforms'], 'name')
		);
		$this->assertSame(80.0, $software['platforms'][0]['share']);
		$this->assertSame(10000000, $software['accounts']);
	}

	/** So the shares still sum to the whole and nobody wonders what is missing. */
	public function testWhatIsNotNamedIsAddedTogetherRatherThanDropped(): void {
		$rows = [];
		for ($i = 0; $i < 9; $i++) {
			$rows[] = $this->platform('Platform ' . $i, 1000 * (10 - $i));
		}
		$this->platforms = $rows;

		$software = $this->service->software();

		$this->assertCount(NetworkStatsService::SOFTWARE_NAMED + 1, $software['platforms']);
		$rest = end($software['platforms']);
		$this->assertSame('', $rest['name'], 'the remainder is not a platform and has no name');
		// the three that were not named: 4000 + 3000 + 2000
		$this->assertSame(9000, $rest['accounts']);
		$this->assertEqualsWithDelta(
			100.0, array_sum(array_column($software['platforms'], 'share')), 0.3
		);
	}

	/**
	 * Of the seventy-odd platforms the survey lists, a third have never been
	 * met — and a band of zero width with a name on it is noise.
	 */
	public function testAPlatformWithNobodyOnItIsLeftOut(): void {
		$this->platforms = [$this->platform('Mastodon', 100), $this->platform('Gitea', 0, 0)];

		$this->assertSame(['Mastodon'], array_column($this->service->software()['platforms'], 'name'));
	}

	public function testAnAdministratorCanRefuseThisRequestToo(): void {
		$this->configured = '0';
		$this->platforms = [$this->platform('Mastodon', 100)];

		$this->assertNull($this->service->software());
		$this->assertSame(0, $this->asked);
	}

	public function testAPlatformListThatDoesNotAnswerLeavesTheCardOut(): void {
		$this->platforms = new RuntimeException('down');

		$this->assertNull($this->service->software());
	}
}
