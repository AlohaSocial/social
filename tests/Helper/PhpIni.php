<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

/**
 * Stand-in for the server's `IniGetWrapper`, which `\OCP\Util::uploadLimit()`
 * resolves from the container: php.ini values a test chooses, `0` (no limit)
 * for any it does not.
 */
class PhpIni {
	/** The container id `\OCP\Util` asks for; the class itself is not in this suite. */
	public const SERVICE = 'bantu\\IniGetWrapper\\IniGetWrapper';

	/** @param array<string, string> $values */
	public function __construct(
		private array $values = [],
	) {
	}

	public function get(string $key): string {
		return $this->values[$key] ?? '0';
	}
}
