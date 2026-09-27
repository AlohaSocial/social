<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\HashClientSecrets;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Service\ConfigService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The repair step that hashes OAuth client secrets, authorization codes and
 * access tokens stored in plaintext — what it asks the database for, and what
 * it does with a row it cannot write.
 */
class HashClientSecretsTest extends TestCase {
	private SecretHasher $secretHasher;
	private ConfigService|Stub $configService;
	private IOutput|Stub $output;
	/** @var string[] */
	private array $warnings = [];

	protected function setUp(): void {
		parent::setUp();
		// the real one: the step asks it what a hashed value looks like, to
		// have the database pick out the rows that are not hashed yet
		$this->secretHasher = new SecretHasher();
		// the marker short-circuits the step, so it has to read as unset here
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValueInt')->willReturn(0);
		$this->output = $this->createStub(IOutput::class);
		$this->warnings = [];
		$this->output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
	}

	public function testTheDatabasePicksOutThePlaintextRows(): void {
		// this runs on every upgrade, and on all but the first there is nothing
		// left to convert — which must not cost the whole table hydrated here
		$connection = new FakeConnection([[], []]);

		(new HashClientSecrets($connection, $this->secretHasher, $this->configService))->run($this->output);

		// one select a table: the app registration keeps its own secret, and
		// the code and token live with the authorization
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
			$connection->queries[1]->wheres,
			'a legacy token carried into social_client_auth is still plaintext'
		);
	}

	public function testARowThatCannotBeWrittenDoesNotEndTheUpgrade(): void {
		$connection = new FakeConnection(
			[[
				['id' => 'client-broken', 'app_client_secret' => 'plain-a', 'auth_code' => '', 'token' => ''],
				['id' => 'client-fine', 'app_client_secret' => '', 'auth_code' => '', 'token' => 'plain-b'],
			]],
			static function (FakeQueryBuilder $query): void {
				if ($query->wheres === ['id = client-broken']) {
					throw new RuntimeException('the row is gone');
				}
			}
		);

		(new HashClientSecrets($connection, $this->secretHasher, $this->configService))->run($this->output);

		$writes = $connection->writes();
		$this->assertCount(2, $writes);
		$this->assertSame(
			['token' => $this->secretHasher->hash('plain-b')],
			$writes[1]->sets,
			'the row after the failing one is still hashed'
		);

		$this->assertCount(2, $this->warnings);
		$this->assertStringContainsString('client-broken', $this->warnings[0]);
		$this->assertStringContainsString('the row is gone', $this->warnings[0]);
		$this->assertStringContainsString('client-broken', $this->warnings[1]);
	}

	public function testEveryPlaintextColumnOfARowIsHashedInOneWrite(): void {
		$connection = new FakeConnection([[[
			'id' => 'client-a',
			'app_client_secret' => 'secret',
			'auth_code' => $this->secretHasher->hash('already'),
			'token' => 'token',
		]]]);

		(new HashClientSecrets($connection, $this->secretHasher, $this->configService))->run($this->output);

		$writes = $connection->writes();
		$this->assertCount(1, $writes);
		$this->assertSame(
			[
				'app_client_secret' => $this->secretHasher->hash('secret'),
				'token' => $this->secretHasher->hash('token'),
			],
			$writes[0]->sets,
			'a column that is already hashed is left out of the update'
		);
	}

	public function testTheMarkerStopsTheScanOnLaterUpgrades(): void {
		// coming back empty still meant running the query, on every upgrade,
		// forever. Once a run finishes clean, it must not look again.
		$connection = new FakeConnection([[]]);
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getAppValueInt')->willReturn(1);

		(new HashClientSecrets($connection, $this->secretHasher, $configService))->run($this->output);

		$this->assertSame([], $connection->queries, 'the marker did not stop the scan');
	}

	public function testACleanRunSetsTheMarker(): void {
		$connection = new FakeConnection([[]]);
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getAppValueInt')->willReturn(0);
		$configService->expects($this->once())
			->method('setAppValue')
			->with('migration_client_secrets_hashed', '1');

		(new HashClientSecrets($connection, $this->secretHasher, $configService))->run($this->output);
	}

}
