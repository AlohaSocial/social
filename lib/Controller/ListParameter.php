<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

/**
 * A list parameter as a client sends it: `name[]=a&name[]=b`, or one
 * `name=a` without the brackets, which PHP hands over as a string. A route
 * parameter typed `array` alone turns the second into a TypeError raised by
 * the dispatcher before the route's own error handling, so these take
 * `array|string` and read it through `listParam()`.
 */
trait ListParameter {
	/**
	 * The scalar entries of `$value` as strings, blanks left out.
	 *
	 * @param array<mixed>|string $value
	 * @return string[]
	 */
	protected static function listParam(array|string $value): array {
		$list = [];
		foreach ((array)$value as $entry) {
			if (!is_scalar($entry)) {
				continue;
			}
			$entry = trim((string)$entry);
			if ($entry !== '') {
				$list[] = $entry;
			}
		}

		return $list;
	}
}
