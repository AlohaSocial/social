<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AlohaDirectory;

use OCA\Social\AlohaDirectory\DirectoryApiException;
use OCA\Social\AlohaDirectory\DirectoryClient;
use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCA\Social\AlohaDirectory\DirectoryKey;
use OCA\Social\AlohaDirectory\MessageSigner;
use OCA\Social\Security\PrivateKeyCipher;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class DirectoryClientTest extends TestCase {
	private const BASE = 'https://directory.aloha.social/api/v1/servers/';

	private FakeCurl $curl;
	private DirectoryClient $client;
	private DirectoryKey $key;

	protected function setUp(): void {
		$this->curl = new FakeCurl();
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794586000);
		$config = new DirectoryConfig((new AppConfigStore())->mock($this), $this->createStub(PrivateKeyCipher::class));
		$this->client = new DirectoryClient($this->curl, $config, new MessageSigner(), $time);
		$this->key = DirectoryKey::generate();
	}

	public function testEnrolmentIsUnsignedAndCarriesTheKey(): void {
		$this->curl->answer('POST', self::BASE . 'enrol', 201, ['challenge' => 'abc', 'key_id' => $this->key->keyId()]);

		$answer = $this->client->enrol('cloud.example.com', $this->key, 'admin@example.com');

		$this->assertSame('abc', $answer['challenge']);
		$sent = $this->curl->sent[0];
		$this->assertArrayNotHasKey('Signature', $sent['options']['headers']);
		$this->assertSame(['domain' => 'cloud.example.com', 'public_key' => $this->key->publicKey(), 'contact_email' => 'admin@example.com'], json_decode($sent['options']['body'], true));
	}

	public function testSignedRequestsAreSignedForTheDirectoryAndFollowNoRedirect(): void {
		$this->curl->answer('PUT', self::BASE . 'cloud.example.com/heartbeat', 200, ['accepted' => true, 'state' => 'listed']);

		$this->client->putHeartbeat('cloud.example.com', $this->key, ['domain' => 'cloud.example.com']);

		$options = $this->curl->sent[0]['options'];
		$this->assertStringContainsString('keyid="' . $this->key->keyId() . '"', $options['headers']['Signature-Input']);
		$this->assertStringContainsString('"content-digest"', $options['headers']['Signature-Input']);
		$this->assertSame(0, $options['max_redirects']);
		$this->assertTrue($options['accept_errors']);
	}

	public function testARefusalCarriesTheDirectorysError(): void {
		$this->curl->answer('PUT', self::BASE . 'cloud.example.com/profile', 422, [
			'error' => 'validation_failed', 'message' => 'Some fields are invalid.', 'details' => ['terms.rules' => ['is required']],
		]);

		try {
			$this->client->putProfile('cloud.example.com', $this->key, []);
			$this->fail('a 422 must throw');
		} catch (DirectoryApiException $e) {
			$this->assertSame(422, $e->getStatus());
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertSame(['terms.rules' => ['is required']], $e->getDetails());
			$this->assertFalse($e->isTemporary());
		}
	}

	public function testAnUnreachableDirectoryIsTemporary(): void {
		try {
			$this->client->status('cloud.example.com', $this->key);
			$this->fail('no answer must throw');
		} catch (DirectoryApiException $e) {
			$this->assertSame(0, $e->getStatus());
			$this->assertTrue($e->isTemporary());
		}
	}

	public function testKeyRotationIsProvenByTheNewKey(): void {
		$next = DirectoryKey::generate();
		$this->curl->answer('POST', self::BASE . 'cloud.example.com/key', 200, ['key_id' => $next->keyId()]);

		$this->client->rotateKey('cloud.example.com', $this->key, $next);

		$body = json_decode($this->curl->sent[0]['options']['body'], true);
		$this->assertSame($next->publicKey(), $body['public_key']);
		$proof = (string)base64_decode(strtr($body['proof'], '-_', '+/'), true);
		$public = sodium_crypto_sign_publickey_from_secretkey($next->secretKey());
		$this->assertTrue(sodium_crypto_sign_verify_detached($proof, 'aloha-directory-key-rotation:cloud.example.com:' . $next->keyId(), $public));
		$this->assertStringContainsString('keyid="' . $this->key->keyId() . '"', $this->curl->sent[0]['options']['headers']['Signature-Input'], 'signed with the current key');
	}
}
