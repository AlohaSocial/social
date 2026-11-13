<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AlohaDirectory;

use OCA\Social\AlohaDirectory\DirectoryKey;
use OCA\Social\AlohaDirectory\MessageSigner;
use PHPUnit\Framework\TestCase;

class MessageSignerTest extends TestCase {
	private DirectoryKey $key;

	protected function setUp(): void {
		$meta = json_decode((string)file_get_contents(__DIR__ . '/fixtures/meta.json'), true);
		$this->key = DirectoryKey::fromSecretKey((string)base64_decode($meta['server_secret'], true));
	}

	public function testTheKeyIdIsTheDirectorysFingerprint(): void {
		$meta = json_decode((string)file_get_contents(__DIR__ . '/fixtures/meta.json'), true);

		$this->assertSame($meta['server_key_id'], $this->key->keyId());
		$this->assertSame(43, strlen($this->key->publicKey()));
	}

	public function testABodyIsCoveredByItsDigestAndTheSignatureVerifies(): void {
		$body = '{"domain":"cloud.example.com"}';
		$headers = (new MessageSigner())->sign($this->key, 'put', 'Directory.Aloha.Social', '/api/v1/servers/cloud.example.com/heartbeat', $body, 1794586000, 'bm9uY2U');

		$this->assertSame('sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':', $headers['Content-Digest']);
		$params = '("@method" "@authority" "@path" "content-digest");created=1794586000;keyid="' . $this->key->keyId() . '";alg="ed25519";tag="aloha-directory";nonce="bm9uY2U"';
		$this->assertSame('dir=' . $params, $headers['Signature-Input']);

		$base = '"@method": PUT' . "\n"
			. '"@authority": directory.aloha.social' . "\n"
			. '"@path": /api/v1/servers/cloud.example.com/heartbeat' . "\n"
			. '"content-digest": ' . $headers['Content-Digest'] . "\n"
			. '"@signature-params": ' . $params;
		$this->assertMatchesRegularExpression('/^dir=:[A-Za-z0-9+\/=]+:$/', $headers['Signature']);
		$signature = (string)base64_decode(substr($headers['Signature'], 5, -1), true);
		$this->assertTrue(sodium_crypto_sign_verify_detached($signature, $base, sodium_crypto_sign_publickey_from_secretkey($this->key->secretKey())));
	}

	public function testAQueryIsCoveredAndAnEmptyBodyHasNoDigest(): void {
		$headers = (new MessageSigner())->sign($this->key, 'GET', 'directory.aloha.social', '/api/v1/servers/x.example/status?a=1', '', 1794586000, 'bm9uY2U');

		$this->assertArrayNotHasKey('Content-Digest', $headers);
		$this->assertStringStartsWith('dir=("@method" "@authority" "@path" "@query");', $headers['Signature-Input']);
	}

	public function testEveryRequestGetsAFreshNonce(): void {
		$signer = new MessageSigner();
		$first = $signer->sign($this->key, 'GET', 'directory.aloha.social', '/a', '', 1794586000);
		$second = $signer->sign($this->key, 'GET', 'directory.aloha.social', '/a', '', 1794586000);

		$this->assertNotSame($first['Signature'], $second['Signature']);
	}
}
