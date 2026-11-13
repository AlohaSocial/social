<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\WellKnown;

use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCA\Social\AlohaDirectory\DirectoryKey;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Tests\AlohaDirectory\AppConfigStore;
use OCA\Social\WellKnown\AlohaDirectoryHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class AlohaDirectoryHandlerTest extends TestCase {
	private DirectoryConfig $config;
	private AlohaDirectoryHandler $handler;

	protected function setUp(): void {
		$cipher = $this->createStub(PrivateKeyCipher::class);
		$cipher->method('seal')->willReturnArgument(0);
		$cipher->method('open')->willReturnArgument(0);
		$this->config = new DirectoryConfig((new AppConfigStore())->mock($this), $cipher);
		$this->handler = new AlohaDirectoryHandler($this->config);
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testThePendingChallengeIsServed(): void {
		$key = DirectoryKey::generate();
		$this->config->setKey($key, DirectoryConfig::PENDING_KEY);
		$this->config->setChallenge('the-challenge');

		$response = $this->handler->handle('aloha-directory', $this->createStub(IRequestContext::class), null);

		$this->assertNotNull($response);
		$http = $response->toHttpResponse();
		$this->assertSame(['challenge' => 'the-challenge', 'key_id' => $key->keyId()], $http->getData());
		$this->assertSame('no-store', $http->getHeaders()['Cache-Control']);
	}

	public function testNothingIsServedWithoutAnEnrolment(): void {
		$previous = $this->createStub(IResponse::class);

		$this->assertSame($previous, $this->handler->handle('aloha-directory', $this->createStub(IRequestContext::class), $previous));
	}

	public function testOtherServicesArePassedOn(): void {
		$this->config->setKey(DirectoryKey::generate(), DirectoryConfig::PENDING_KEY);
		$this->config->setChallenge('the-challenge');

		$this->assertNull($this->handler->handle('webfinger', $this->createStub(IRequestContext::class), null));
	}
}
