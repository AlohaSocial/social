<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ClientRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Migration\Version1000Date20261008000100;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\ClientService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The OAuth credential storage against the real social_client table: secrets,
 * codes and tokens land hashed, the token lookup resolves the plaintext a client
 * presents, a legacy plaintext row is refused until the hashing migration
 * converts it, a fresh authorization invalidates the previous token, and
 * revocation works. The unit suite mocks ClientRequest, so none of this SQL runs
 * there.
 */
class ClientCredentialStorageTest extends TestCase {
	private const APP_NAME = 'credstore-itest';

	private ClientRequest $clientRequest;
	private ClientService $clientService;
	private IDBConnection $connection;
	private string $clientId = '';

	protected function setUp(): void {
		parent::setUp();
		$this->clientRequest = Server::get(ClientRequest::class);
		$this->clientService = Server::get(ClientService::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id')
			->from(CoreRequestBuilder::TABLE_CLIENT)
			->where($qb->expr()->eq('app_name', $qb->createNamedParameter(self::APP_NAME)));
		$cursor = $qb->executeQuery();
		$ids = array_column($cursor->fetchAll(), 'id');
		$cursor->closeCursor();

		foreach ($ids as $id) {
			$auth = $this->connection->getQueryBuilder();
			$auth->delete(CoreRequestBuilder::TABLE_CLIENT_AUTH)
				->where($auth->expr()->eq('client_id', $auth->createNamedParameter($id)));
			$auth->executeStatement();
		}

		$delete = $this->connection->getQueryBuilder();
		$delete->delete(CoreRequestBuilder::TABLE_CLIENT)
			->where($delete->expr()->eq('app_name', $delete->createNamedParameter(self::APP_NAME)));
		$delete->executeStatement();
	}

	/** The authorization row of one account against our client. */
	private function authRow(string $userId): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('a.id', 'a.code', 'a.token')
			->from(CoreRequestBuilder::TABLE_CLIENT_AUTH, 'a')
			->innerJoin('a', CoreRequestBuilder::TABLE_CLIENT, 'cl', $qb->expr()->eq('a.client_id', 'cl.id'))
			->where($qb->expr()->eq('cl.app_name', $qb->createNamedParameter(self::APP_NAME)))
			->andWhere($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$this->assertNotFalse($row, 'authorization row exists for ' . $userId);

		return $row;
	}

	/** Authorizes our client for one account and exchanges the code. */
	private function signIn(string $userId): SocialClient {
		$client = $this->clientRequest->getFromClientId($this->clientId);
		$client->setAuthScopes(['read'])->setAuthAccount($userId)->setAuthUserId($userId);
		$this->clientService->authClient($client);

		return $this->clientService->exchangeCode(
			$this->clientRequest->getFromClientId($this->clientId), $client->getAuthCode()
		);
	}

	private function registeredClient(): SocialClient {
		$client = new SocialClient();
		$client->setAppName(self::APP_NAME);
		$client->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob']);
		$client->setAppScopes(['read', 'write']);
		$this->clientService->createApp($client);
		$this->clientId = $client->getAppClientId();

		return $client;
	}

	/** @return array<string, string> the raw row for our client */
	private function rawRow(): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('app_client_secret', 'auth_code', 'token')
			->from(CoreRequestBuilder::TABLE_CLIENT)
			->where($qb->expr()->eq('app_name', $qb->createNamedParameter(self::APP_NAME)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$this->assertNotFalse($row, 'client row exists');

		return $row;
	}

	private function setRaw(string $column, string $value): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_CLIENT)
			->set($column, $qb->createNamedParameter($value))
			->where($qb->expr()->eq('app_name', $qb->createNamedParameter(self::APP_NAME)));
		$qb->executeStatement();
	}

	/** Devolves one column of an authorization row to the pre-hashing format. */
	private function setRawAuth(string $userId, string $column, string $value): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_CLIENT_AUTH)
			->set($column, $qb->createNamedParameter($value))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($this->authRow($userId)['id'])));
		$qb->executeStatement();
	}

	public function testTheFullAuthorizationFlowStoresOnlyDigests(): void {
		$client = $this->registeredClient();
		$this->assertStringStartsWith('sha256:', $this->rawRow()['app_client_secret']);
		$this->assertStringNotContainsString($client->getAppClientSecret(), $this->rawRow()['app_client_secret']);

		$client->setAuthScopes(['read'])->setAuthAccount('alice')->setAuthUserId('alice');
		$this->clientService->authClient($client);
		$this->assertStringStartsWith('sha256:', $this->authRow('alice')['code']);

		$granted = $this->clientService->exchangeCode(
			$this->clientRequest->getFromClientId($this->clientId), $client->getAuthCode()
		);
		$row = $this->authRow('alice');
		$this->assertStringStartsWith('sha256:', $row['token']);
		$this->assertSame('', $row['code'], 'the code is single-use');

		// the plaintext token the client holds resolves through the hashed lookup
		$resolved = $this->clientService->getFromToken($granted->getToken());
		$this->assertSame('alice', $resolved->getAuthUserId());
	}

	/**
	 * The same person authorizing again is saying "start again". A *different*
	 * person authorizing is not, which is what the separate table is for —
	 * that case is `ClientAuthMultiUserTest`.
	 */
	public function testAFreshAuthorizationByTheSameAccountInvalidatesItsPreviousToken(): void {
		$this->registeredClient();
		$first = $this->signIn('alice')->getToken();

		$this->signIn('alice');

		$this->expectException(ClientNotFoundException::class);
		$this->clientService->getFromToken($first);
	}

	public function testRevokeTokenEndsTheSession(): void {
		$this->registeredClient();
		$granted = $this->signIn('alice');

		$this->clientService->revokeToken($granted, $granted->getToken());

		$this->expectException(ClientNotFoundException::class);
		$this->clientService->getFromToken($granted->getToken());
	}

	/**
	 * A row still holding the bare token is one the hashing migration missed:
	 * it is not a credential, however the token is presented.
	 */
	public function testALegacyPlaintextTokenNoLongerResolves(): void {
		$this->registeredClient();
		$granted = $this->signIn('legacy-user');

		// devolve the row to the pre-hashing format
		$this->setRawAuth('legacy-user', 'token', $granted->getToken());

		$this->expectException(ClientNotFoundException::class);
		$this->clientService->getFromToken($granted->getToken());
	}

	/** The migration hashes such a row, and the token then works again. */
	public function testTheMigrationHashesWhatWasLeftInPlaintext(): void {
		$this->registeredClient();
		$granted = $this->signIn('legacy-user');
		$this->setRawAuth('legacy-user', 'token', $granted->getToken());

		Server::get(Version1000Date20261008000100::class)
			->postSchemaChange($this->createStub(IOutput::class), fn () => null, []);

		$this->assertStringStartsWith('sha256:', $this->authRow('legacy-user')['token']);
		$this->assertSame('legacy-user', $this->clientService->getFromToken($granted->getToken())->getAuthUserId());
	}

	public function testGetFromClientIdRoundTrip(): void {
		$client = $this->registeredClient();

		$read = $this->clientRequest->getFromClientId($client->getAppClientId());

		$this->assertSame(self::APP_NAME, $read->getAppName());
		$this->assertSame(['urn:ietf:wg:oauth:2.0:oob'], $read->getAppRedirectUris());
		$this->assertSame(['read', 'write'], $read->getAppScopes());
	}
}
