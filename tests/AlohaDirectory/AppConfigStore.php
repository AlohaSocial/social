<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AlohaDirectory;

use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * An app config held in an array, for the Aloha Directory tests: what one
 * service writes, the next one reads.
 */
final class AppConfigStore {
	/** @var array<string, string> */
	public array $values = [];

	public function mock(TestCase $test): IAppConfig {
		$mock = (new \ReflectionMethod($test, 'createStub'))->invoke($test, IAppConfig::class);
		$mock->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => $this->values[$key] ?? $default
		);
		$mock->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->values[$key] = $value;

				return true;
			}
		);
		$mock->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->values[$key]);
		});

		return $mock;
	}
}
