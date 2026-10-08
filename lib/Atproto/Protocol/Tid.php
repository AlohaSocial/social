<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

use InvalidArgumentException;

/**
 * Timestamp identifiers: 53 bits of microseconds since the epoch and a
 * 10-bit clock id, written as 13 characters of base32-sortable, so that
 * record keys and commit revisions sort by time as strings.
 */
final class Tid {
	public const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';
	private const PATTERN = '/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/';

	private static int $last = 0;
	private static ?int $clockId = null;

	/**
	 * The next TID: later than every one this process has issued, so a
	 * burst of writes in one microsecond still gets distinct, ordered ids.
	 */
	public static function next(): string {
		[$fraction, $seconds] = explode(' ', microtime());
		$micros = (int)$seconds * 1000000 + (int)substr($fraction, 2, 6);
		if ($micros <= self::$last) {
			$micros = self::$last + 1;
		}
		self::$last = $micros;
		self::$clockId ??= random_int(0, 1023);

		return self::encode($micros, self::$clockId);
	}

	/**
	 * A TID that is later than $after, for a revision that must advance a
	 * repository whose head was written by another process or a clock that
	 * went backwards.
	 */
	public static function after(string $after): string {
		$tid = self::next();
		if (strcmp($tid, $after) > 0) {
			return $tid;
		}
		[$micros, $clockId] = self::decode($after);
		self::$last = $micros + 1;

		return self::encode(self::$last, $clockId);
	}

	public static function encode(int $micros, int $clockId): string {
		if ($micros < 0 || $micros >= (1 << 53) || $clockId < 0 || $clockId > 1023) {
			throw new InvalidArgumentException('TID out of range');
		}
		$value = ($micros << 10) | $clockId;
		$out = '';
		for ($i = 0; $i < 13; $i++) {
			$out .= self::ALPHABET[($value >> (60 - 5 * $i)) & 0x1f];
		}

		return $out;
	}

	/**
	 * @return array{0: int, 1: int} microseconds and clock id
	 * @throws InvalidArgumentException
	 */
	public static function decode(string $tid): array {
		if (!self::isValid($tid)) {
			throw new InvalidArgumentException('Not a TID: ' . $tid);
		}
		$value = 0;
		for ($i = 0; $i < 13; $i++) {
			$value = ($value << 5) | (int)strpos(self::ALPHABET, $tid[$i]);
		}

		return [$value >> 10, $value & 0x3ff];
	}

	public static function isValid(string $tid): bool {
		return preg_match(self::PATTERN, $tid) === 1;
	}
}
