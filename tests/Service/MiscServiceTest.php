<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\MiscService;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MiscServiceTest extends TestCase {
	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testLogTagsTheMessageWithAppAndLevel(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('log')
			->with(3, 'something happened', ['app' => 'social', 'level' => 3]);

		$service = new MiscService($logger);
		$service->log('something happened', 3);
	}

	public function testLogDefaultsToLevelTwo(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('log')
			->with(2, 'default level', ['app' => 'social', 'level' => 2]);

		$service = new MiscService($logger);
		$service->log('default level');
	}

	public function testGetNcVersionReturnsTheMajorVersion(): void {
		// A real subclass rather than a double: `ServerVersion` is `readonly`,
		// and PHPUnit 11 — which this branch moves to — refuses to double a
		// readonly class where 9 allowed it. A readonly class may still be
		// extended by a readonly one. The parent constructor is not called: it
		// reads the server's own `version.php`, which a unit run has no copy of.
		$version = new readonly class extends ServerVersion {
			public function __construct() {
			}

			#[\Override]
			public function getVersion(): array {
				return [31, 0, 2];
			}
		};
		\OC::$server->register(ServerVersion::class, $version);

		$service = new MiscService($this->createMock(LoggerInterface::class));

		$this->assertSame(31, $service->getNcVersion());
	}
}
