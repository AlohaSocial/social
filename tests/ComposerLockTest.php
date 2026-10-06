<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests;

use PHPUnit\Framework\TestCase;

/**
 * composer.lock records a hash of composer.json, and `composer install`
 * warns on every run once the two disagree. composer.json carries the app's
 * version (DocumentationTest keeps it equal to appinfo/info.xml), so every
 * release bump changes the hash: a bump that does not also run
 * `composer update --lock` leaves the warning on for good, and the day a real
 * `require` change lands without a lock update nobody notices.
 */
class ComposerLockTest extends TestCase {
	/** The keys Composer's Locker::getContentHash() reads, in its own list. */
	private const RELEVANT = [
		'name', 'version', 'require', 'require-dev', 'conflict', 'replace',
		'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra',
	];

	public function testTheLockFileMatchesComposerJson(): void {
		$root = dirname(__DIR__);
		$composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
		$lock = json_decode((string)file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(
			self::contentHash($composer),
			$lock['content-hash'] ?? null,
			'composer.lock is stale: run `composer update --lock` and commit composer.lock with the version bump'
		);
	}

	/** Composer 2's content hash of a composer.json. */
	private static function contentHash(array $composer): string {
		$relevant = [];
		foreach (array_intersect(self::RELEVANT, array_keys($composer)) as $key) {
			$relevant[$key] = $composer[$key];
		}
		if (isset($composer['config']['platform'])) {
			$relevant['config']['platform'] = $composer['config']['platform'];
		}
		ksort($relevant);

		return md5((string)json_encode($relevant, 0));
	}
}
