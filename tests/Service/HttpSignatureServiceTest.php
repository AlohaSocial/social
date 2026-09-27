<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\InstanceActor;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Service\InstanceActorService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Signing outbound fetches. Unsigned, a peer running Mastodon's
 * AUTHORIZED_FETCH or GoToSocial's secure mode answers 401 to every actor,
 * object, collection and outbox GET this app makes.
 */
class HttpSignatureServiceTest extends TestCase {
	private const INSTANCE_ACTOR = 'https://cloud.example.com/apps/social/actor';

	private static string $privateKey;
	private static string $publicKey;

	/** @var InstanceActorService&MockObject */
	private $instanceActorService;

	public static function setUpBeforeClass(): void {
		$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($res, $private);
		self::$privateKey = $private;
		self::$publicKey = openssl_pkey_get_details($res)['key'];
	}

	protected function setUp(): void {
		$this->instanceActorService = $this->createMock(InstanceActorService::class);
	}

	private function signsWith(string $privateKey): void {
		$actor = new InstanceActor();
		$actor->setId(self::INSTANCE_ACTOR);
		$actor->setPrivateKey($privateKey);

		$this->instanceActorService->method('getSigningActor')->willReturn($actor);
	}

	private function service(): HttpSignatureService {
		return new HttpSignatureService(
			$this->createMock(ActorsRequest::class), $this->instanceActorService, new NullLogger()
		);
	}

	private function url(string $path = '/users/bob', string $host = 'remote.example'): string {
		return 'https://' . $host . $path;
	}

	/** @return array<string, string> */
	private function signatureParts(array $headers): array {
		$parts = [];
		foreach (explode(',', $headers['Signature']) as $entry) {
			[$k, $v] = explode('=', $entry, 2);
			$parts[$k] = trim($v, '"');
		}

		return $parts;
	}

	/**
	 * The key that signs is the server's own, never a person's: the owner of a
	 * signing key is dereferenced by every peer that checks it.
	 */
	public function testAFetchIsSignedWithTheInstanceActorsKey(): void {
		$this->signsWith(self::$privateKey);

		$headers = $this->service()->signFetch($this->url());

		$parts = $this->signatureParts($headers);
		$this->assertSame(self::INSTANCE_ACTOR . '#main-key', $parts['keyId']);
		$this->assertSame('rsa-sha256', $parts['algorithm']);
		$this->assertSame('(request-target) host date', $parts['headers']);
	}

	/**
	 * A GET has no body: signing a digest or a content-length over nothing is
	 * something a peer is entitled to find strange.
	 */
	public function testAFetchSignsNeitherDigestNorContentLength(): void {
		$this->signsWith(self::$privateKey);

		$headers = $this->service()->signFetch($this->url());

		$this->assertSame(['host', 'date', 'Signature'], array_keys($headers));
		$this->assertSame('remote.example', $headers['host']);
		$this->assertNotEmpty($headers['date']);
	}

	public function testTheSignedStringIsTheRequestAsItGoesOut(): void {
		$this->signsWith(self::$privateKey);

		$headers = $this->service()->signFetch($this->url('/users/bob/outbox?page=2'));

		$expected = implode("\n", [
			'(request-target): get /users/bob/outbox?page=2',
			'host: remote.example',
			'date: ' . $headers['date'],
		]);

		$this->assertSame(
			1,
			openssl_verify(
				$expected,
				base64_decode($this->signatureParts($headers)['signature'], true),
				self::$publicKey,
				OPENSSL_ALGO_SHA256
			)
		);
	}

	/**
	 * A peer on a non-default port serves a different vhost for it, and checks
	 * the signed `host` against the authority it was asked for.
	 */
	public function testANonDefaultPortIsPartOfTheSignedHost(): void {
		$this->signsWith(self::$privateKey);

		$headers = $this->service()->signFetch('https://remote.example:8443/users/bob');

		$this->assertSame('remote.example:8443', $headers['host']);
	}

	public function testTheDefaultPortIsNotWrittenIntoTheSignedHost(): void {
		$this->signsWith(self::$privateKey);

		$this->assertSame('remote.example', $this->service()->signFetch('https://remote.example:443/x')['host']);
		$this->assertSame('remote.example', $this->service()->signFetch('http://remote.example:80/x')['host']);
	}

	/**
	 * Signing as whoever triggered the fetch would tell the remote instance
	 * which of our accounts reads which of their posts. One fixed identity does
	 * not.
	 */
	public function testEveryFetchIsSignedByTheSameActor(): void {
		$this->signsWith(self::$privateKey);
		$service = $this->service();

		$first = $service->signFetch($this->url());
		$second = $service->signFetch($this->url('/users/carol'));

		$this->assertSame(self::INSTANCE_ACTOR . '#main-key', $this->signatureParts($first)['keyId']);
		$this->assertSame(self::INSTANCE_ACTOR . '#main-key', $this->signatureParts($second)['keyId']);
	}

	/**
	 * An instance that cannot produce a key pair — it does not know its own URL
	 * yet, or OpenSSL refused — still fetches. Unsigned is what the request was
	 * until now, and against a peer that does not demand a signature it works.
	 */
	public function testWithoutAnInstanceActorTheFetchGoesOutUnsigned(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn(null);

		$this->assertSame([], $this->service()->signFetch($this->url()));
	}

	public function testAnUnusableKeyLeavesTheRequestUnsigned(): void {
		$this->signsWith('not a key');

		$this->assertSame([], $this->service()->signFetch($this->url()));
	}
}
