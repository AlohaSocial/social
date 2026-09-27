<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Exception;
use OCA\Social\Migration\EncryptPrivateKeys;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Service\ConfigService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The repair step that rewrites bare-PEM actor private keys into their sealed
 * form, and what it does with a row it cannot seal.
 */
#[AllowMockObjectsWithoutExpectations]
class EncryptPrivateKeysTest extends TestCase {
	private PrivateKeyCipher|Stub $keyCipher;
	private ConfigService|Stub $configService;
	private IOutput|MockObject $output;
	/** @var string[] */
	private array $warnings = [];

	protected function setUp(): void {
		parent::setUp();
		$this->keyCipher = $this->createStub(PrivateKeyCipher::class);
		// the marker short-circuits the step, so it has to read as unset here
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValueInt')->willReturn(0);
		$this->output = $this->createMock(IOutput::class);
		$this->warnings = [];
		$this->output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
	}

	public function testAKeyThatCannotBeSealedDoesNotEndTheUpgrade(): void {
		// seal() throws when the instance secret is missing or unusable, and
		// the upgrade then fails with a raw exception and leaves the instance
		// in maintenance mode over one row
		$connection = new FakeConnection([[
			['id' => 'https://cloud.example/users/broken', 'private_key' => '-----BEGIN BROKEN'],
			['id' => 'https://cloud.example/users/fine', 'private_key' => '-----BEGIN FINE'],
		]]);
		$this->keyCipher->method('seal')->willReturnCallback(
			static function (string $key): string {
				if (str_contains($key, 'BROKEN')) {
					throw new Exception('the instance secret is not readable');
				}

				return 'sealed:' . $key;
			}
		);

		(new EncryptPrivateKeys($connection, $this->keyCipher, $this->configService))->run($this->output);

		$writes = $connection->writes();
		$this->assertCount(1, $writes, 'the row that could be sealed is still written');
		$this->assertSame(['private_key' => 'sealed:-----BEGIN FINE'], $writes[0]->sets);
		$this->assertSame(['id = https://cloud.example/users/fine'], $writes[0]->wheres);

		$this->assertCount(2, $this->warnings);
		$this->assertStringContainsString('users/broken', $this->warnings[0]);
		$this->assertStringContainsString('the instance secret is not readable', $this->warnings[0]);
		$this->assertStringContainsString('users/broken', $this->warnings[1]);
	}

	public function testAnInstanceWithNoPlaintextKeyLeftWritesNothing(): void {
		$connection = new FakeConnection([[]]);
		$this->output->expects($this->never())->method('startProgress');

		(new EncryptPrivateKeys($connection, $this->keyCipher, $this->configService))->run($this->output);

		$this->assertSame([], $connection->writes());
		$this->assertCount(1, $connection->queries, 'one select that comes back empty');
	}

	public function testOnlyBarePemKeysAreSelected(): void {
		$connection = new FakeConnection([[]]);

		(new EncryptPrivateKeys($connection, $this->keyCipher, $this->configService))->run($this->output);

		$this->assertSame(
			['private_key LIKE -----BEGIN%'],
			$connection->queries[0]->wheres
		);
	}

	public function testTheMarkerStopsTheScanOnLaterUpgrades(): void {
		// the step used to LIKE-scan social_actor on every `occ upgrade` for the
		// life of the instance, with the instance in maintenance mode, only to
		// find nothing to do. Once a run finishes clean, it must not look again.
		$connection = new FakeConnection([[]]);
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getAppValueInt')->willReturn(1);

		(new EncryptPrivateKeys($connection, $this->keyCipher, $configService))->run($this->output);

		$this->assertSame([], $connection->queries, 'the marker did not stop the scan');
	}

	public function testACleanRunSetsTheMarker(): void {
		$connection = new FakeConnection([[]]);
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getAppValueInt')->willReturn(0);
		$configService->expects($this->once())
			->method('setAppValue')
			->with('migration_actor_keys_encrypted', '1');

		(new EncryptPrivateKeys($connection, $this->keyCipher, $configService))->run($this->output);
	}

}
