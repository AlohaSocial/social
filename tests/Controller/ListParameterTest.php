<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ListParameter;
use PHPUnit\Framework\TestCase;

class ListParameterTest extends TestCase {
	use ListParameter;

	public function testAListIsKeptAsStrings(): void {
		$this->assertSame(['1', 'mention'], self::listParam([1, 'mention']));
	}

	public function testOneValueWithoutBracketsIsAListOfOne(): void {
		$this->assertSame(['mention'], self::listParam('mention'));
	}

	public function testBlanksAndNestedValuesAreLeftOut(): void {
		$this->assertSame(['a'], self::listParam(['', ' ', ['x'], ' a ']));
		$this->assertSame([], self::listParam(''));
	}
}
