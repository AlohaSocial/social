<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Crypto;

use InvalidArgumentException;

/**
 * The little DER an ECDSA signature needs: OpenSSL signs and verifies
 * SEQUENCE { INTEGER r, INTEGER s }, the protocol carries r || s.
 */
final class Der {
	public static function sequence(string $content): string {
		return "\x30" . self::length(strlen($content)) . $content;
	}

	public static function unsignedInteger(BigNum $value): string {
		$bytes = ltrim($value->toBytes(33), "\0");
		if ($bytes === '') {
			$bytes = "\0";
		}
		if (ord($bytes[0]) & 0x80) {
			$bytes = "\0" . $bytes;
		}

		return "\x02" . self::length(strlen($bytes)) . $bytes;
	}

	/**
	 * @return array{0: BigNum, 1: BigNum} r and s
	 * @throws InvalidArgumentException
	 */
	public static function parseSignature(string $der): array {
		$offset = 0;
		if (self::byte($der, $offset) !== 0x30) {
			throw new InvalidArgumentException('Not a DER sequence');
		}
		$length = self::readLength($der, $offset);
		if ($offset + $length !== strlen($der)) {
			throw new InvalidArgumentException('DER sequence length mismatch');
		}
		$r = self::readInteger($der, $offset);
		$s = self::readInteger($der, $offset);
		if ($offset !== strlen($der)) {
			throw new InvalidArgumentException('Trailing DER bytes');
		}

		return [$r, $s];
	}

	private static function readInteger(string $der, int &$offset): BigNum {
		if (self::byte($der, $offset) !== 0x02) {
			throw new InvalidArgumentException('Not a DER integer');
		}
		$length = self::readLength($der, $offset);
		if ($length < 1 || $offset + $length > strlen($der)) {
			throw new InvalidArgumentException('DER integer length');
		}
		$bytes = substr($der, $offset, $length);
		$offset += $length;
		if (ord($bytes[0]) & 0x80) {
			throw new InvalidArgumentException('DER integer is negative');
		}

		return BigNum::fromBytes($bytes);
	}

	private static function length(int $length): string {
		if ($length < 0x80) {
			return chr($length);
		}
		if ($length <= 0xff) {
			return "\x81" . chr($length);
		}

		return "\x82" . pack('n', $length);
	}

	private static function readLength(string $der, int &$offset): int {
		$first = self::byte($der, $offset);
		if ($first < 0x80) {
			return $first;
		}
		$count = $first & 0x7f;
		if ($count === 0 || $count > 2) {
			throw new InvalidArgumentException('DER length form not supported');
		}
		$length = 0;
		for ($i = 0; $i < $count; $i++) {
			$length = ($length << 8) | self::byte($der, $offset);
		}

		return $length;
	}

	private static function byte(string $der, int &$offset): int {
		if ($offset >= strlen($der)) {
			throw new InvalidArgumentException('DER ends early');
		}

		return ord($der[$offset++]);
	}
}
