<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

final class Cid {
	public function __construct(
		public readonly string $value,
	) {
		self::decode($value);
	}
	public static function hash(string $bytes, int $codec = 0x71): string {
		if (!in_array($codec, [0x71, 0x55], true)) {
			throw new \InvalidArgumentException('Unsupported CID codec');
		}
		return 'b' . self::base32("\x01" . chr($codec) . "\x12\x20" . hash('sha256', $bytes, true));
	}
	public static function base32(string $bytes): string {
		$out = '';
		$buffer = 0;
		$bits = 0;
		foreach (str_split($bytes) as $byte) {
			$buffer = ($buffer << 8) | ord($byte);
			$bits += 8;
			while ($bits >= 5) {
				$bits -= 5;
				$out .= 'abcdefghijklmnopqrstuvwxyz234567'[($buffer >> $bits) & 31];
			}
			$buffer &= (1 << $bits) - 1;
		}
		if ($bits > 0) {
			$out .= 'abcdefghijklmnopqrstuvwxyz234567'[($buffer << (5 - $bits)) & 31];
		}
		return $out;
	}
	public static function decode(string $cid): string {
		if (!preg_match('/^b[a-z2-7]{58}$/D', $cid)) {
			throw new \InvalidArgumentException('Invalid CID');
		}
		$buffer = 0;
		$bits = 0;
		$bytes = '';
		foreach (str_split(substr($cid, 1)) as $char) {
			$buffer = ($buffer << 5) | strpos('abcdefghijklmnopqrstuvwxyz234567', $char);
			$bits += 5;
			if ($bits >= 8) {
				$bits -= 8;
				$bytes .= chr(($buffer >> $bits) & 255);
			}
			$buffer &= (1 << $bits) - 1;
		}
		if (strlen($bytes) !== 36 || $bytes[0] !== "\x01" || !in_array(ord($bytes[1]), [0x71, 0x55], true)
			|| substr($bytes, 2, 2) !== "\x12\x20" || $buffer !== 0 || 'b' . self::base32($bytes) !== $cid) {
			throw new \InvalidArgumentException('Invalid CID encoding');
		}
		return $bytes;
	}
}
