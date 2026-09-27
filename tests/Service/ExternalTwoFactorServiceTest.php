<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ExternalTwoFactorService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Core's mandatory two-factor setting, read and written for the externals'
 * group. The trap it must not fall into: an empty list of enforced groups
 * means everybody.
 */
class ExternalTwoFactorServiceTest extends TestCase {
	private array $system = [];
	private ExternalTwoFactorService $service;

	protected function setUp(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = '') => $this->system[$key] ?? $default
		);
		$config->method('setSystemValue')->willReturnCallback(
			function (string $key, $value): void {
				$this->system[$key] = $value;
			}
		);
		$this->service = new ExternalTwoFactorService($config);
	}

	private function core(bool $enforced, array $groups = [], array $excluded = []): void {
		$this->system = [
			'twofactor_enforced' => $enforced ? 'true' : 'false',
			'twofactor_enforced_groups' => $groups,
			'twofactor_enforced_excluded_groups' => $excluded,
		];
	}

	public function testNotEnforcedAnywhere(): void {
		$this->core(false);

		$this->assertSame(['enforced' => false, 'everybody' => false], $this->service->state());
	}

	public function testSwitchingItOnWhereNothingIsEnforcedEnforcesItForExternalsOnly(): void {
		$this->core(false, [], ['staff']);

		$this->assertSame(['enforced' => true, 'everybody' => false], $this->service->set(true));
		$this->assertSame('true', $this->system['twofactor_enforced']);
		$this->assertSame(['social-external'], $this->system['twofactor_enforced_groups']);
		$this->assertSame(['staff'], $this->system['twofactor_enforced_excluded_groups']);
	}

	public function testSwitchingItOnBesideOtherGroupsAddsTheGroup(): void {
		$this->core(true, ['admin']);

		$this->service->set(true);

		$this->assertSame(['admin', 'social-external'], $this->system['twofactor_enforced_groups']);
	}

	public function testSwitchingItOffBesideOtherGroupsKeepsThem(): void {
		$this->core(true, ['admin', 'social-external']);

		$this->assertSame(['enforced' => false, 'everybody' => false], $this->service->set(false));
		$this->assertSame('true', $this->system['twofactor_enforced']);
		$this->assertSame(['admin'], $this->system['twofactor_enforced_groups']);
	}

	public function testRemovingTheLastGroupTurnsEnforcementOffRatherThanOnForEverybody(): void {
		$this->core(true, ['social-external']);

		$this->service->set(false);

		$this->assertSame('false', $this->system['twofactor_enforced']);
		$this->assertSame([], $this->system['twofactor_enforced_groups']);
	}

	public function testEnforcementForEverybodyCoversExternalsUnlessExcluded(): void {
		$this->core(true);
		$this->assertSame(['enforced' => true, 'everybody' => true], $this->service->state());

		$this->assertSame(['enforced' => false, 'everybody' => true], $this->service->set(false));
		$this->assertSame('true', $this->system['twofactor_enforced']);
		$this->assertSame(['social-external'], $this->system['twofactor_enforced_excluded_groups']);

		$this->assertSame(['enforced' => true, 'everybody' => true], $this->service->set(true));
		$this->assertSame([], $this->system['twofactor_enforced_excluded_groups']);
	}

	public function testSwitchingItOffWhereNothingIsEnforcedChangesNothing(): void {
		$this->core(false);

		$this->service->set(false);

		$this->assertSame('false', $this->system['twofactor_enforced']);
		$this->assertSame([], $this->system['twofactor_enforced_groups']);
	}
}
