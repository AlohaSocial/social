<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261008000100;
use OCA\Social\Security\SecretHasher;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The migration that leaves no OAuth credential in plaintext: what it asks the
 * database for, what it writes, and what it does with a row it cannot hash —
 * which, now that no lookup accepts a bare value, is to take it back.
 */
class HashLegacyClientSecretsTest extends TestCase {
	private SecretHasher $secretHasher;
	private IOutput|Stub $output;
	/** @var string[] */
	private array $warnings = [];

	protected function setUp(): void {
		parent::setUp();
		$this->secretHasher = new SecretHasher();
		$this->output = $this->createStub(IOutput::class);
		$this->warnings = [];
		$this->output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
	}

	private function migrate(FakeConnection $connection): void {
		(new Version1000Date20261008000100($connection, $this->secretHasher))
			->postSchemaChange($this->output, fn () => null, []);
	}

	public function testTheDatabasePicksOutThePlaintextRows(): void {
		$connection = new FakeConnection([[], []]);

		$this->migrate($connection);

		$this->assertCount(2, $connection->queries, 'one select a table, both empty');
		$this->assertSame(
			["((app_client_secret <> '' AND app_client_secret NOT LIKE sha256:%)"
				. " OR (auth_code <> '' AND auth_code NOT LIKE sha256:%)"
				. " OR (token <> '' AND token NOT LIKE sha256:%))"],
			$connection->queries[0]->wheres
		);
		$this->assertSame(
			["((code <> '' AND code NOT LIKE sha256:%)"
				. " OR (token <> '' AND token NOT LIKE sha256:%))"],
			$connection->queries[1]->wheres
		);
	}

	public function testEveryPlaintextColumnOfARowIsHashedInOneWrite(): void {
		$connection = new FakeConnection([[], [[
			'id' => 7,
			'code' => $this->secretHasher->hash('already'),
			'token' => 'token',
		]]]);

		$this->migrate($connection);

		$writes = $connection->writes();
		$this->assertCount(1, $writes);
		$this->assertSame('social_client_auth', $writes[0]->table);
		$this->assertSame(['token' => $this->secretHasher->hash('token')], $writes[0]->sets);
		$this->assertSame(['id = 7'], $writes[0]->wheres);
	}

	/**
	 * Left in plaintext, a token would be a credential nobody can use and a
	 * secret anybody with a backup can read; deleting it signs one app out.
	 */
	public function testAnAuthorizationThatCannotBeHashedIsTakenBack(): void {
		$connection = new FakeConnection(
			[[], [['id' => 7, 'code' => '', 'token' => 'plain']]],
			static function (FakeQueryBuilder $query): void {
				if (!$query->deletes) {
					throw new RuntimeException('the row is locked');
				}
			}
		);

		$this->migrate($connection);

		$writes = $connection->writes();
		$this->assertCount(2, $writes);
		$this->assertTrue($writes[1]->deletes);
		$this->assertSame('social_client_auth', $writes[1]->table);
		$this->assertSame(['id = 7'], $writes[1]->wheres);
		$this->assertStringContainsString('the row is locked', $this->warnings[0]);
	}

	/** An app registration goes with the authorizations that name it. */
	public function testARegistrationThatCannotBeHashedTakesItsAuthorizationsWithIt(): void {
		$connection = new FakeConnection(
			[[['id' => 3, 'app_client_secret' => 'plain', 'auth_code' => '', 'token' => '']], []],
			static function (FakeQueryBuilder $query): void {
				if (!$query->deletes) {
					throw new RuntimeException('no');
				}
			}
		);

		$this->migrate($connection);

		$deletes = array_values(array_filter($connection->writes(), static fn (FakeQueryBuilder $q): bool => $q->deletes));
		$this->assertCount(2, $deletes);
		$this->assertSame(['social_client_auth', ['client_id = 3']], [$deletes[0]->table, $deletes[0]->wheres]);
		$this->assertSame(['social_client', ['id = 3']], [$deletes[1]->table, $deletes[1]->wheres]);
	}

	public function testARowThatCannotBeDeletedEitherDoesNotEndTheUpgrade(): void {
		$connection = new FakeConnection(
			[[], [['id' => 7, 'code' => '', 'token' => 'plain'], ['id' => 8, 'code' => '', 'token' => 'fine']]],
			static function (FakeQueryBuilder $query): void {
				if ($query->wheres === ['id = 7']) {
					throw new RuntimeException('gone');
				}
			}
		);

		$this->migrate($connection);

		$last = $connection->writes()[array_key_last($connection->writes())];
		$this->assertSame(['token' => $this->secretHasher->hash('fine')], $last->sets, 'the next row is still hashed');
	}
}
