<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\SensitiveMediaService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Which of the three policies a reader gets, and the fourth thing that is not
 * one of them.
 */
class SensitiveMediaServiceTest extends TestCase {
	private ConfigService|Stub $configService;
	private SensitiveMediaService $service;

	/** the user-config rows, as the instance would hold them */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createStub(ConfigService::class);
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $user, string $app, string $key, $default = '') => $this->stored[$user] ?? $default
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $user, string $app, string $key, $value): void {
				$this->stored[$user] = $value;
			}
		);

		$this->service = new SensitiveMediaService($this->configService, $config);
	}

	private function instance(string $policy): void {
		$this->configService->method('getAppValue')->willReturn($policy);
	}

	public function testAnInstanceThatHasNotChosenCoversSensitiveMedia(): void {
		$this->instance('');

		$this->assertSame(SensitiveMediaService::COVERED, $this->service->instancePolicy());
	}

	/**
	 * Read on every page load, where there is nobody to tell: a value that is
	 * not one of the three is the covered one rather than an error.
	 */
	public function testAnythingThatIsNotOneOfTheThreeIsTheCoveredOne(): void {
		$this->instance('blur');

		$this->assertSame(SensitiveMediaService::COVERED, $this->service->instancePolicy());
	}

	public function testNobodySignedInGetsWhatTheInstanceDoes(): void {
		$this->instance(SensitiveMediaService::HIDE_ALL);

		$this->assertSame(SensitiveMediaService::HIDE_ALL, $this->service->policyFor(''));
	}

	public function testAnAccountThatHasNotChosenFollowsTheInstance(): void {
		$this->instance(SensitiveMediaService::HIDE_ALL);

		$this->assertSame(SensitiveMediaService::HIDE_ALL, $this->service->policyFor('alice'));
	}

	public function testAnAccountsOwnChoiceWins(): void {
		$this->instance(SensitiveMediaService::HIDE_ALL);
		$this->assertTrue($this->service->choose('alice', SensitiveMediaService::SHOW_ALL));

		$this->assertSame(SensitiveMediaService::SHOW_ALL, $this->service->policyFor('alice'));
	}

	/**
	 * "Follow the instance" and "the policy the instance currently has" look
	 * the same until an administrator changes the default, at which point a
	 * settings page that could not tell them apart would have silently pinned
	 * everybody to the old one.
	 */
	public function testFollowingTheInstanceIsAStateOfItsOwn(): void {
		$this->instance(SensitiveMediaService::COVERED);
		$this->service->choose('alice', SensitiveMediaService::COVERED);

		$this->assertSame(SensitiveMediaService::COVERED, $this->service->choiceOf('alice'));

		$this->service->choose('alice', SensitiveMediaService::FOLLOW_INSTANCE);

		$this->assertSame(SensitiveMediaService::FOLLOW_INSTANCE, $this->service->choiceOf('alice'));
		$this->assertSame(SensitiveMediaService::COVERED, $this->service->policyFor('alice'));
	}

	public function testAPolicyThatIsNotOneOfTheThreeIsRefusedRatherThanStored(): void {
		$this->instance(SensitiveMediaService::COVERED);

		$this->assertFalse($this->service->choose('alice', 'blur'));
		$this->assertSame([], $this->stored);
	}

	/** There is nobody to store it against. */
	public function testNobodySignedInCannotChoose(): void {
		$this->instance(SensitiveMediaService::COVERED);

		$this->assertFalse($this->service->choose('', SensitiveMediaService::SHOW_ALL));
	}
}
