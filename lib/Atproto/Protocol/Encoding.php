<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

use InvalidArgumentException;

/**
 * The text encodings AT Protocol uses for binary values: base32 (CIDs),
 * base58btc (did:key), base64url (signatures, JWTs) and base32-sortable
 * (TIDs). All of them without padding, all of them strict on decode.
 */
final class Encoding {
	private const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';
	private const BASE58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

	public static function base32Encode(string $bytes): string {
		$out = '';
		$buffer = 0;
		$bits = 0;
		foreach (str_split($bytes) as $char) {
			$buffer = ($buffer << 8) | ord($char);
			$bits += 8;
			while ($bits >= 5) {
				$bits -= 5;
				$out .= self::BASE32[($buffer >> $bits) & 0x1f];
			}
		}
		if ($bits > 0) {
			$out .= self::BASE32[($buffer << (5 - $bits)) & 0x1f];
		}

		return $out;
	}

	public static function base32Decode(string $text): string {
		$out = '';
		$buffer = 0;
		$bits = 0;
		foreach (str_split($text) as $char) {
			$value = strpos(self::BASE32, $char);
			if ($value === false) {
				throw new InvalidArgumentException('Not base32: ' . $char);
			}
			$buffer = (($buffer << 5) | $value) & 0xffff;
			$bits += 5;
			if ($bits >= 8) {
				$bits -= 8;
				$out .= chr(($buffer >> $bits) & 0xff);
			}
		}
		// the bits left over must be the zero padding of the last byte
		if ($bits >= 5 || ($buffer & ((1 << $bits) - 1)) !== 0) {
			throw new InvalidArgumentException('Not canonical base32');
		}

		return $out;
	}

	public static function base58Encode(string $bytes): string {
		$zeros = strlen($bytes) - strlen(ltrim($bytes, "\0"));
		$digits = [];
		foreach (str_split(substr($bytes, $zeros) ?: '') as $char) {
			$carry = ord($char);
			foreach ($digits as $i => $digit) {
				$carry += $digit << 8;
				$digits[$i] = $carry % 58;
				$carry = intdiv($carry, 58);
			}
			while ($carry > 0) {
				$digits[] = $carry % 58;
				$carry = intdiv($carry, 58);
			}
		}
		$out = str_repeat('1', $zeros);
		for ($i = count($digits) - 1; $i >= 0; $i--) {
			$out .= self::BASE58[$digits[$i]];
		}

		return $out;
	}

	public static function base58Decode(string $text): string {
		$zeros = strlen($text) - strlen(ltrim($text, '1'));
		$bytes = [];
		foreach (str_split(substr($text, $zeros) ?: '') as $char) {
			$carry = strpos(self::BASE58, $char);
			if ($carry === false) {
				throw new InvalidArgumentException('Not base58: ' . $char);
			}
			foreach ($bytes as $i => $byte) {
				$carry += $byte * 58;
				$bytes[$i] = $carry & 0xff;
				$carry >>= 8;
			}
			while ($carry > 0) {
				$bytes[] = $carry & 0xff;
				$carry >>= 8;
			}
		}
		$out = str_repeat("\0", $zeros);
		for ($i = count($bytes) - 1; $i >= 0; $i--) {
			$out .= chr($bytes[$i]);
		}

		return $out;
	}

	public static function base64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}

	public static function base64UrlDecode(string $text): string {
		if (!preg_match('/^[A-Za-z0-9_-]*$/', $text)) {
			throw new InvalidArgumentException('Not base64url');
		}
		$decoded = base64_decode(strtr($text, '-_', '+/'), true);
		if ($decoded === false) {
			throw new InvalidArgumentException('Not base64url');
		}

		return $decoded;
	}

	/**
	 * Unsigned LEB128, as CAR files and multiformats use it.
	 */
	public static function varint(int $value): string {
		if ($value < 0) {
			throw new InvalidArgumentException('Varints are unsigned');
		}
		$out = '';
		while ($value >= 0x80) {
			$out .= chr(($value & 0x7f) | 0x80);
			$value >>= 7;
		}

		return $out . chr($value);
	}

	/**
	 * Reads one varint at $offset and moves $offset past it.
	 *
	 * @throws InvalidArgumentException when the bytes end inside the number or it is too wide
	 */
	public static function readVarint(string $bytes, int &$offset): int {
		$value = 0;
		$shift = 0;
		$length = strlen($bytes);
		do {
			if ($offset >= $length || $shift > 56) {
				throw new InvalidArgumentException('Truncated varint');
			}
			$byte = ord($bytes[$offset++]);
			$value |= ($byte & 0x7f) << $shift;
			$shift += 7;
		} while ($byte & 0x80);

		return $value;
	}
}
