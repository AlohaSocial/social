<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261108400000;
use OCA\Social\Service\ConfigService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Learning from reading is opted into: a stored old default is removed so the
 * new one applies, and a stored opt-out stays as it is.
 */
class InterestsDefaultOptInTest extends TestCase {
	/** @return iterable<string, array{string|null, bool}> */
	public static function storedValues(): iterable {
		yield 'the old default, saved with the card' => ['1', true];
		yield 'an administrator who already chose off' => ['0', false];
		yield 'nothing stored' => [null, false];
	}

	#[DataProvider('storedValues')]
	public function testOnlyAStoredOnIsRemoved(?string $stored, bool $removed): void {
		$values = $stored === null ? [] : [ConfigService::SOCIAL_INTERESTS_DEFAULT => $stored];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$values): string {
				self::assertSame('social', $app);

				return $values[$key] ?? $default;
			}
		);
		$appConfig->expects($removed ? $this->once() : $this->never())->method('deleteKey')
			->with('social', ConfigService::SOCIAL_INTERESTS_DEFAULT)
			->willReturnCallback(static function (string $app, string $key) use (&$values): void {
				unset($values[$key]);
			});

		$step = new Version1000Date20261108400000($appConfig);
		$output = $this->createStub(IOutput::class);
		$step->postSchemaChange($output, static fn () => null, []);
		// a second run finds nothing left to change
		$step->postSchemaChange($output, static fn () => null, []);
	}
}
