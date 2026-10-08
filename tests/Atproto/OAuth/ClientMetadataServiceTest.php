<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use OCA\Social\Atproto\OAuth\ClientMetadataService;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Service\CurlService;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ClientMetadataServiceTest extends TestCase {
	private const CLIENT = 'https://app.example.com/oauth-client-metadata.json';

	private array $document = [];
	private int $status = 200;
	private string $type = 'application/json; charset=utf-8';
	private ClientMetadataService $clients;

	protected function setUp(): void {
		$this->document = OAuthKit::clientDocument();
		$curl = $this->createMock(CurlService::class);
		$curl->method('retrieveJson')->willReturnCallback(function (string $method, string $url, array $options, ?int &$status = null, ?string &$type = null): array {
			$status = $this->status;
			$type = $this->type;

			return $this->document;
		});
		$cache = $this->createMock(ICacheFactory::class);
		$cache->method('isAvailable')->willReturn(false);
		$this->clients = new ClientMetadataService($curl, $cache);
	}

	public function testAGoodDocumentIsTaken(): void {
		$metadata = $this->clients->get(self::CLIENT);

		$this->assertSame('Example', $metadata['client_name']);
		$this->assertFalse(ClientMetadataService::isConfidential($metadata));
		$this->assertTrue(ClientMetadataService::allowsRedirect($metadata, 'https://app.example.com/callback'));
		$this->assertFalse(ClientMetadataService::allowsRedirect($metadata, 'https://app.example.com/callback/'), 'exactly');
	}

	public function testWhatTheProfileRequiresIsChecked(): void {
		foreach ([
			'another client_id' => ['client_id' => 'https://other.example.com/meta.json'],
			'no atproto scope' => ['scope' => 'transition:generic'],
			'no DPoP' => ['dpop_bound_access_tokens' => false],
			'a secret' => ['token_endpoint_auth_method' => 'client_secret_post'],
			'confidential without keys' => ['token_endpoint_auth_method' => 'private_key_jwt'],
			'no code grant' => ['grant_types' => ['refresh_token']],
			'no redirect' => ['redirect_uris' => []],
			'a web client redirecting to a custom scheme' => ['redirect_uris' => ['com.example.app:/callback']],
			'a native client redirecting to another host' => ['application_type' => 'native', 'redirect_uris' => ['https://evil.example.org/callback']],
			'a native scheme of another app' => ['application_type' => 'native', 'redirect_uris' => ['org.evil.app:/callback']],
		] as $what => $override) {
			$this->document = OAuthKit::clientDocument($override);
			try {
				$this->clients->get(self::CLIENT);
				$this->fail('taken: ' . $what);
			} catch (OAuthException $e) {
				$this->assertSame('invalid_client', $e->error, $what);
			}
		}

		$this->document = OAuthKit::clientDocument(['application_type' => 'native', 'redirect_uris' => ['com.example.app:/callback', 'https://app.example.com/back']]);
		$this->assertSame('native', $this->clients->get(self::CLIENT)['application_type'], 'a reverse-domain scheme and https on its own host');
	}

	public function testTheDocumentMustBeAnsweredAsJsonWith200(): void {
		$this->status = 302;
		$this->expectException(OAuthException::class);
		$this->clients->get(self::CLIENT);
	}

	public function testAClientIdMustBeAnHttpsAddressWithoutAPort(): void {
		foreach (['http://app.example.com/meta.json', 'https://app.example.com:8443/meta.json', 'https://user:pw@app.example.com/meta.json', 'app'] as $clientId) {
			try {
				$this->clients->get($clientId);
				$this->fail('taken: ' . $clientId);
			} catch (OAuthException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testTheLocalhostDevelopmentClientNeedsNoDocument(): void {
		$clientId = 'http://localhost?redirect_uri=' . rawurlencode('http://127.0.0.1:8080/callback') . '&scope=' . rawurlencode('atproto transition:generic');

		$metadata = $this->clients->get($clientId);

		$this->assertSame(['http://127.0.0.1:8080/callback'], $metadata['redirect_uris']);
		$this->assertSame('atproto transition:generic', $metadata['scope']);
		$this->assertTrue(ClientMetadataService::allowsRedirect($metadata, 'http://127.0.0.1:53211/callback'), 'the port is not matched');
		$this->assertFalse(ClientMetadataService::allowsRedirect($metadata, 'http://127.0.0.1:8080/other'));
		$this->assertSame(['http://127.0.0.1/', 'http://[::1]/'], $this->clients->get('http://localhost')['redirect_uris']);
		$this->assertFalse(ClientMetadataService::isLocalhost('http://localhost:8080'));
	}
}
