<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Two people, one registered app — against the real database.
 *
 * This is the whole reason the authorization moved out of `social_client`.
 * That row held one `auth_user_id` and one `token`, so the second person to
 * sign in with Elk or Phanpy — which register one app per instance — signed
 * the first one out, and there was no way to tell from the API that it had
 * happened.
 *
 * Every assertion here is a property of the SQL rather than of the service: a
 * unique index on the wrong pair, or a delete that takes one column too many,
 * would leave the service looking right.
 */
class ClientAuthMultiUserTest extends TestCase {
	private const APP = 'multi-user-test';

	private ClientRequest $clientRequest;
	private ClientAuthRequest $clientAuthRequest;
	private int $clientId = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->clientRequest = Server::get(ClientRequest::class);
		$this->clientAuthRequest = Server::get(ClientAuthRequest::class);
		$this->cleanup();

		$client = new SocialClient();
		$client->setAppName(self::APP)
			->setAppWebsite('https://app.example')
			->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob'])
			->setAppScopes(['read', 'write'])
			->setAppClientId(self::APP . '-id')
			->setAppClientSecret(self::APP . '-secret');
		$this->clientRequest->saveApp($client);

		$this->clientId = $this->clientRequest->getFromClientId(self::APP . '-id')->getId();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (['alice', 'bob'] as $userId) {
			$this->clientAuthRequest->deleteRelatedId($userId);
		}

		$this->clientRequest->deleteApp(self::APP . '-id');
		$this->clientRequest->deleteApp(self::APP . '-unused-id');
	}

	/** Authorizes, exchanges, and hands back the token. */
	private function signIn(string $userId, array $scopes = ['read']): string {
		$code = $userId . '-code-' . bin2hex(random_bytes(4));
		$this->clientAuthRequest->authorize($this->clientId, $userId, $userId, $scopes, $code);

		$token = $userId . '-token-' . bin2hex(random_bytes(8));
		$this->clientAuthRequest->exchange($this->clientId, $code, $token);

		return $token;
	}

	/**
	 * The bug this table exists for: user B signing in signed user A out.
	 */
	public function testASecondAccountSigningInLeavesTheFirstSignedIn(): void {
		$alice = $this->signIn('alice');
		$bob = $this->signIn('bob');

		$this->assertSame('alice', $this->clientAuthRequest->getByToken($alice)->getAuthUserId());
		$this->assertSame('bob', $this->clientAuthRequest->getByToken($bob)->getAuthUserId());
	}

	/** And each token carries its own account's scopes, not the other's. */
	public function testEachTokenCarriesTheScopesItsOwnerGranted(): void {
		$alice = $this->signIn('alice', ['read']);
		$bob = $this->signIn('bob', ['read', 'write']);

		$this->assertSame(['read'], $this->clientAuthRequest->getByToken($alice)->getAuthScopes());
		$this->assertSame(['read', 'write'], $this->clientAuthRequest->getByToken($bob)->getAuthScopes());
	}

	/** The app registration is the same one for both. */
	public function testBothAuthorizationsNameTheSameApp(): void {
		$alice = $this->clientAuthRequest->getByToken($this->signIn('alice'));
		$bob = $this->clientAuthRequest->getByToken($this->signIn('bob'));

		$this->assertSame($this->clientId, $alice->getId());
		$this->assertSame($this->clientId, $bob->getId());
		$this->assertSame(self::APP, $alice->getAppName());
	}

	/**
	 * Re-authorizing is the same person saying "start again": their old token
	 * goes, and nobody else's.
	 */
	public function testReAuthorizingReplacesOnlyThatAccountsToken(): void {
		$firstAlice = $this->signIn('alice');
		$bob = $this->signIn('bob');

		$secondAlice = $this->signIn('alice');

		$this->assertSame('alice', $this->clientAuthRequest->getByToken($secondAlice)->getAuthUserId());
		$this->assertSame('bob', $this->clientAuthRequest->getByToken($bob)->getAuthUserId());

		$this->expectException(ClientNotFoundException::class);
		$this->clientAuthRequest->getByToken($firstAlice);
	}

	/** Revoking on one device does not sign anybody else out. */
	public function testRevokingOneAuthorizationLeavesTheOther(): void {
		$alice = $this->signIn('alice');
		$bob = $this->signIn('bob');

		$this->clientAuthRequest->revoke(
			$this->clientAuthRequest->getByToken($alice)->getAuthId()
		);

		$this->assertSame('bob', $this->clientAuthRequest->getByToken($bob)->getAuthUserId());

		$this->expectException(ClientNotFoundException::class);
		$this->clientAuthRequest->getByToken($alice);
	}

	/**
	 * A code is spent when it is exchanged, in the same statement that writes
	 * the token — so the same code cannot be exchanged twice even if two
	 * requests arrive together.
	 */
	public function testACodeCannotBeExchangedTwice(): void {
		$code = 'alice-code-once';
		$this->clientAuthRequest->authorize($this->clientId, 'alice', 'alice', ['read'], $code);
		$this->clientAuthRequest->exchange($this->clientId, $code, 'first-token');

		$this->expectException(ClientNotFoundException::class);
		$this->clientAuthRequest->exchange($this->clientId, $code, 'second-token');
	}

	/** A code granted against one app cannot be exchanged by another. */
	public function testACodeIsBoundToTheAppItWasGrantedAgainst(): void {
		$code = 'alice-code-bound';
		$this->clientAuthRequest->authorize($this->clientId, 'alice', 'alice', ['read'], $code);

		$this->expectException(ClientNotFoundException::class);
		$this->clientAuthRequest->exchange($this->clientId + 10_000, $code, 'stolen');
	}

	public function testAnUnknownTokenNamesNobody(): void {
		$this->expectException(ClientNotFoundException::class);
		$this->clientAuthRequest->getByToken('nothing-like-this');
	}

	/** An authorization that has not been exchanged has no token to find. */
	public function testAnUnexchangedAuthorizationIsNotReachableByToken(): void {
		$this->clientAuthRequest->authorize($this->clientId, 'alice', 'alice', ['read'], 'pending');

		$this->expectException(ClientNotFoundException::class);
		$this->clientAuthRequest->getByToken('');
	}

	public function testWhatAnAccountHasAuthorizedIsItsOwn(): void {
		$this->signIn('alice');
		$this->signIn('bob');

		$this->assertCount(1, $this->clientAuthRequest->getByUser('alice'));
		$this->assertSame('alice', $this->clientAuthRequest->getByUser('alice')[0]->getAuthUserId());
	}

	/** Deleting an account takes its authorizations and nobody else's. */
	public function testDeletingAnAccountTakesItsAuthorizations(): void {
		$this->signIn('alice');
		$bob = $this->signIn('bob');

		$this->clientAuthRequest->deleteRelatedId('alice');

		$this->assertSame([], $this->clientAuthRequest->getByUser('alice'));
		$this->assertSame('bob', $this->clientAuthRequest->getByToken($bob)->getAuthUserId());
	}

	/**
	 * The sweep behind the public registration endpoint takes a registration
	 * nobody signed in with and leaves one somebody did — even after that
	 * somebody's authorization is gone again.
	 */
	public function testTheSweepTakesOnlyRegistrationsNobodyEverAuthorized(): void {
		$unused = new SocialClient();
		$unused->setAppName(self::APP)
			->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob'])
			->setAppScopes(['read'])
			->setAppClientId(self::APP . '-unused-id')
			->setAppClientSecret(self::APP . '-unused-secret');
		$this->clientRequest->saveApp($unused);

		$this->signIn('alice');
		$this->clientAuthRequest->deleteRelatedId('alice');

		// a cutoff in the future: both registrations are old enough
		$this->clientRequest->deleteNeverAuthorized(time() + 60);

		$this->assertSame($this->clientId, $this->clientRequest->getFromClientId(self::APP . '-id')->getId());

		$this->expectException(ClientNotFoundException::class);
		$this->clientRequest->getFromClientId(self::APP . '-unused-id');
	}
}
