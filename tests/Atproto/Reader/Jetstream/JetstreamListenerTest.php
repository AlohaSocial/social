<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader\Jetstream;

use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamClient;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamEvents;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamListener;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class JetstreamListenerTest extends TestCase {
	/** @var array<string, string> app values */
	private array $values = [];
	/** @var Watch[] */
	private array $watched = [];
	/** @var JetstreamClient&MockObject */
	private JetstreamClient $client;
	/** @var JetstreamEvents&MockObject */
	private JetstreamEvents $events;
	private JetstreamListener $listener;

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('jetstream')->willReturn('wss://jetstream.test');
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->values[$key] ?? '');
		$configService->method('setAppValue')->willReturnCallback(function (string $key, string $value): void {
			$this->values[$key] = $value;
		});
		$watches = $this->createMock(AtprotoWatchRequest::class);
		$watches->method('getAll')->willReturnCallback(fn (): array => $this->watched);
		$this->client = $this->createMock(JetstreamClient::class);
		$this->events = $this->createMock(JetstreamEvents::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->listener = new JetstreamListener($config, $configService, $watches, $this->events, $this->client, $time, new NullLogger());
	}

	public function testTheAddressAsksForTheCollectionsAndForNothingUntilTheAccountsAreNamed(): void {
		$this->assertSame(
			'wss://jetstream.test/subscribe?wantedCollections=app.bsky.feed.post&wantedCollections=app.bsky.feed.repost&wantedCollections=app.bsky.actor.profile&requireHello=true&cursor=42',
			JetstreamListener::url('wss://jetstream.test/', 42),
		);
		$this->assertStringStartsWith('ws://127.0.0.1:6008/subscribe?wanted', JetstreamListener::url('ws://127.0.0.1:6008/subscribe'));
		$this->assertSame(
			['type' => 'options_update', 'payload' => ['wantedCollections' => JetstreamEvents::COLLECTIONS, 'wantedDids' => ['did:plc:a'], 'maxMessageSizeBytes' => 0]],
			json_decode(JetstreamListener::optionsUpdate(['did:plc:a']), true),
		);
	}

	/** An empty list of accounts would be the whole network. */
	public function testNobodyFollowedIsNoConnection(): void {
		$this->client->expects($this->never())->method('connect');

		$this->assertSame(0, $this->listener->listen(static fn (): null => null, 0, true));
		$this->assertFalse($this->listener->status()['connected']);
	}

	public function testTheFollowedAccountsAreAskedForFromAFewSecondsBeforeWhereItStopped(): void {
		$this->watched = [new Watch('did:plc:b', 'b.test', '', 0, 0, 0, '', 0), new Watch('did:plc:a', 'a.test', '', 0, 0, 0, '', 0)];
		$this->values[ConfigService::ATPROTO_JETSTREAM_CURSOR] = '1760000000000000';
		$connected = false;
		$this->client->method('isConnected')->willReturnCallback(static function () use (&$connected): bool {
			return $connected;
		});
		$this->client->expects($this->once())->method('connect')->with($this->stringContains('cursor=1759999995000000'))->willReturnCallback(static function () use (&$connected): void {
			$connected = true;
		});
		$this->client->expects($this->once())->method('send')->with(JetstreamListener::optionsUpdate(['did:plc:a', 'did:plc:b']));
		$this->client->method('read')->willReturnOnConsecutiveCalls(['{"did":"did:plc:a","time_us":1760000001000000,"kind":"commit"}', 'not json'], []);
		$this->events->expects($this->once())->method('setWatched')->with(['did:plc:a', 'did:plc:b']);
		$this->events->expects($this->once())->method('handle')->with($this->callback(static fn (array $event): bool => $event['did'] === 'did:plc:a'));

		$this->assertSame(0, $this->listener->listen(static fn (): null => null, 0, true));

		$this->assertSame('1760000001000000', $this->values[ConfigService::ATPROTO_JETSTREAM_CURSOR], 'where it got to is kept');
		$this->assertSame(2, $this->listener->status()['accounts']);
		$this->assertFalse($this->listener->status()['running'], 'and it says it stopped');
	}
}
