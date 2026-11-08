<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Client\ClientSession;
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
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\EnabledUserService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
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
	/** whether alice's Nextcloud account is enabled */
	private bool $aliceEnabled = true;
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private InMemoryClientRequest $request;
	private AppPasswordService $appPasswords;
	/** @var IThrottler&MockObject */
	private IThrottler $throttler;
	private SessionService $sessions;
	private int $now = 1760000000;
	private Identity $identity;
	private PrivateKey $serviceKey;
	private string $emailVerification = IAccountManager::VERIFIED;
	/** @var list<IUser> */
	private array $emailUsers = [];
	private IUser $aliceUser;
	private IUserManager $users;
	private ActorsRequest $actors;
	private IdentityService $identities;

	protected function setUp(): void {
		$this->request = (new \ReflectionClass(InMemoryClientRequest::class))->newInstanceWithoutConstructor();
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(static fn (int $length, string $chars): string => substr(str_shuffle(str_repeat($chars, 4)), 0, $length));
		$this->appPasswords = new AppPasswordService($this->request, $random);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('serviceDid')->willReturn('did:web:social.test');
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identities->method('getByDid')->willReturnCallback(fn (string $did): Identity => $did === self::DID ? $this->identity : throw new AtprotoIdentityNotFoundException());
		$this->identities->method('getByHandle')->willReturnCallback(fn (string $h): Identity => $h === 'alice.social.test' ? $this->identity : throw new AtprotoIdentityNotFoundException());
		$this->identities->method('getByActorId')->willReturnCallback(fn (string $id): Identity => $id === $this->identity->actorId ? $this->identity : throw new AtprotoIdentityNotFoundException());
		$this->identities->method('document')->willReturn(['id' => self::DID]);
		$this->serviceKey = PrivateKey::generate(Curve::K256);
		$keys = $this->createMock(InstanceKeyService::class);
		$keys->method('serviceKey')->willReturnCallback(fn (): PrivateKey => $this->serviceKey);
		$this->actors = $this->createMock(ActorsRequest::class);
		$alice = new Person();
		$alice->setId('https://social.test/@alice');
		$alice->setUserId('alice');
		$this->actors->method('getFromId')->willReturn($alice);
		$this->actors->method('getFromUserId')->willReturnCallback(static function (string $userId): Person {
			$actor = new Person();
			$actor->setId('https://social.test/@' . $userId);
			$actor->setUserId($userId);

			return $actor;
		});
		$this->users = $this->createMock(IUserManager::class);
		$this->aliceUser = $this->createMock(IUser::class);
		$this->aliceUser->method('getUID')->willReturn('alice');
		$this->aliceUser->method('getEMailAddress')->willReturn('alice@example.org');
		$this->aliceEnabled = true;
		$this->aliceUser->method('isEnabled')->willReturnCallback(fn (): bool => $this->aliceEnabled);
		$this->emailUsers = [$this->aliceUser];
		$this->users->method('getByEmail')->willReturnCallback(fn (string $email): array => $email === 'alice@example.org' ? $this->emailUsers : []);
		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'alice' ? $this->aliceUser : null);
		$emailProperty = $this->createMock(IAccountProperty::class);
		$emailProperty->method('getValue')->willReturn('alice@example.org');
		$emailProperty->method('getVerified')->willReturnCallback(fn (): string => $this->emailVerification);
		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->with(IAccountManager::PROPERTY_EMAIL)->willReturn($emailProperty);
		$accounts = $this->createMock(IAccountManager::class);
		$accounts->method('getAccount')->with($this->aliceUser)->willReturn($account);
		$this->throttler = $this->createMock(IThrottler::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->sessions = new SessionService($config, $this->identities, $keys, $this->appPasswords, $this->request, $this->actors, $accounts, $this->users, $this->throttler, $time, new EnabledUserService($this->users));
	}

	public function testOnlyAPrivilegedAppPasswordReachesTheDirectMessages(): void {
		$plain = $this->appPasswords->create('alice', 'reader')['password'];
		$privileged = $this->appPasswords->create('alice', 'chatter', true);
		$this->assertTrue($privileged['privileged']);

		$signedIn = fn (string $password): ClientSession => $this->sessions->authenticate('Bearer ' . $this->sessions->create('alice.social.test', $password, '203.0.113.5')['accessJwt']);
		$this->assertFalse($signedIn($plain)->permissions()->mayCall('chat.bsky.convo.listConvos', 'did:web:api.bsky.chat#bsky_chat'));
		$this->assertTrue($signedIn($privileged['password'])->permissions()->mayCall('chat.bsky.convo.listConvos', 'did:web:api.bsky.chat#bsky_chat'));
		$this->assertTrue($signedIn($plain)->permissions()->mayCall('app.bsky.feed.getTimeline', 'did:web:api.bsky.app#bsky_appview'), 'the rest as before');
	}

	public function testEmailSignInFindsTheAccountWhenAnEarlierMatchHasNoBlueskyIdentity(): void {
		$other = $this->createMock(IUser::class);
		$other->method('getUID')->willReturn('bob');
		$this->emailUsers = [$other, $this->aliceUser];
		$password = $this->appPasswords->create('alice', 'phone')['password'];

		$session = $this->sessions->create('alice@example.org', $password, '203.0.113.5');

		$this->assertSame(self::DID, $session['did']);
		$this->assertSame('alice.social.test', $session['handle']);
	}

	public function testEmailSignInTrimsWhitespaceAndIgnoresCase(): void {
		$password = $this->appPasswords->create('alice', 'phone')['password'];

		$session = $this->sessions->create('  ALICE@EXAMPLE.ORG  ', $password, '203.0.113.5');

		$this->assertSame(self::DID, $session['did']);
		$this->assertSame('alice.social.test', $session['handle']);
	}

	public function testPdsAppPasswordMethodsExposeOnlyLexiconFieldsAndRevokeSessions(): void {
		$session = new ClientSession('alice', $this->identity, 'owner');
		try {
			$this->sessions->createAppPassword($session, 'chat-app', true);
			$this->fail('created a privileged password without chat permission');
		} catch (XrpcException $e) {
			$this->assertSame(403, $e->status);
			$this->assertSame('InsufficientScope', $e->error);
		}

		$created = $this->sessions->createAppPassword(new ClientSession('alice', $this->identity, 'owner', ['atproto', ClientSession::GENERIC, ClientSession::CHAT]), 'chat-app', true);
		$this->assertSame([
			'name' => 'chat-app',
			'password' => $created['password'],
			'createdAt' => '1970-01-01T00:00:01Z',
			'privileged' => true,
		], $created);
		$this->assertSame(['passwords' => [[
			'name' => 'chat-app',
			'createdAt' => '1970-01-01T00:00:01Z',
			'privileged' => true,
		]]], $this->sessions->listAppPasswords($session));

		$tokens = $this->sessions->create('alice.social.test', $created['password'], '203.0.113.5');
		$this->sessions->revokeAppPassword($session, 'chat-app');
		$this->assertSame(['passwords' => []], $this->sessions->listAppPasswords($session));
		try {
			$this->sessions->authenticate('Bearer ' . $tokens['accessJwt']);
			$this->fail('revoked app password session stayed active');
		} catch (XrpcException $e) {
			$this->assertSame('ExpiredToken', $e->error);
		}
	}

	public function testAnAppPasswordIsShownOnceAndStoredAsAHash(): void {
		$created = $this->appPasswords->create('alice', 'Bluesky on my phone');
		$this->assertMatchesRegularExpression('/^[a-z2-7]{4}(-[a-z2-7]{4}){3}$/', $created['password']);
		$stored = $this->request->passwords[$created['id']];
		$this->assertNotSame($created['password'], $stored['hash']);
		$this->assertTrue(password_verify($created['password'], $stored['hash']));
		$this->assertSame([['id' => $created['id'], 'name' => 'Bluesky on my phone', 'creation' => 1, 'last_used' => 0, 'privileged' => false]], $this->appPasswords->list('alice'), 'never the hash, never the password');
		$this->expectException(MoveErrorException::class);
		$this->appPasswords->create('alice', 'Bluesky on my phone');
	}

	/** Each refusal carries the code the settings page explains (§13.5). */
	public function testARefusedAppPasswordSaysWhyByCode(): void {
		$this->appPasswords->create('alice', 'phone');
		$codes = [];
		foreach (['', str_repeat('x', 65), 'phone'] as $name) {
			try {
				$this->appPasswords->create('alice', $name);
				$this->fail('made ' . $name);
			} catch (MoveErrorException $e) {
				$codes[] = $e->errorCode;
			}
		}
		$this->assertSame(['app-password-name-invalid', 'app-password-name-invalid', 'app-password-name-taken'], $codes);

		for ($i = 1; $i < AppPasswordService::MAX_PER_USER; $i++) {
			$this->appPasswords->create('alice', 'app ' . $i);
		}
		try {
			$this->appPasswords->create('alice', 'one more');
			$this->fail('made one past the limit');
		} catch (MoveErrorException $e) {
			$this->assertSame('app-password-limit', $e->errorCode);
		}
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

	public function testAnUnverifiedNextcloudEmailIsNotReportedAsConfirmed(): void {
		$this->emailVerification = IAccountManager::NOT_VERIFIED;
		$password = $this->appPasswords->create('alice', 'phone')['password'];
		$session = $this->sessions->create('alice.social.test', $password, '203.0.113.5');

		$this->assertSame('alice@example.org', $session['email']);
		$this->assertFalse($session['emailConfirmed']);
	}

	public function testAWrongPasswordCountsAgainstTheCallersAddress(): void {
		$this->appPasswords->create('alice', 'phone');
		$this->throttler->expects($this->exactly(4))->method('registerAttempt')->with('social_atproto_session', '203.0.113.5', []);
		foreach ([['alice.social.test', 'aaaa-bbbb-cccc-dddd'], ['nobody.social.test', 'aaaa-bbbb-cccc-dddd'], ['alice.social.test', 'the Nextcloud password'], ['alice@example.org', 'aaaa-bbbb-cccc-dddd']] as [$who, $password]) {
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

	public function testOnlyOneConcurrentRefreshCanConsumeASession(): void {
		$password = $this->appPasswords->create('alice', 'phone')['password'];
		$session = $this->sessions->create('alice.social.test', $password, '203.0.113.5');
		$this->request->consumeNextSessionConcurrently = true;

		$this->assertRefused(fn () => $this->sessions->refresh('Bearer ' . $session['refreshJwt']), 'ExpiredToken', 'another request consumed the refresh token first');
		$this->assertSame([], $this->request->sessions, 'no replacement is issued for a consumed token');
	}

	public function testRevokingAnAppPasswordInvalidatesAlreadyIssuedAccessTokens(): void {
		$created = $this->appPasswords->create('alice', 'phone');
		$session = $this->sessions->create('alice.social.test', $created['password'], '203.0.113.5');
		$this->request->removeAppPassword('alice', $created['id']);

		$this->assertRefused(fn () => $this->sessions->authenticate('Bearer ' . $session['accessJwt']), 'ExpiredToken');
		$this->assertSame([], $this->request->sessions, 'the revoked session is cleaned up');
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

	/**
	 * Switched off for Bluesky, the account takes no sign-in and no app
	 * signed in goes on, not even by refreshing; on again, the same app
	 * password signs in.
	 */
	public function testAnAccountSwitchedOffForBlueskyTakesNoAppUntilItIsOnAgain(): void {
		$password = $this->appPasswords->create('alice', 'phone')['password'];
		$session = $this->sessions->create('alice.social.test', $password, '203.0.113.5');
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_DEACTIVATED, '', 0);

		$this->assertRefused(fn () => $this->sessions->authenticate('Bearer ' . $session['accessJwt']), 'AccountDeactivated');
		$this->assertRefused(fn () => $this->sessions->refresh('Bearer ' . $session['refreshJwt']), 'AccountDeactivated');
		$this->assertRefused(fn () => $this->sessions->create('alice.social.test', $password, '203.0.113.5'), 'AccountDeactivated');

		$this->request->removeSessionsOfUser('alice');
		$this->assertSame([], $this->request->sessions, 'signed out');
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->assertTrue($this->sessions->create('alice.social.test', $password, '203.0.113.5')['active']);
	}

	/**
	 * A disabled Nextcloud account takes no sign-in, and no app signed in
	 * goes on; its sessions are kept, so enabling it again brings them back.
	 */
	public function testADisabledNextcloudAccountTakesNoAppUntilItIsEnabledAgain(): void {
		$password = $this->appPasswords->create('alice', 'phone')['password'];
		$session = $this->sessions->create('alice.social.test', $password, '203.0.113.5');
		$this->aliceEnabled = false;

		$this->assertRefused(fn () => $this->sessions->authenticate('Bearer ' . $session['accessJwt']), 'AccountDeactivated');
		$this->assertRefused(fn () => $this->sessions->refresh('Bearer ' . $session['refreshJwt']), 'AccountDeactivated');
		$this->assertRefused(fn () => $this->sessions->create('alice.social.test', $password, '203.0.113.5'), 'AuthenticationRequired');
		$this->assertRefused(fn () => $this->sessions->create('alice@example.org', $password, '203.0.113.5'), 'AuthenticationRequired');
		$this->assertCount(1, $this->request->sessions, 'the session is kept');

		$this->aliceEnabled = true;
		$this->assertNotNull($this->sessions->authenticate('Bearer ' . $session['accessJwt']));
		$this->assertNotNull($this->sessions->refresh('Bearer ' . $session['refreshJwt']));
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
