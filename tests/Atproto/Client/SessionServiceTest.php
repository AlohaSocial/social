<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use InvalidArgumentException;
use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Client\SessionService;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SessionServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private InMemoryClientRequest $request;
	private AppPasswordService $appPasswords;
	/** @var IThrottler&MockObject */
	private IThrottler $throttler;
	private SessionService $sessions;
	private int $now = 1760000000;
	private Identity $identity;
	private PrivateKey $serviceKey;

	protected function setUp(): void {
		$this->request = (new \ReflectionClass(InMemoryClientRequest::class))->newInstanceWithoutConstructor();
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(static fn (int $length, string $chars): string => substr(str_shuffle(str_repeat($chars, 4)), 0, $length));
		$this->appPasswords = new AppPasswordService($this->request, $random);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('serviceDid')->willReturn('did:web:social.test');
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByDid')->willReturnCallback(fn (string $did): Identity => $did === self::DID ? $this->identity : throw new AtprotoIdentityNotFoundException());
		$identities->method('getByHandle')->willReturnCallback(fn (string $h): Identity => $h === 'alice.social.test' ? $this->identity : throw new AtprotoIdentityNotFoundException());
		$identities->method('getByActorId')->willReturn($this->identity);
		$identities->method('document')->willReturn(['id' => self::DID]);
		$this->serviceKey = PrivateKey::generate(Curve::K256);
		$keys = $this->createMock(InstanceKeyService::class);
		$keys->method('serviceKey')->willReturnCallback(fn (): PrivateKey => $this->serviceKey);
		$actors = $this->createMock(ActorsRequest::class);
		$alice = new Person();
		$alice->setId('https://social.test/@alice');
		$alice->setUserId('alice');
		$actors->method('getFromId')->willReturn($alice);
		$actors->method('getFromUserId')->willReturn($alice);
		$users = $this->createMock(IUserManager::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getEMailAddress')->willReturn('alice@example.org');
		$users->method('getByEmail')->willReturnCallback(static fn (string $email): array => $email === 'alice@example.org' ? [$user] : []);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'alice' ? $user : null);
		$this->throttler = $this->createMock(IThrottler::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->sessions = new SessionService($config, $identities, $keys, $this->appPasswords, $this->request, $actors, $users, $this->throttler, $time);
	}

	public function testAnAppPasswordIsShownOnceAndStoredAsAHash(): void {
		$created = $this->appPasswords->create('alice', 'Bluesky on my phone');
		$this->assertMatchesRegularExpression('/^[a-z2-7]{4}(-[a-z2-7]{4}){3}$/', $created['password']);
		$stored = $this->request->passwords[$created['id']];
		$this->assertNotSame($created['password'], $stored['hash']);
		$this->assertTrue(password_verify($created['password'], $stored['hash']));
		$this->assertSame([['id' => $created['id'], 'name' => 'Bluesky on my phone', 'creation' => 1, 'last_used' => 0]], $this->appPasswords->list('alice'), 'never the hash, never the password');
		$this->expectException(InvalidArgumentException::class);
		$this->appPasswords->create('alice', 'Bluesky on my phone');
	}

	public function testSigningInGivesTokensSignedByThisServerForThisServer(): void {
		$password = $this->appPasswords->create('alice', 'phone')['password'];
		foreach (['alice.social.test', '@Alice.social.test', self::DID, 'alice@example.org'] as $identifier) {
			$session = $this->sessions->create($identifier, strtoupper($password), '203.0.113.5');
			$this->assertSame(self::DID, $session['did']);
			$this->assertSame('alice.social.test', $session['handle']);
			$access = $this->claims($session['accessJwt']);
			$this->assertSame('com.atproto.access', $access['scope']);
			$this->assertSame('did:web:social.test', $access['aud']);
			$this->assertSame($this->now + SessionService::ACCESS_LIFETIME, $access['exp']);
			$refresh = $this->claims($session['refreshJwt']);
			$this->assertSame('com.atproto.refresh', $refresh['scope']);
			$this->assertSame($access['sid'], $refresh['jti'], 'the access token names its session');
		}
		$signedIn = $this->sessions->authenticate('Bearer ' . $session['accessJwt']);
		$this->assertSame('alice', $signedIn->userId);
		$described = $this->sessions->describe($signedIn);
		$this->assertSame(self::DID, $described['did']);
		$this->assertSame(['email' => 'alice@example.org', 'emailConfirmed' => true], array_intersect_key($described, ['email' => 1, 'emailConfirmed' => 1]), 'Bluesky apps offer video to a confirmed address');
		$this->assertNull($this->sessions->authenticate(''), 'no header, nobody');
	}

	public function testAWrongPasswordCountsAgainstTheCallersAddress(): void {
		$this->appPasswords->create('alice', 'phone');
		$this->throttler->expects($this->exactly(3))->method('registerAttempt')->with('social_atproto_session', '203.0.113.5', $this->anything());
		foreach ([['alice.social.test', 'aaaa-bbbb-cccc-dddd'], ['nobody.social.test', 'aaaa-bbbb-cccc-dddd'], ['alice.social.test', 'the Nextcloud password']] as [$who, $password]) {
			try {
				$this->sessions->create($who, $password, '203.0.113.5');
				$this->fail('signed in with ' . $password);
			} catch (XrpcException $e) {
				$this->assertSame(401, $e->status);
				$this->assertSame('Invalid identifier or password', $e->getMessage(), 'the same answer for both, so nothing is learned');
			}
		}
	}

	public function testARefreshEndsTheOldSessionAndStartsANewOne(): void {
		$password = $this->appPasswords->create('alice', 'phone')['password'];
		$first = $this->sessions->create('alice.social.test', $password, '203.0.113.5');
		$second = $this->sessions->refresh('Bearer ' . $first['refreshJwt']);
		$this->assertNotSame($first['refreshJwt'], $second['refreshJwt']);
		$this->assertNotNull($this->sessions->authenticate('Bearer ' . $second['accessJwt']));
		$this->assertRefused(fn () => $this->sessions->authenticate('Bearer ' . $first['accessJwt']), 'ExpiredToken');
		$this->assertRefused(fn () => $this->sessions->refresh('Bearer ' . $first['refreshJwt']), 'ExpiredToken');
		$this->assertRefused(fn () => $this->sessions->refresh('Bearer ' . $second['accessJwt']), 'InvalidToken', 'an access token is no refresh token');
		$this->assertRefused(fn () => $this->sessions->authenticate('Bearer ' . $second['refreshJwt']), 'InvalidToken', 'nor the other way');
	}

	public function testRevokingThePasswordEndsItsSessionsAndTimeEndsAccess(): void {
		$created = $this->appPasswords->create('alice', 'phone');
		$session = $this->sessions->create('alice.social.test', $created['password'], '203.0.113.5');
		$this->now += SessionService::ACCESS_LIFETIME + 1;
		$this->assertRefused(fn () => $this->sessions->authenticate('Bearer ' . $session['accessJwt']), 'ExpiredToken');
		$fresh = $this->sessions->refresh('Bearer ' . $session['refreshJwt']);
		$this->assertTrue($this->appPasswords->revoke('alice', $created['id']));
		$this->assertRefused(fn () => $this->sessions->authenticate('Bearer ' . $fresh['accessJwt']), 'ExpiredToken');
		$this->assertFalse($this->appPasswords->revoke('bob', $created['id']), 'not bob\'s to revoke');
	}

	public function testATokenSignedByAnotherKeyOrTamperedWithIsRefused(): void {
		$password = $this->appPasswords->create('alice', 'phone')['password'];
		$session = $this->sessions->create('alice.social.test', $password, '203.0.113.5');
		[$header, $payload, $signature] = explode('.', $session['accessJwt']);
		$claims = json_decode(Encoding::base64UrlDecode($payload), true);
		$claims['sub'] = 'did:plc:someoneelse0000000000000';
		$forged = $header . '.' . Encoding::base64UrlEncode((string)json_encode($claims)) . '.' . $signature;
		$this->assertRefused(fn () => $this->sessions->authenticate('Bearer ' . $forged), 'InvalidToken');
		$this->serviceKey = PrivateKey::generate(Curve::K256);
		$this->assertRefused(fn () => $this->sessions->authenticate('Bearer ' . $session['accessJwt']), 'InvalidToken');
		$this->assertRefused(fn () => $this->sessions->authenticate('Basic abc'), 'AuthenticationRequired');
	}

	public function testAnAccountThatMovedAwayCannotSignIn(): void {
		$password = $this->appPasswords->create('alice', 'phone')['password'];
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_MOVED_AWAY, '', 0);
		$this->assertRefused(fn () => $this->sessions->create('alice.social.test', $password, '203.0.113.5'), 'AccountTakedown');
	}

	private function claims(string $jwt): array {
		[$header, $payload, $signature] = explode('.', $jwt);
		$this->assertTrue($this->serviceKey->publicKey()->verify($header . '.' . $payload, Encoding::base64UrlDecode($signature)));

		return json_decode(Encoding::base64UrlDecode($payload), true);
	}

	private function assertRefused(callable $call, string $error, string $message = ''): void {
		try {
			$call();
			$this->fail('not refused: ' . $message);
		} catch (XrpcException $e) {
			$this->assertSame($error, $e->error, $message);
		}
	}
}
