<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Service\ClientService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ClientServiceTest extends TestCase {
	private ClientRequest|MockObject $clientRequest;
	private ClientAuthRequest|MockObject $clientAuthRequest;
	private ClientService $service;

	protected function setUp(): void {
		$this->clientRequest = $this->createMock(ClientRequest::class);
		$this->clientAuthRequest = $this->createMock(ClientAuthRequest::class);
		$this->service = new ClientService(
			$this->clientRequest,
			new SecretHasher(),
			$this->clientAuthRequest
		);
	}

	private function registeredClient(): SocialClient {
		$client = new SocialClient();
		$client->setAppName('Tusky');
		$client->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob', 'https://app.example/callback']);
		$client->setAppScopes(['read', 'write']);
		$client->setAppClientSecret('s3cret');
		$client->setAuthCode('c0de');

		return $client;
	}

	public function testCreateAppGeneratesCredentialsAndSaves(): void {
		$client = $this->registeredClient();
		$this->clientRequest->expects($this->once())
			->method('saveApp')
			->with($this->identicalTo($client));

		$this->service->createApp($client);

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $client->getAppClientId());
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $client->getAppClientSecret());
		$this->assertNotSame($client->getAppClientId(), $client->getAppClientSecret());
	}

	public function testCreateAppRequiresAName(): void {
		$client = $this->registeredClient();
		$client->setAppName('');
		$this->clientRequest->expects($this->never())->method('saveApp');

		$this->expectException(ClientException::class);
		$this->expectExceptionMessage('missing client_name');
		$this->service->createApp($client);
	}

	public function testCreateAppRequiresRedirectUris(): void {
		$client = $this->registeredClient();
		$client->setAppRedirectUris([]);
		$this->clientRequest->expects($this->never())->method('saveApp');

		$this->expectException(ClientException::class);
		$this->expectExceptionMessage('missing redirect_uris');
		$this->service->createApp($client);
	}

	/**
	 * The app registration used to hold the authorization in its own row, so
	 * the second person to sign in with a client signed the first one out.
	 * What is written now is a row of that account's own.
	 */
	public function testAuthClientRecordsTheAuthorizationOfOneAccount(): void {
		$client = $this->registeredClient();
		$client->setId(7)->setAuthUserId('alice')->setAuthAccount('alice')
			->setAuthScopes(['read', 'write']);

		$recorded = [];
		$this->clientAuthRequest->expects($this->once())->method('authorize')
			->willReturnCallback(
				function (int $clientId, string $userId, string $account, array $scopes, string $code) use (&$recorded): void {
					$recorded = compact('clientId', 'userId', 'account', 'scopes', 'code');
				}
			);

		$this->service->authClient($client);

		$this->assertSame(7, $recorded['clientId']);
		$this->assertSame('alice', $recorded['userId']);
		$this->assertSame(['read', 'write'], $recorded['scopes']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{60}$/', $client->getAuthCode());
		$this->assertSame($client->getAuthCode(), $recorded['code']);
	}

	public function testExchangingACodeMintsATokenOnThatAuthorization(): void {
		$client = $this->registeredClient();
		$client->setId(7);
		$authorized = (new SocialClient())->setId(7)->setAuthUserId('alice');
		$authorized->setLastUpdate(time() - 10);
		$this->clientAuthRequest->method('getByCode')->with(7, 'the-code')->willReturn($authorized);

		$minted = '';
		$this->clientAuthRequest->expects($this->once())->method('exchange')
			->willReturnCallback(
				function (int $clientId, string $code, string $token) use (&$minted, $authorized): SocialClient {
					$minted = $token;

					return $authorized->setToken($token);
				}
			);

		$result = $this->service->exchangeCode($client, 'the-code');

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{80}$/', $minted);
		$this->assertSame('alice', $result->getAuthUserId());
	}

	public function testAuthClientRecordsThePkceChallengeWithTheAuthorization(): void {
		$client = $this->registeredClient();
		$client->setId(7)->setAuthUserId('alice')->setAuthAccount('alice')
			->setAuthCodeChallenge('the-challenge')->setAuthCodeChallengeMethod('S256');

		$recorded = [];
		$this->clientAuthRequest->expects($this->once())->method('authorize')
			->willReturnCallback(
				function (
					int $clientId, string $userId, string $account, array $scopes, string $code,
					string $challenge = '', string $method = '',
				) use (&$recorded): void {
					$recorded = [$challenge, $method];
				}
			);

		$this->service->authClient($client);

		$this->assertSame(['the-challenge', 'S256'], $recorded);
	}

	/**
	 * RFC 7636: a code bound to a challenge is worth nothing on its own, which
	 * is what protects a redirect to a custom scheme another application can
	 * claim.
	 */
	public function testACodeBoundToAChallengeNeedsTheVerifierThatMatchesIt(): void {
		$verifier = str_repeat('a', 43);
		$client = $this->registeredClient();
		$client->setId(7);
		$authorized = (new SocialClient())->setId(7)->setAuthUserId('alice')
			->setAuthCodeChallenge(ClientService::codeChallenge($verifier));
		$authorized->setLastUpdate(time() - 10);
		$this->clientAuthRequest->method('getByCode')->willReturn($authorized);
		$this->clientAuthRequest->method('exchange')
			->willReturnCallback(fn (): SocialClient => $authorized->setToken('tok'));

		$this->assertSame('tok', $this->service->exchangeCode($client, 'the-code', $verifier)->getToken());
	}

	/** @return array<string, array{string}> */
	public static function wrongVerifierProvider(): array {
		return [
			'none at all' => [''],
			'another verifier' => [str_repeat('b', 43)],
			// the challenge is the digest; presenting it is not knowing the
			// verifier it was made from
			'the challenge itself' => [ClientService::codeChallenge(str_repeat('a', 43))],
			'too short to be one' => ['short'],
		];
	}

	#[DataProvider('wrongVerifierProvider')]
	public function testACodeBoundToAChallengeIsRefusedWithoutIt(string $presented): void {
		$client = $this->registeredClient();
		$client->setId(7);
		$authorized = (new SocialClient())->setId(7)
			->setAuthCodeChallenge(ClientService::codeChallenge(str_repeat('a', 43)));
		$authorized->setLastUpdate(time() - 10);
		$this->clientAuthRequest->method('getByCode')->willReturn($authorized);
		$this->clientAuthRequest->expects($this->never())->method('exchange');

		$this->expectException(ClientException::class);
		$this->expectExceptionMessage('invalid code_verifier');
		$this->service->exchangeCode($client, 'the-code', $presented);
	}

	/** A verifier sent against an authorization that carries no challenge is ignored. */
	public function testAnAuthorizationWithoutAChallengeIgnoresAVerifier(): void {
		$client = $this->registeredClient();
		$client->setId(7);
		$authorized = (new SocialClient())->setId(7);
		$authorized->setLastUpdate(time() - 10);
		$this->clientAuthRequest->method('getByCode')->willReturn($authorized);
		$this->clientAuthRequest->expects($this->once())->method('exchange')
			->willReturnCallback(fn (): SocialClient => $authorized->setToken('tok'));

		$this->assertSame('tok', $this->service->exchangeCode($client, 'the-code', 'whatever')->getToken());
	}

	public function testTheS256ChallengeIsTheUnpaddedBase64UrlOfTheDigest(): void {
		// RFC 7636 appendix B
		$challenge = ClientService::codeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');

		$this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $challenge);
	}

	/** A code is short-lived, and an expired one is not exchangeable. */
	public function testExchangingAnExpiredCodeIsRefused(): void {
		$client = $this->registeredClient();
		$authorized = new SocialClient();
		$authorized->setLastUpdate(time() - ClientService::TIME_CODE_TTL - 1);
		$this->clientAuthRequest->method('getByCode')->willReturn($authorized);
		$this->clientAuthRequest->expects($this->never())->method('exchange');

		$this->expectException(ClientException::class);
		$this->expectExceptionMessage('code expired');

		$this->service->exchangeCode($client, 'stale');
	}

	public function testExchangingACodeNobodyGrantedIsRefused(): void {
		$this->clientAuthRequest->method('getByCode')
			->willThrowException(new ClientNotFoundException('unknown code'));

		$this->expectException(ClientNotFoundException::class);

		$this->service->exchangeCode($this->registeredClient(), 'nope');
	}

	public function testGetFromClientIdDelegates(): void {
		$client = $this->registeredClient();
		$this->clientRequest->expects($this->once())
			->method('getFromClientId')
			->with('client-id')
			->willReturn($client);

		$this->assertSame($client, $this->service->getFromClientId('client-id'));
	}

	public function testGetFromTokenDoesNotRewriteAFreshlyRefreshedToken(): void {
		$client = $this->registeredClient();
		$client->setLastUpdate(time() - 60);
		$this->clientAuthRequest->method('getByToken')->with('tok')->willReturn($client);
		$this->clientAuthRequest->expects($this->never())->method('touch');
		$this->clientAuthRequest->expects($this->never())->method('deprecate');

		$this->assertSame($client, $this->service->getFromToken('tok'));
	}

	public function testGetFromTokenRefreshesATokenInUse(): void {
		// last_update follows usage (at most one write per TIME_TOKEN_REFRESH), so an
		// actively used token never ages into the TTL. The old inverted comparison
		// only rewrote recently-written rows, so real usage never refreshed anything.
		$client = $this->registeredClient();
		$client->setLastUpdate(time() - ClientService::TIME_TOKEN_REFRESH - 60);
		$this->clientAuthRequest->method('getByToken')->willReturn($client);
		$client->setAuthId(11);
		$this->clientAuthRequest->expects($this->once())->method('touch')->with(11);

		$this->assertSame($client, $this->service->getFromToken('tok'));
	}

	public function testGetFromTokenRejectsAnExpiredTokenAndPurges(): void {
		$client = $this->registeredClient();
		$client->setLastUpdate(time() - ClientService::TIME_TOKEN_TTL - 1);
		$this->clientAuthRequest->method('getByToken')->willReturn($client);
		$this->clientAuthRequest->expects($this->once())->method('deprecate');

		$this->expectException(ClientNotFoundException::class);
		$this->service->getFromToken('tok');
	}

	public function testGetFromTokenStillRejectsWhenPurgingFails(): void {
		$client = $this->registeredClient();
		$client->setLastUpdate(0);
		$this->clientAuthRequest->method('getByToken')->willReturn($client);
		$this->clientAuthRequest->method('deprecate')->willThrowException(new Exception('db'));

		$this->expectException(ClientNotFoundException::class);
		$this->service->getFromToken('tok');
	}

	public function testGetFromTokenPropagatesUnknownToken(): void {
		$this->clientAuthRequest->method('getByToken')->willThrowException(new ClientNotFoundException());

		$this->expectException(ClientNotFoundException::class);
		$this->service->getFromToken('nope');
	}

	/** @return array<string, array{array}> */
	public static function validDataProvider(): array {
		return [
			'nothing to check' => [[]],
			'known redirect' => [['redirect_uri' => 'https://app.example/callback']],
			'right secret' => [['client_secret' => 's3cret']],
			'registered app scopes as array' => [['app_scopes' => ['read']]],
			'registered app scopes as string' => [['app_scopes' => 'read write']],
			// an app row carries no granted scopes, so a token call that names
			// one is not compared against it — the scopes a token really has
			// are the ones on the authorization its code names
			'a scope on the app row is not consulted' => [['auth_scopes' => ['read', 'write', 'follow']]],
			'everything at once' => [[
				'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
				'client_secret' => 's3cret',
				'app_scopes' => 'write',
			]],
		];
	}

	#[DataProvider('validDataProvider')]
	public function testConfirmDataAcceptsMatchingData(array $data): void {
		$this->service->confirmData($this->registeredClient(), $data);
		$this->addToAssertionCount(1);
	}

	/** @return array<string, array{array, string}> */
	public static function invalidDataProvider(): array {
		return [
			'unknown redirect' => [['redirect_uri' => 'https://evil.example/'], 'unknown redirect_uri'],
			'wrong secret' => [['client_secret' => 'nope'], 'wrong client_secret'],
			'more app scopes than registered' => [['app_scopes' => 'read write follow'], 'invalid scope'],
		];
	}

	#[DataProvider('invalidDataProvider')]
	public function testConfirmDataRejectsMismatches(array $data, string $message): void {
		$this->expectException(ClientException::class);
		$this->expectExceptionMessage($message);
		$this->service->confirmData($this->registeredClient(), $data);
	}

	public function testConfirmDataAcceptsSecretsStoredHashed(): void {
		$hasher = new SecretHasher();
		$client = $this->registeredClient();
		$client->setAppClientSecret($hasher->hash('s3cret'));
		$client->setAuthCode($hasher->hash('c0de'));
		$client->setLastUpdate(time() - 60);

		$this->service->confirmData($client, ['client_secret' => 's3cret']);
		$this->addToAssertionCount(1);
	}

	public function testConfirmDataRejectsTheStoredHashAsThePresentedSecret(): void {
		$hasher = new SecretHasher();
		$client = $this->registeredClient();
		$client->setAppClientSecret($hasher->hash('s3cret'));

		// a database leak must not hand out a working credential
		$this->expectException(ClientException::class);
		$this->service->confirmData($client, ['client_secret' => $hasher->hash('s3cret')]);
	}

	/**
	 * `code` is not among what confirmData checks any more: an app row no
	 * longer carries one, because it no longer carries one authorization. A
	 * code handed here is ignored rather than silently accepted as valid —
	 * exchangeCode() is what checks one, against the row it names.
	 */
	public function testConfirmDataNoLongerTakesACode(): void {
		$client = $this->registeredClient();
		$client->setLastUpdate(time() - ClientService::TIME_CODE_TTL - 60);

		$this->service->confirmData($client, ['code' => 'whatever']);
		$this->addToAssertionCount(1);
	}

	/**
	 * One authorization goes, not the app row: revoking on one device must not
	 * sign out everybody else who authorized the same client.
	 */
	public function testRevokeTokenTakesBackOneAuthorization(): void {
		$client = $this->registeredClient();
		$client->setId(7)->setAuthId(11);
		$this->clientAuthRequest->method('getByToken')->with('tok')->willReturn($client);
		$this->clientAuthRequest->expects($this->once())->method('revoke')->with(11);

		$this->service->revokeToken($client, 'tok');
	}

	public function testRevokeTokenRefusesAnotherClientsToken(): void {
		$owner = $this->registeredClient();
		$owner->setId(7);
		$caller = $this->registeredClient();
		$caller->setId(8);
		$this->clientAuthRequest->method('getByToken')->willReturn($owner);

		$this->expectException(ClientException::class);
		$this->service->revokeToken($caller, 'tok');
	}

	// taking one authorization back

	public function testRevokingOneOfTheAccountsOwnAuthorizationsTakesItBack(): void {
		$mine = new SocialClient();
		$mine->setAuthId(4)->setAppName('Tusky');
		$this->clientAuthRequest->method('getByUser')->with('alice')->willReturn([$mine]);
		$this->clientAuthRequest->expects($this->once())->method('revoke')->with(4);

		$this->assertTrue($this->service->revokeAuthorizationOf('alice', 4));
	}

	/**
	 * The id is a number a caller can count upwards, so the authorization is
	 * looked up among the account's own rather than deleted by the id it was
	 * handed: signing a stranger's phone out has to be impossible rather than
	 * unlikely.
	 */
	public function testRevokingAnAuthorizationThatIsNotTheAccountsDoesNothing(): void {
		$mine = new SocialClient();
		$mine->setAuthId(4);
		$this->clientAuthRequest->method('getByUser')->with('alice')->willReturn([$mine]);
		$this->clientAuthRequest->expects($this->never())->method('revoke');

		$this->assertFalse($this->service->revokeAuthorizationOf('alice', 5));
	}
}
