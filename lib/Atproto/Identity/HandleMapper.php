<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

/**
 * `alice.<instance host>` from a username.
 *
 * A Social username may hold what a DNS label may not — dots, underscores,
 * capitals — so it is mapped: lower-cased, every run of other characters
 * becomes one hyphen, hyphens are trimmed, and the label is cut to 63. The
 * result is stored with the identity, never recomputed, so a later change
 * of this rule renames nobody; a collision after mapping gets a number.
 */
final class HandleMapper {
	private const MAX_LABEL = 63;

	public static function label(string $username): string {
		$label = strtolower($username);
		$label = (string)preg_replace('/[^a-z0-9-]+/', '-', $label);
		$label = (string)preg_replace('/-{2,}/', '-', $label);
		$label = trim($label, '-');
		if ($label === '') {
			$label = 'user';
		}
		if (ctype_digit($label[0])) {
			$label = 'u-' . $label;
		}

		return rtrim(substr($label, 0, self::MAX_LABEL), '-');
	}

	/**
	 * The first free handle for the username.
	 *
	 * @param callable(string): bool $taken whether a handle is already issued
	 */
	public static function handle(string $username, string $host, callable $taken): string {
		$label = self::label($username);
		$handle = $label . '.' . strtolower($host);
		if (!$taken($handle)) {
			return $handle;
		}
		for ($n = 2; $n < 10000; $n++) {
			$suffix = '-' . $n;
			$candidate = rtrim(substr($label, 0, self::MAX_LABEL - strlen($suffix)), '-') . $suffix . '.' . strtolower($host);
			if (!$taken($candidate)) {
				return $candidate;
			}
		}

		throw new \RuntimeException('No free handle for ' . $username);
	}
}
