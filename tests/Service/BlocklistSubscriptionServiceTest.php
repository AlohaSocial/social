<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Service\BlocklistImportService;
use OCA\Social\Service\BlocklistSubscriptionService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Tests\Helper\EndlessStream;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class BlocklistSubscriptionServiceTest extends TestCase {
	private ConfigService|Stub $configService;
	private FediverseService|MockObject $fediverseService;
	private IClient|Stub $client;
	private BlocklistSubscriptionService $service;
	private string $stored = '[]';

	protected function setUp(): void {
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->stored);
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, string $value): void {
				$this->stored = $value;
			});

		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->fediverseService->method('getAccessType')->willReturn('all_but');

		$this->client = $this->createStub(IClient::class);
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$this->service = new BlocklistSubscriptionService(
			$this->configService,
			new BlocklistImportService($this->fediverseService),
			$clientService,
			new NullLogger(),
		);
	}

	private function answers(string $body): void {
		$response = $this->createStub(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$this->client->method('get')->willReturn($response);
	}

	/**
	 * A server's moderation is its own. Adopting somebody else's is a decision
	 * an administrator makes, not one they inherit by installing this app.
	 */
	public function testEverySourceIsOffUntilSomebodySaysOtherwise(): void {
		foreach ($this->service->sources() as $source) {
			$this->assertFalse($source['enabled'], $source['id'] . ' follows a list nobody asked for');
		}

		$this->assertFalse($this->service->anyEnabled());
	}

	/**
	 * Only servers that actually publish.
	 *
	 * Mastodon answers `/api/v1/instance/domain_blocks` only where its admins
	 * turned publishing on, and most have not — chaos.social among them. A
	 * source that can only ever say "that server did not hand over a list" is
	 * a switch that does nothing, so it is not offered.
	 */
	public function testTheListsOfferedAreTheOnesThatPublishThem(): void {
		$ids = array_column($this->service->sources(), 'id');

		$this->assertSame(['mastodon.social', 'thebad.space'], $ids);
	}

	public function testTurningOneOnIsRemembered(): void {
		$this->service->configure('mastodon.social', true);

		$sources = array_column($this->service->sources(), 'enabled', 'id');

		$this->assertTrue($sources['mastodon.social']);
		$this->assertFalse($sources['thebad.space']);
		$this->assertTrue($this->service->anyEnabled());
	}

	public function testASourceNobodyOffersCannotBeTurnedOn(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->configure('made.up', true);
	}

	/**
	 * A source is a published file on the internet. An address inside the
	 * network this server sits in would have it fetched on a timer by
	 * whoever typed it.
	 */
	public function testASourceMayOnlyReadHttpsFromANamedHost(): void {
		$refused = [];
		foreach (['http://plain.example/list', 'https://10.0.0.5/list', 'https://localhost/list', 'file:///etc/passwd'] as $url) {
			try {
				$this->service->configure('mastodon.social', true, $url);
			} catch (InvalidArgumentException) {
				$refused[] = $url;
			}
		}

		$this->assertSame(
			['http://plain.example/list', 'https://10.0.0.5/list', 'https://localhost/list', 'file:///etc/passwd'],
			$refused
		);
	}

	public function testAFetchAppliesWhatTheListSays(): void {
		$this->answers('[{"domain":"blocked.example","severity":"suspend"},{"domain":"limited.example","severity":"silence"}]');
		$this->fediverseService->expects($this->once())->method('addAddresses')
			->with(['blocked.example'])->willReturn(1);
		$this->fediverseService->expects($this->once())->method('silenceAddresses')->with(['limited.example']);

		$result = $this->service->fetch('mastodon.social');

		$this->assertSame(1, $result['blocked']);
		$this->assertSame(1, $result['silenced']);
		$this->assertSame(2, $result['read']);
	}

	/** What an administrator presses before following a list. */
	public function testADryRunReadsTheListAndChangesNothing(): void {
		$this->answers('[{"domain":"blocked.example","severity":"suspend"}]');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$result = $this->service->fetch('mastodon.social', true);

		$this->assertSame(1, $result['blocked']);
		$this->assertTrue($result['dryRun']);
		// a question is not what this source last did
		$this->assertSame([], $this->service->sources()[0]['lastResult']);
	}

	/**
	 * A server publishes its list only if its administrators turned that on,
	 * and one can turn it back off — or an administrator can re-point a source
	 * at a server that never did. That answer has to be one they can read.
	 */
	public function testAServerThatPublishesNothingSaysSo(): void {
		$this->client->method('get')->willThrowException(new RuntimeException('404'));
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$result = $this->service->fetch('mastodon.social');

		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('did not hand over a list', $result['error']);
	}

	/** And what it did is written down, for the page to show afterwards. */
	public function testWhatARunDidIsRemembered(): void {
		$this->answers('[{"domain":"blocked.example","severity":"suspend"}]');
		$this->fediverseService->method('addAddresses')->willReturn(1);

		$this->service->fetch('mastodon.social');

		$source = $this->service->sources()[0];
		$this->assertSame(1, $source['lastResult']['blocked']);
		$this->assertGreaterThan(0, $source['lastRun']);
	}

	/** The daily job reads what is on, and nothing else. */
	public function testOnlyTheSourcesThatAreOnAreRead(): void {
		$this->answers('[{"domain":"blocked.example","severity":"suspend"}]');
		$this->fediverseService->method('addAddresses')->willReturn(1);
		$this->service->configure('mastodon.social', true);

		$results = $this->service->fetchEnabled();

		$this->assertSame(['mastodon.social'], array_keys($results));
	}

	/**
	 * A list is read no further than one byte past the import ceiling, and
	 * refused as too large, rather than buffered whole first.
	 */
	public function testAListLargerThanTheCeilingIsRefusedWithoutReadingItAll(): void {
		$options = [];
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(EndlessStream::open());
		$this->client->method('get')->willReturnCallback(function (string $url, array $sent) use ($response, &$options): IResponse {
			$options = $sent;

			return $response;
		});
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$result = $this->service->fetch('mastodon.social');

		$this->assertTrue($options['stream'] ?? false);
		$this->assertStringContainsString('larger than', (string)($result['error'] ?? ''));
		$this->assertLessThan(BlocklistImportService::MAX_BYTES + 1024 * 1024, EndlessStream::$read);
	}
}
