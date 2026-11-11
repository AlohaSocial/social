<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Security;

use OCA\Social\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Stored text stamped with `HtmlSanitizer::version()` is trusted on read
 * without being sanitized again, so a change to what the sanitizer lets
 * through has to come with a new `VERSION`, or text cleaned under the old
 * rules would be served as if it had met the new ones.
 */
class HtmlSanitizerVersionTest extends TestCase {
	/**
	 * The allowlists as they were when `VERSION` was last bumped. When this
	 * fails because a list changed, bump `HtmlSanitizer::VERSION` and record
	 * the new fingerprint here with it.
	 */
	private const FINGERPRINTS = [
		1 => '9c7e43ccd861e4337c8decd8fedf0d24',
	];

	public function testTheAllowlistsAreTheOnesTheVersionWasGivenFor(): void {
		$constants = (new ReflectionClass(HtmlSanitizer::class))->getConstants();
		unset($constants['VERSION']);
		ksort($constants);

		$this->assertSame(
			self::FINGERPRINTS[HtmlSanitizer::VERSION] ?? null,
			md5(json_encode($constants, JSON_THROW_ON_ERROR)),
			'the sanitizer\'s lists changed: bump HtmlSanitizer::VERSION'
		);
	}

	/** The stamp tells the two parsers apart, and each revision from the next. */
	public function testTheStampNamesTheRevisionAndTheParser(): void {
		$this->assertSame(HtmlSanitizer::VERSION, intdiv(HtmlSanitizer::version(), 2));
		$this->assertSame(PHP_VERSION_ID >= 80400 ? 1 : 0, HtmlSanitizer::version() % 2);
		$this->assertGreaterThan(0, HtmlSanitizer::version(), '0 is the stamp of a row never sanitized on the way in');
	}
}
