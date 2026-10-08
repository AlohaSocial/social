<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto;

use RuntimeException;

/**
 * The interop vectors under tests/Atproto/fixtures, as the tests read them.
 */
final class Fixtures {
	public static function json(string $name): array {
		$path = __DIR__ . '/fixtures/' . $name;
		$content = file_get_contents($path);
		if ($content === false) {
			throw new RuntimeException('Missing fixture ' . $name);
		}

		return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * One value per line, blank lines and `#` comments skipped.
	 *
	 * @return string[]
	 */
	public static function lines(string $name): array {
		$path = __DIR__ . '/fixtures/syntax/' . $name;
		$content = file_get_contents($path);
		if ($content === false) {
			throw new RuntimeException('Missing fixture ' . $name);
		}
		$lines = [];
		foreach (explode("\n", $content) as $line) {
			$line = rtrim($line, "\r");
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}
			$lines[] = $line;
		}

		return $lines;
	}
}
