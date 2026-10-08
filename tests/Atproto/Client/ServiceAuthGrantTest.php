<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\ServiceAuthGrant;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ServiceAuthGrantTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const HERE = 'did:web:social.test';
	private const VIDEO = 'did:web:video.bsky.app';

	private PrivateKey $key;
	private Identity $alice;
	private int $now = 1760000000;
	private ServiceAuth $serviceAuth;
	private ServiceAuthGrant $grants;
	private ClientSession $session;

	protected function setUp(): void {
		$this->key = PrivateKey::generate(Curve::K256);
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('serviceDid')->willReturn(self::HERE);
		$config->method('videoServiceDid')->willReturn(self::VIDEO);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('signingKey')->willReturnCallback(fn (): PrivateKey => $this->key);
		$identities->method('getByDid')->willReturnCallback(fn (string $did): Identity => $did === self::DID ? $this->alice : throw new AtprotoIdentityNotFoundException());
		$alice = new Person();
		$alice->setUserId('alice');
		$actors = $this->createMock(ActorsRequest::class);
		$actors->method('getFromId')->willReturn($alice);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->serviceAuth = new ServiceAuth($time);
		$this->grants = new ServiceAuthGrant($config, $identities, $this->serviceAuth, $actors, $time);
		$this->session = new ClientSession('alice', $this->alice, 'sid');
	}

	public function testATokenForTheVideoServiceIsSignedByTheAccount(): void {
		$token = $this->grants->grant($this->session, self::VIDEO, 'app.bsky.video.getUploadLimits')['token'];
		[$header, $payload, $signature] = explode('.', $token);
		$claims = json_decode(Encoding::base64UrlDecode($payload), true);

		$this->assertSame(['iss' => self::DID, 'aud' => self::VIDEO, 'lxm' => 'app.bsky.video.getUploadLimits'], array_intersect_key($claims, ['iss' => 1, 'aud' => 1, 'lxm' => 1]));
		$this->assertSame($this->now + ServiceAuth::LIFETIME, $claims['exp'], 'a minute unless asked for longer');
		$this->assertTrue($this->key->publicKey()->verify($header . '.' . $payload, Encoding::base64UrlDecode($signature)));
	}

	public function testOnlyTheMethodsAVideoUploadNeedsAreGranted(): void {
		$long = $this->grants->grant($this->session, 'did:web:social.test', ServiceAuthGrant::UPLOAD, $this->now + 1800)['token'];
		$this->assertSame($this->now + 1800, json_decode(Encoding::base64UrlDecode(explode('.', $long)[1]), true)['exp']);
		$this->assertNotEmpty($this->grants->grant($this->session, 'did:web:social.test', ServiceAuthGrant::UPLOAD)['token']);

		foreach ([
			[self::VIDEO, 'com.atproto.repo.createRecord', 0, 'a write, for another service'],
			['did:web:api.bsky.app', 'app.bsky.feed.getTimeline', 0, 'any other service'],
			[self::HERE, 'com.atproto.repo.createRecord', 0, 'anything else here'],
			[self::HERE, ServiceAuthGrant::UPLOAD, $this->now + 7200, 'for two hours'],
			[self::HERE, ServiceAuthGrant::UPLOAD, $this->now - 1, 'already expired'],
		] as [$audience, $method, $exp, $what]) {
			try {
				$this->grants->grant($this->session, $audience, $method, $exp);
				$this->fail('granted: ' . $what);
			} catch (XrpcException $e) {
				$this->assertSame(400, $e->status, $what);
			}
		}
	}

	public function testTheVideoServiceUploadsInTheAccountsName(): void {
		$token = $this->serviceAuth->token($this->key, self::DID, self::HERE, ServiceAuthGrant::UPLOAD, 1800);

		$session = $this->grants->uploader('Bearer ' . $token);

		$this->assertNotNull($session);
		$this->assertSame('alice', $session->userId);
		$this->assertSame(self::DID, $session->identity->did);
	}

	public function testAnAudienceWithAnUnencodedPortIsThisServerToo(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('serviceDid')->willReturn('did:web:social.test%3A8443');
		$identities = $this->createMock(IdentityService::class);
		$identities->method('signingKey')->willReturn($this->key);
		$identities->method('getByDid')->willReturn($this->alice);
		$alice = new Person();
		$alice->setUserId('alice');
		$actors = $this->createMock(ActorsRequest::class);
		$actors->method('getFromId')->willReturn($alice);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($this->now);
		$grants = new ServiceAuthGrant($config, $identities, $this->serviceAuth, $actors, $time);

		$this->assertNotNull($grants->uploader('Bearer ' . $this->serviceAuth->token($this->key, self::DID, 'did:web:social.test:8443', ServiceAuthGrant::UPLOAD)));
	}

	public function testATokenThatIsNotForUploadingHereIsRefused(): void {
		$other = PrivateKey::generate(Curve::K256);
		foreach ([
			'another method' => $this->serviceAuth->token($this->key, self::DID, self::HERE, 'com.atproto.repo.createRecord'),
			'another audience' => $this->serviceAuth->token($this->key, self::DID, self::VIDEO, ServiceAuthGrant::UPLOAD),
			'another key' => $this->serviceAuth->token($other, self::DID, self::HERE, ServiceAuthGrant::UPLOAD),
			'an account not here' => $this->serviceAuth->token($this->key, 'did:plc:z72i7hdynmk6r22z27h6tvur', self::HERE, ServiceAuthGrant::UPLOAD),
			'longer than allowed' => $this->serviceAuth->token($this->key, self::DID, self::HERE, ServiceAuthGrant::UPLOAD, 7200),
		] as $what => $token) {
			try {
				$this->grants->uploader('Bearer ' . $token);
				$this->fail('accepted: ' . $what);
			} catch (XrpcException $e) {
				$this->assertContains($e->status, [400, 401], $what);
			}
		}

		$expired = $this->serviceAuth->token($this->key, self::DID, self::HERE, ServiceAuthGrant::UPLOAD);
		$this->now += 120;
		$this->expectException(XrpcException::class);
		$this->grants->uploader('Bearer ' . $expired);
	}

	public function testASessionTokenIsLeftToTheSession(): void {
		$header = Encoding::base64UrlEncode((string)json_encode(['typ' => 'at+jwt', 'alg' => 'ES256K']));
		$payload = Encoding::base64UrlEncode((string)json_encode(['scope' => 'com.atproto.access', 'sub' => self::DID]));

		$this->assertNull($this->grants->uploader('Bearer ' . $header . '.' . $payload . '.c2ln'));
		$this->assertNull($this->grants->uploader(''));
	}
}
