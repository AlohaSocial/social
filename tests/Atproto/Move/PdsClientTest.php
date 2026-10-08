<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Exceptions\AtprotoException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PdsClientTest extends TestCase {
	private function client(bool $local): PdsClient {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('allow_local_remote_servers', false)->willReturn($local);

		return new PdsClient($this->createMock(IClientService::class), $config);
	}

	public function testAPdsIsItsHttpsOrigin(): void {
		$client = $this->client(false);

		$this->assertSame('https://pds.example.com', $client->origin('pds.example.com'));
		$this->assertSame('https://pds.example.com:8443', $client->origin('https://pds.example.com:8443/xrpc/'));
		foreach (['http://pds.example.com', 'https://user:pw@pds.example.com', 'ftp://pds.example.com', ''] as $typed) {
			try {
				$client->origin($typed);
				$this->fail('taken: ' . $typed);
			} catch (AtprotoException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testAnEmptyInputIsSentAsAnObject(): void {
		$sent = [];
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('{}');
		$http = $this->createMock(IClient::class);
		$http->method('post')->willReturnCallback(function (string $url, array $options) use (&$sent, $response) {
			$sent[] = $options['body'];
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($http);
		$client = new PdsClient($clients, $this->createMock(IConfig::class));

		$client->call('https://pds.example.com', 'com.atproto.server.deactivateAccount', 'POST', [], []);
		$client->call('https://pds.example.com', 'com.atproto.repo.createRecord', 'POST', [], ['repo' => 'did:plc:a']);

		$this->assertSame(['{}', '{"repo":"did:plc:a"}'], $sent);
	}

	public function testPlainHttpOnlyOnAServerThatTalksToLocalOnes(): void {
		$this->assertSame('http://localhost:2583', $this->client(true)->origin('http://localhost:2583'));
	}
}
