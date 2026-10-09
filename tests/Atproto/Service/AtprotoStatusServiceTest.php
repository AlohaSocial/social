<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Service;

use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\AtprotoStatusService;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AtprotoStatusServiceTest extends TestCase {
	/** @var array<string, string> what each relay answers about this server */
	private array $relayAnswers = [];

	private function service(array $relays): AtprotoStatusService {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('pdsEndpoint')->willReturn('https://social.example');
		$config->method('handleHost')->willReturn('social.example');
		$config->method('relays')->willReturn($relays);
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(function (string $verb, string $url, array $options, &$contentType, &$status): string {
			foreach ($this->relayAnswers as $relay => $answer) {
				if (str_starts_with($url, $relay . '/xrpc/com.atproto.sync.getHostStatus?hostname=social.example')) {
					$status = str_contains($answer, 'HostNotFound') ? 400 : 200;

					return $answer;
				}
			}
			$status = 404;

			return '';
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$daemon = $this->createMock(FirehoseDaemon::class);
		$daemon->method('status')->willReturn(['running' => true]);

		return new AtprotoStatusService($config, $this->createMock(ConfigService::class), $this->createMock(IdentityService::class), $this->createMock(InstanceKeyService::class), $this->createMock(EventService::class), $daemon, $this->createMock(AtprotoRepoRequest::class), $curl, $this->createMock(IConfig::class), $cacheFactory, $this->createMock(ITimeFactory::class));
	}

	private function relayCheck(array $relays): array {
		foreach ($this->service($relays)->checks(true) as $check) {
			if ($check['id'] === 'relay_host') {
				return $check;
			}
		}
		$this->fail('no relay check');
	}

	public function testARelayThatCarriesThisServerSaysHowMany(): void {
		$this->relayAnswers['https://bsky.network'] = '{"hostname":"social.example","accountCount":42,"seq":7,"status":"active"}';

		$this->assertSame(['id' => 'relay_host', 'state' => 'ok', 'detail' => 'bsky.network: active, 42 accounts'], $this->relayCheck(['https://bsky.network']));
	}

	public function testAThrottlingOrUnawareRelayIsAWarningWithWhatToDo(): void {
		$this->relayAnswers['https://bsky.network'] = '{"hostname":"social.example","accountCount":100,"status":"throttled"}';
		$this->relayAnswers['https://relay.example'] = '{"error":"HostNotFound","message":"host not found"}';

		$check = $this->relayCheck(['https://bsky.network', 'https://relay.example']);

		$this->assertSame('warning', $check['state'], 'never an error: the switch does not wait for a relay');
		$this->assertSame('bsky.network throttles this server at 100 accounts: ask its operator to raise the limit for this host; relay.example has not crawled this server yet: run occ social:atproto:crawl', $check['detail']);
	}

	public function testNoRelayIsAWarning(): void {
		$this->assertSame('warning', $this->relayCheck([])['state']);
	}
}
