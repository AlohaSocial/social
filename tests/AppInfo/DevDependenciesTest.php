<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * The app's dev dependencies next to the server they run in.
 *
 * `lib/AppInfo/Application.php` loads the app's own `vendor/autoload.php`,
 * and composer prepends it to the server's. A dev-only package the server
 * also carries is then served in place of the server's own: with
 * `symfony/console` 6.4 in a dev checkout inside Nextcloud 36, which calls
 * 7.4's `Application::addCommand()`, every `occ` call died — and with it the
 * interop job, the one job that runs the app against Nextcloud master.
 */
class DevDependenciesTest extends TestCase {
	/**
	 * The major of `symfony/console` Nextcloud 35 and 36 ship. Nextcloud 34
	 * ships 6.4, and a dev checkout cannot match both; the newest is the one
	 * the interop job and a development server run against.
	 */
	private const SERVER_CONSOLE_MAJOR = '7';

	private function root(): string {
		return dirname(__DIR__, 2);
	}

	public function testTheLockedConsoleIsTheMajorTheNewestServerShips(): void {
		$lock = json_decode((string)file_get_contents($this->root() . '/composer.lock'), true);
		$versions = [];
		foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
			if (($package['name'] ?? '') === 'symfony/console') {
				$versions[] = ltrim((string)$package['version'], 'v');
			}
		}

		$this->assertCount(1, $versions, 'symfony/console is not in composer.lock');
		$this->assertStringStartsWith(self::SERVER_CONSOLE_MAJOR . '.', $versions[0]);
	}

	public function testTheRequirementStillAdmitsWhatTheOldestServerShips(): void {
		$composer = json_decode((string)file_get_contents($this->root() . '/composer.json'), true);
		$constraint = (string)($composer['require-dev']['symfony/console'] ?? '');

		$this->assertStringContainsString('^6.4', $constraint);
		$this->assertStringContainsString('^' . self::SERVER_CONSOLE_MAJOR . '.', $constraint);
	}

	/**
	 * The interop job brings the server up with the app as it ships, and adds
	 * the dev dependencies only for the test runner afterwards.
	 */
	public function testTheInteropJobEnablesTheAppWithoutItsDevDependencies(): void {
		$workflow = (string)file_get_contents($this->root() . '/.github/workflows/interop.yml');

		$noDev = strpos($workflow, 'composer i --no-dev');
		$enable = strpos($workflow, 'app:enable --force social');
		// the suites run one at a time, each through the interop config
		$tests = strpos($workflow, '-c tests/Interop/phpunit.xml');

		$this->assertNotFalse($noDev, 'the interop job installs the dev dependencies before the server starts');
		$this->assertNotFalse($enable);
		$this->assertNotFalse($tests);
		$this->assertLessThan($enable, $noDev);
		$this->assertLessThan($tests, $enable);
	}
}
