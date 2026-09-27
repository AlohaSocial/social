<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Traits;

/**
 * Trait TPathTools
 *
 * @deprecated - 19
 * @package OCA\Social\Tools\Traits
 */
trait TPathTools {
	/**
	 * @param string $path
	 *
	 * @return string
	 */
	protected function withEndSlash(string $path): string {
		$path .= '/';
		$path = str_replace('//', '/', $path);

		return trim($path);
	}

	/**
	 * @param string $path
	 * @param bool $force
	 * @param bool $clean
	 *
	 * @return string
	 */
	protected function withoutEndSlash(string $path, bool $force = false, bool $clean = true,
	): string {
		if ($clean) {
			$path = str_replace('//', '/', $path);
		}

		if ($path === '/' && !$force) {
			return $path;
		}

		$path = rtrim($path, '/');

		return trim($path);
	}

	/**
	 * @param string $path
	 *
	 * @return string
	 */
	protected function withBeginSlash(string $path): string {
		$path = '/' . $path;
		$path = str_replace('//', '/', $path);

		return trim($path);
	}

	/**
	 * @param string $path
	 * @param bool $force
	 * @param bool $clean
	 *
	 * @return string
	 */
	protected function withoutBeginSlash(string $path, bool $force = false, bool $clean = true) {
		if ($clean) {
			$path = str_replace('//', '/', $path);
		}

		if ($path === '/' && !$force) {
			return $path;
		}

		$path = ltrim($path, '/');

		return trim($path);
	}

	protected function withoutBeginAt(string $path): string {
		$path = ltrim($path, '@');

		return trim($path);
	}
}
