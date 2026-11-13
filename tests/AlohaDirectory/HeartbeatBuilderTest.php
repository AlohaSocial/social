<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AlohaDirectory;

use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCA\Social\AlohaDirectory\HeartbeatBuilder;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ExternalSignupsRequest;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ExternalUserService;
use OCA\Social\Service\FederationHealthService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;

class HeartbeatBuilderTest extends TestCase {
	private AppConfigStore $store;
	private bool $enabled = true;
	private int $stale = 0;
	private bool $countsHidden = false;

	private function builder(): HeartbeatBuilder {
		$config = new DirectoryConfig($this->store->mock($this), $this->createStub(PrivateKeyCipher::class));
		$external = $this->createStub(ExternalUserService::class);
		$external->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$external->method('acceptsPublicRegistrations')->willReturnCallback(fn (): bool => $this->enabled);
		$external->method('mode')->willReturn(ExternalUserService::MODE_OPEN);
		$external->method('freeAccounts')->willReturn(412);
		$signups = $this->createStub(ExternalSignupsRequest::class);
		$signups->method('countAwaitingApproval')->willReturn(3);
		$stats = $this->createStub(InstanceStatsRequest::class);
		$stats->method('countLocalAccounts')->willReturn(1588);
		$health = $this->createStub(FederationHealthService::class);
		$health->method('stuck')->willReturnCallback(fn (): array => ['abandoned' => 2, 'stale' => $this->stale]);
		$atproto = $this->createStub(AtprotoConfig::class);
		$atproto->method('isEnabled')->willReturn(false);
		$version = $this->createStub(ServerVersion::class);
		$version->method('getVersionString')->willReturn('33.0.2');
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794586000);

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getAppValueBool')->willReturnCallback(fn (string $key): bool => $key === ConfigService::SOCIAL_HIDE_INSTANCE_COUNTS && $this->countsHidden);

		return new HeartbeatBuilder($config, $external, $signups, $stats, $health, $atproto, $this->store->mock($this), $version, $time, $configService);
	}

	protected function setUp(): void {
		$this->store = new AppConfigStore();
		$this->store->values['installed_version'] = '0.27.0';
	}

	public function testTheHeartbeatReportsSignupCapacityAndHealth(): void {
		$heartbeat = $this->builder()->build('cloud.example.com', 'W/"1"');

		$this->assertSame('cloud.example.com', $heartbeat['domain']);
		$this->assertSame(['name' => 'aloha-social', 'version' => '0.27.0', 'nextcloud' => '33.0.2'], $heartbeat['software']);
		$this->assertSame(['open' => true, 'mode' => 'open', 'available_accounts' => 412, 'approval_backlog' => 3], $heartbeat['signup']);
		$this->assertSame(1588, $heartbeat['usage']['users_total']);
		$this->assertTrue($heartbeat['health']['federation_ok'], 'deliveries given up on dead peers are normal');
		$this->assertSame('W/"1"', $heartbeat['profile_etag']);
		$this->assertSame('2026-11-13T16:06:40Z', $heartbeat['sent_at']);
		$this->assertArrayNotHasKey('atproto', $heartbeat);
	}

	public function testTheAdvertisedAccountsAreCapped(): void {
		$this->store->values[DirectoryConfig::ADVERTISED_ACCOUNTS] = '50';

		$this->assertSame(50, $this->builder()->build('cloud.example.com', '')['signup']['available_accounts']);
	}

	public function testClosedSignupsAndAStuckQueueAreReported(): void {
		$this->enabled = false;
		$this->stale = 4;

		$heartbeat = $this->builder()->build('cloud.example.com', '');
		$this->assertSame('closed', $heartbeat['signup']['mode']);
		$this->assertFalse($heartbeat['signup']['open']);
		$this->assertFalse($heartbeat['health']['federation_ok']);
	}

	public function testHiddenCountsAreNotSent(): void {
		$this->countsHidden = true;

		$this->assertArrayNotHasKey('usage', $this->builder()->build('cloud.example.com', ''));
	}
}
