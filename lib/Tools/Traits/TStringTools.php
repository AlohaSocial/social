<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Traits;

use Exception;

trait TStringTools {
	/**
	 * A random token of $length characters; a failure of the random source
	 * throws rather than returning a short token.
	 *
	 * @throws Exception when no cryptographically secure source is available
	 */
	protected function token(int $length = 15): string {
		$chars = 'qwertyuiopasdfghjklzxcvbnmQWERTYUIOPASDFGHJKLZXCVBNM1234567890';

		$str = '';
		$max = strlen($chars);
		for ($i = 0; $i < $length; $i++) {
			$str .= $chars[random_int(0, $max - 1)];
		}

		return $str;
	}

	/**
	 * Generate uuid: 2b5a7a87-8db1-445f-a17b-405790f91c80
	 *
	 * Version 4, from `random_bytes()`: the value becomes a document id and the
	 * file name behind `/media/{uuid}`, so it must not be predictable.
	 *
	 * @param int $length
	 *
	 * @return string
	 * @throws Exception when no cryptographically secure source is available
	 */
	protected function uuid(int $length = 0): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 1

		$hex = bin2hex($bytes);
		$uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
			. '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);

		if ($length > 0) {
			if ($length <= 16) {
				$uuid = str_replace('-', '', $uuid);
			}

			$uuid = substr($uuid, 0, $length);
		}

		return $uuid;
	}
}
