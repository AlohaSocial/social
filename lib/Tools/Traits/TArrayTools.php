<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Traits;

use OCA\Social\Tools\Exceptions\ArrayNotFoundException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;

/**
 * Trait TArrayTools
 *
 * @package OCA\Social\Tools\Traits
 */
trait TArrayTools {
	protected function get(string $k, array $arr, string $default = ''): string {
		if (!array_key_exists($k, $arr)) {
			$subs = explode('.', $k, 2);
			if (sizeof($subs) > 1) {
				if (!array_key_exists($subs[0], $arr)) {
					return $default;
				}

				$r = $arr[$subs[0]];
				if (!is_array($r)) {
					return $default;
				}

				return $this->get($subs[1], $r, $default);
			} else {
				return $default;
			}
		}

		if ($arr[$k] === null || !is_string($arr[$k]) && (!is_int($arr[$k]))) {
			return $default;
		}

		return (string)$arr[$k];
	}

	protected function getInt(string $k, array $arr, int $default = 0): int {
		if (!array_key_exists($k, $arr)) {
			$subs = explode('.', $k, 2);
			if (sizeof($subs) > 1) {
				if (!array_key_exists($subs[0], $arr)) {
					return $default;
				}

				$r = $arr[$subs[0]];
				if (!is_array($r)) {
					return $default;
				}

				return $this->getInt($subs[1], $r, $default);
			} else {
				return $default;
			}
		}

		if ($arr[$k] === null) {
			return $default;
		}

		return intval($arr[$k]);
	}

	protected function getFloat(string $k, array $arr, float $default = 0): float {
		if (!array_key_exists($k, $arr)) {
			$subs = explode('.', $k, 2);
			if (sizeof($subs) > 1) {
				if (!array_key_exists($subs[0], $arr)) {
					return $default;
				}

				$r = $arr[$subs[0]];
				if (!is_array($r)) {
					return $default;
				}

				return $this->getFloat($subs[1], $r, $default);
			} else {
				return $default;
			}
		}

		if ($arr[$k] === null) {
			return $default;
		}

		return floatval($arr[$k]);
	}

	protected function getBool(string $k, array $arr, bool $default = false): bool {
		if (!array_key_exists($k, $arr)) {
			$subs = explode('.', $k, 2);
			if (sizeof($subs) > 1) {
				if (!array_key_exists($subs[0], $arr)) {
					return $default;
				}

				$r = $arr[$subs[0]];
				if (!is_array($r)) {
					return $default;
				}

				return $this->getBool($subs[1], $r, $default);
			} else {
				return $default;
			}
		}

		if ($arr[$k] === null) {
			return $default;
		}

		if (is_bool($arr[$k])) {
			return $arr[$k];
		}

		$sk = (string)$arr[$k];
		if ($sk === '1' || strtolower($sk) === 'true') {
			return true;
		}

		if ($sk === '0' || strtolower($sk) === 'false') {
			return false;
		}

		return $default;
	}

	protected function getArray(string $k, array $arr, array $default = []): array {
		if (!array_key_exists($k, $arr)) {
			$subs = explode('.', $k, 2);
			if (sizeof($subs) > 1) {
				if (!array_key_exists($subs[0], $arr)) {
					return $default;
				}

				$r = $arr[$subs[0]];
				if (!is_array($r)) {
					return $default;
				}

				return $this->getArray($subs[1], $r, $default);
			} else {
				return $default;
			}
		}

		$r = $arr[$k];
		if ($r === null || (!is_array($r) && !is_string($r))) {
			return $default;
		}

		if (is_string($r)) {
			$r = json_decode($r, true);
		}

		if (!is_array($r)) {
			return $default;
		}

		return $r;
	}

	/**
	 * @param string $k
	 * @param string $value
	 * @param array $list
	 *
	 * @return mixed
	 * @throws ArrayNotFoundException
	 */
	protected function extractArray(string $k, string $value, array $list) {
		foreach ($list as $arr) {
			if (!array_key_exists($k, $arr)) {
				continue;
			}

			if ($arr[$k] === $value) {
				return $arr;
			}
		}

		throw new ArrayNotFoundException();
	}

	/**
	 * @param array $keys
	 * @param array $arr
	 *
	 * @throws MalformedArrayException
	 */
	protected function mustContains(array $keys, array $arr) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $arr)) {
				throw new MalformedArrayException(
					'source: ' . json_encode($arr) . ' - missing key: ' . $key
				);
			}
		}
	}

	/**
	 * @param array $arr
	 */
	protected function cleanArray(array &$arr) {
		$arr = array_filter(
			$arr,
			function ($v) {
				if (is_string($v)) {
					return ($v !== '');
				}
				if (is_array($v)) {
					return !empty($v);
				}

				return true;
			}
		);
	}
}
