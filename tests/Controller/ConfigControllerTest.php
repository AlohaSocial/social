<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ConfigController;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\TestService;
use OCA\Social\Tools\Model\SimpleDataStore;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ConfigControllerTest extends TestCase {
	/** @var TestService&MockObject */
	private $testService;
	/** @var ConfigService&MockObject */
	private $configService;
	private ConfigController $controller;

	protected function setUp(): void {
		$this->testService = $this->createMock(TestService::class);
		$this->configService = $this->createMock(ConfigService::class);

		$this->controller = new ConfigController(
			'social',
			$this->createStub(IRequest::class),
			$this->testService,
			$this->configService,
			$this->createStub(MiscService::class)
		);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testSetCloudAddressStoresTheAddress(): void {
		$this->configService->expects($this->once())->method('setCloudUrl')->with('https://cloud.example');

		$response = $this->controller->setCloudAddress('https://cloud.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testLocalReportsVersionAndCompletedSetup(): void {
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$this->configService->method('getAppValue')->with('installed_version')->willReturn('0.10.0');

		$response = $this->controller->local();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['result' => ['version' => '0.10.0', 'setup' => true], 'status' => 1], $response->getData());
	}

	public function testLocalReportsMissingSetup(): void {
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->configService->method('getAppValue')->willReturn('0.10.0');

		$this->assertFalse($this->controller->local()->getData()['result']['setup']);
	}

	public function testRemoteWithoutAccountFallsBackToLocal(): void {
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$this->configService->method('getAppValue')->willReturn('1.0');
		$this->testService->expects($this->never())->method('testWebfinger');

		$this->assertSame(['version' => '1.0', 'setup' => true], $this->controller->remote('')->getData()['result']);
	}

	public function testRemoteWithoutTestEndpointFallsBackToLocal(): void {
		$this->configService->method('getSystemValue')->with('social.tests')->willReturn('');
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$this->configService->method('getAppValue')->willReturn('1.0');
		$this->testService->expects($this->never())->method('testWebfinger');

		$this->assertSame(['version' => '1.0', 'setup' => true], $this->controller->remote('alice@cloud.example')->getData()['result']);
	}

	public function testRemoteReportsUnconfiguredLocalInstance(): void {
		$this->configService->method('getSystemValue')->willReturn('https://tests.example');
		$this->configService->method('getCloudUrl')->willThrowException(new SocialAppConfigException());
		$this->testService->expects($this->never())->method('testWebfinger');

		$this->assertSame(
			['error' => 'error on my side: my own Social App is not configured'],
			$this->controller->remote('alice@cloud.example')->getData()['result']
		);
	}

	public function testRemoteRunsTheWebfingerTestAgainstTheEndpoint(): void {
		$this->configService->method('getSystemValue')->willReturn('https://tests.example');
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$this->testService->expects($this->once())->method('testWebfinger')
			->with($this->callback(function (SimpleDataStore $tests): bool {
				return $tests->g('account') === 'alice@cloud.example'
					&& $tests->g('endpoint') === 'https://tests.example';
			}));

		$response = $this->controller->remote('alice@cloud.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['status']);
		$this->assertInstanceOf(SimpleDataStore::class, $response->getData()['result'][0]);
	}

	public function testRemoteReportsAFailedWebfingerTestWithStatus200(): void {
		$this->configService->method('getSystemValue')->willReturn('https://tests.example');
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');
		$this->testService->method('testWebfinger')->willThrowException(new \RuntimeException('webfinger down'));

		$response = $this->controller->remote('alice@cloud.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(-1, $data['status']);
		$this->assertSame('request failed', $data['error']);
		$this->assertArrayNotHasKey('exception', $data, 'internals must not leak');
		$this->assertArrayNotHasKey('message', $data);
		$this->assertInstanceOf(SimpleDataStore::class, $data['result']);
	}
}
