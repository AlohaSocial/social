<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\EncryptPrivateKeys;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\SignatureService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Proves the private-key encryption at rest end to end against the real database:
 * a created actor's key is stored sealed (never as PEM), reads decrypt it back to
 * a working key, a legacy plaintext row stays readable, and the EncryptPrivateKeys
 * repair step converts such a row exactly once. The unit suite covers the cipher
 * in isolation; only this proves the ActorsRequest read/write boundary actually
 * applies it.
 */
class ActorsKeyStorageTest extends TestCase {
	private const USERNAME = 'keystore-itest';

	private ActorsRequest $actorsRequest;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->actorsRequest = Server::get(ActorsRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->actorsRequest->delete(self::USERNAME);
	}

	private function createActor(): Person {
		$actor = new Person();
		$actor->setPreferredUsername(self::USERNAME);
		$actor->setUserId(self::USERNAME);
		Server::get(SignatureService::class)->generateKeys($actor);
		$this->actorsRequest->create($actor);

		return $actor;
	}

	private function rawPrivateKey(): string {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('private_key')->from(CoreRequestBuilder::TABLE_ACTORS)
			->where($qb->expr()->eq('preferred_username', $qb->createNamedParameter(self::USERNAME)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$this->assertNotFalse($row, 'actor row exists');

		return (string)$row['private_key'];
	}

	private function setRawPrivateKey(string $value): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_ACTORS)
			->set('private_key', $qb->createNamedParameter($value))
			->where($qb->expr()->eq('preferred_username', $qb->createNamedParameter(self::USERNAME)));
		$qb->executeStatement();
	}

	public function testAFreshActorsKeyIsStoredSealedAndReadsBackAsPem(): void {
		$actor = $this->createActor();
		$this->assertStringStartsWith('-----BEGIN', $actor->getPrivateKey(), 'in memory: PEM');

		$stored = $this->rawPrivateKey();
		$this->assertNotSame('', $stored);
		$this->assertStringNotContainsString('-----BEGIN', $stored, 'at rest: never the PEM');

		$read = $this->actorsRequest->getFromUsername(self::USERNAME);
		$this->assertSame($actor->getPrivateKey(), $read->getPrivateKey(), 'reads decrypt to the original key');
		$this->assertNotFalse(openssl_pkey_get_private($read->getPrivateKey()), 'and it is a usable key');
	}

	public function testALegacyPlaintextRowStaysReadable(): void {
		$actor = $this->createActor();
		$pem = $actor->getPrivateKey();
		$this->setRawPrivateKey($pem);

		$read = $this->actorsRequest->getFromUsername(self::USERNAME);
		$this->assertSame($pem, $read->getPrivateKey());
	}

	public function testTheRepairStepSealsLegacyRowsAndIsIdempotent(): void {
		$actor = $this->createActor();
		$pem = $actor->getPrivateKey();
		$this->setRawPrivateKey($pem);

		$repair = Server::get(EncryptPrivateKeys::class);
		// the step is one-shot and its marker is set on any instance that has
		// upgraded, so clearing it is what makes this a test of the repair
		// rather than of the marker
		$config = Server::get(ConfigService::class);
		$config->setAppValue('migration_actor_keys_encrypted', '0');
		$repair->run($this->createStub(IOutput::class));

		$sealedOnce = $this->rawPrivateKey();
		$this->assertStringNotContainsString('-----BEGIN', $sealedOnce, 'the repair sealed the row');
		$this->assertSame($pem, $this->actorsRequest->getFromUsername(self::USERNAME)->getPrivateKey());

		// a second run must not double-encrypt
		$config->setAppValue('migration_actor_keys_encrypted', '0');
		$repair->run($this->createStub(IOutput::class));
		$this->assertSame($pem, $this->actorsRequest->getFromUsername(self::USERNAME)->getPrivateKey());
	}

	public function testRefreshKeysSealsTheNewKeyToo(): void {
		$actor = $this->createActor();
		$before = $actor->getPrivateKey();

		Server::get(SignatureService::class)->generateKeys($actor);
		$this->actorsRequest->refreshKeys($actor);

		$this->assertStringNotContainsString('-----BEGIN', $this->rawPrivateKey());
		$read = $this->actorsRequest->getFromUsername(self::USERNAME);
		$this->assertSame($actor->getPrivateKey(), $read->getPrivateKey());
		$this->assertNotSame($before, $read->getPrivateKey(), 'the pair actually rotated');
	}
}
