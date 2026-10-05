<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use OCP\IURLGenerator;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TestContainerTest extends TestCase {
	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testStaticServerFacadeResolvesRegisteredService(): void {
		$logger = $this->createStub(LoggerInterface::class);
		\OC::$server->register(LoggerInterface::class, $logger);

		$this->assertSame($logger, Server::get(LoggerInterface::class));
	}

	public function testUnknownServiceIsANotFoundError(): void {
		$this->expectException(NotFoundExceptionInterface::class);
		$this->expectExceptionMessage(\OCP\IConfig::class);

		Server::get(\OCP\IConfig::class);
	}

	public function testResetRestoresTheSilentLogger(): void {
		\OC::$server->register(LoggerInterface::class, $this->createStub(LoggerInterface::class));
		\OC::$server->reset();

		$this->assertInstanceOf(NullLogger::class, Server::get(LoggerInterface::class));
	}

	public function testADefaultUrlGeneratorAnswersPredictableAddresses(): void {
		\OC::$server->reset();

		$urls = Server::get(IURLGenerator::class);

		$this->assertSame('https://cloud.example.org/apps/social/img/header-missing.svg', $urls->getAbsoluteURL($urls->imagePath('social', 'header-missing.svg')));
		$this->assertSame('https://cloud.example.org/core.avatar.getAvatar/alice/128', $urls->linkToRouteAbsolute('core.avatar.getAvatar', ['userId' => 'alice', 'size' => 128]));
	}
}
