<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

use InvalidArgumentException;

/**
 * The DRISL subset of CBOR that AT Protocol stores and signs: minimal integer
 * heads, map keys sorted by length then bytes, no floats, no indefinite
 * lengths, and tag 42 for a CID link. One encoding per value, so the CID of
 * a record is the same on every implementation.
 *
 * The PHP model: a list array is a CBOR array, any other array is a map with
 * string keys, int/string/bool/null are themselves, Bytes is a byte string
 * and Cid is a link. Decoding gives the same model back.
 */
final class DagCbor {
	/** deeper than any record or MST node; a bound on untrusted input */
	private const MAX_DEPTH = 64;

	private const MAJOR_UINT = 0;
	private const MAJOR_NEGINT = 1;
	private const MAJOR_BYTES = 2;
	private const MAJOR_TEXT = 3;
	private const MAJOR_ARRAY = 4;
	private const MAJOR_MAP = 5;
	private const MAJOR_TAG = 6;
	private const MAJOR_SIMPLE = 7;

	private const TAG_CID = 42;

	/**
	 * @throws InvalidArgumentException when the value has no DRISL representation
	 */
	public static function encode(mixed $value): string {
		return self::encodeValue($value, 0);
	}

	/**
	 * @throws InvalidArgumentException when the bytes are not one complete DRISL value
	 */
	public static function decode(string $bytes): mixed {
		$offset = 0;
		$value = self::decodeValue($bytes, $offset, 0);
		if ($offset !== strlen($bytes)) {
			throw new InvalidArgumentException('Trailing bytes after the CBOR value');
		}

		return $value;
	}

	/**
	 * Decodes the value at $offset and leaves $offset after it, for streams
	 * that concatenate values (an event-stream frame is two of them).
	 *
	 * @throws InvalidArgumentException
	 */
	public static function decodeAt(string $bytes, int &$offset): mixed {
		return self::decodeValue($bytes, $offset, 0);
	}

	/**
	 * The JSON form of a value: links become {"$link": cid} and bytes
	 * {"$bytes": base64}, as the XRPC surface shows them.
	 */
	public static function toLexJson(mixed $value): mixed {
		if ($value instanceof Cid) {
			return ['$link' => $value->toString()];
		}
		if ($value instanceof Bytes) {
			return ['$bytes' => rtrim(base64_encode($value->value), '=')];
		}
		if (is_array($value)) {
			$out = [];
			foreach ($value as $key => $item) {
				$out[$key] = self::toLexJson($item);
			}

			return $out;
		}

		return $value;
	}

	/**
	 * The reverse of toLexJson(), for records that arrive as JSON.
	 *
	 * @throws InvalidArgumentException when a $link is not a CID or $bytes not base64
	 */
	public static function fromLexJson(mixed $value): mixed {
		if (!is_array($value)) {
			return $value;
		}
		if (count($value) === 1 && isset($value['$link'])) {
			if (!is_string($value['$link'])) {
				throw new InvalidArgumentException('$link must be a string');
			}

			return Cid::parse($value['$link']);
		}
		if (count($value) === 1 && isset($value['$bytes'])) {
			$text = is_string($value['$bytes']) ? $value['$bytes'] : '';
			// written without padding, accepted either way
			$text = rtrim($text, '=');
			$decoded = preg_match('#^[A-Za-z0-9+/]*$#', $text) === 1
				? base64_decode(str_pad($text, (int)ceil(strlen($text) / 4) * 4, '='), true)
				: false;
			if ($decoded === false) {
				throw new InvalidArgumentException('$bytes must be base64');
			}

			return new Bytes($decoded);
		}
		$out = [];
		foreach ($value as $key => $item) {
			$out[$key] = self::fromLexJson($item);
		}

		return $out;
	}

	private static function encodeValue(mixed $value, int $depth): string {
		if ($depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('CBOR value nested too deeply');
		}
		if ($value === null) {
			return "\xf6";
		}
		if (is_bool($value)) {
			return $value ? "\xf5" : "\xf4";
		}
		if (is_int($value)) {
			return $value >= 0
				? self::head(self::MAJOR_UINT, $value)
				: self::head(self::MAJOR_NEGINT, -1 - $value);
		}
		if (is_string($value)) {
			if (!mb_check_encoding($value, 'UTF-8')) {
				throw new InvalidArgumentException('CBOR text must be UTF-8');
			}

			return self::head(self::MAJOR_TEXT, strlen($value)) . $value;
		}
		if ($value instanceof Bytes) {
			return self::head(self::MAJOR_BYTES, strlen($value->value)) . $value->value;
		}
		if ($value instanceof Cid) {
			$link = "\0" . $value->bytes();

			return self::head(self::MAJOR_TAG, self::TAG_CID) . self::head(self::MAJOR_BYTES, strlen($link)) . $link;
		}
		if (is_array($value)) {
			if (array_is_list($value)) {
				$out = self::head(self::MAJOR_ARRAY, count($value));
				foreach ($value as $item) {
					$out .= self::encodeValue($item, $depth + 1);
				}

				return $out;
			}
			$keys = array_map('strval', array_keys($value));
			usort($keys, static fn (string $a, string $b): int => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
			$out = self::head(self::MAJOR_MAP, count($keys));
			foreach ($keys as $key) {
				$out .= self::encodeValue($key, $depth + 1) . self::encodeValue($value[$key], $depth + 1);
			}

			return $out;
		}

		throw new InvalidArgumentException('No CBOR encoding for ' . get_debug_type($value));
	}

	private static function head(int $major, int $argument): string {
		$type = $major << 5;
		if ($argument < 24) {
			return chr($type | $argument);
		}
		if ($argument <= 0xff) {
			return chr($type | 24) . chr($argument);
		}
		if ($argument <= 0xffff) {
			return chr($type | 25) . pack('n', $argument);
		}
		if ($argument <= 0xffffffff) {
			return chr($type | 26) . pack('N', $argument);
		}

		return chr($type | 27) . pack('J', $argument);
	}

	private static function decodeValue(string $bytes, int &$offset, int $depth): mixed {
		if ($depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('CBOR value nested too deeply');
		}
		$initial = self::byte($bytes, $offset);
		$major = $initial >> 5;
		$info = $initial & 0x1f;

		if ($major === self::MAJOR_SIMPLE) {
			return match ($initial) {
				0xf4 => false,
				0xf5 => true,
				0xf6 => null,
				default => throw new InvalidArgumentException('CBOR simple value or float not allowed: ' . $initial),
			};
		}

		$argument = self::argument($bytes, $offset, $info);
		switch ($major) {
			case self::MAJOR_UINT:
				return $argument;
			case self::MAJOR_NEGINT:
				return -1 - $argument;
			case self::MAJOR_BYTES:
				return new Bytes(self::take($bytes, $offset, $argument));
			case self::MAJOR_TEXT:
				$text = self::take($bytes, $offset, $argument);
				if (!mb_check_encoding($text, 'UTF-8')) {
					throw new InvalidArgumentException('CBOR text is not UTF-8');
				}

				return $text;
			case self::MAJOR_ARRAY:
				$items = [];
				for ($i = 0; $i < $argument; $i++) {
					$items[] = self::decodeValue($bytes, $offset, $depth + 1);
				}

				return $items;
			case self::MAJOR_MAP:
				$map = [];
				for ($i = 0; $i < $argument; $i++) {
					$key = self::decodeValue($bytes, $offset, $depth + 1);
					if (!is_string($key)) {
						throw new InvalidArgumentException('CBOR map keys must be text');
					}
					if (array_key_exists($key, $map)) {
						throw new InvalidArgumentException('CBOR map repeats a key');
					}
					$map[$key] = self::decodeValue($bytes, $offset, $depth + 1);
				}

				return $map;
			case self::MAJOR_TAG:
				if ($argument !== self::TAG_CID) {
					throw new InvalidArgumentException('CBOR tag not allowed: ' . $argument);
				}
				$link = self::decodeValue($bytes, $offset, $depth + 1);
				if (!$link instanceof Bytes || !str_starts_with($link->value, "\0")) {
					throw new InvalidArgumentException('A CID link must be a byte string with a leading zero');
				}

				return Cid::fromBytes(substr($link->value, 1));
		}

		throw new InvalidArgumentException('Unreachable CBOR major type');
	}

	private static function argument(string $bytes, int &$offset, int $info): int {
		if ($info < 24) {
			return $info;
		}
		$value = match ($info) {
			24 => self::byte($bytes, $offset),
			25 => unpack('n', self::take($bytes, $offset, 2))[1],
			26 => unpack('N', self::take($bytes, $offset, 4))[1],
			27 => unpack('J', self::take($bytes, $offset, 8))[1],
			default => throw new InvalidArgumentException('CBOR indefinite or reserved length'),
		};
		if ($value < 0) {
			throw new InvalidArgumentException('CBOR integer beyond 63 bits');
		}
		$minimal = match (true) {
			$value < 24 => 0,
			$value <= 0xff => 24,
			$value <= 0xffff => 25,
			$value <= 0xffffffff => 26,
			default => 27,
		};
		if ($minimal !== $info) {
			throw new InvalidArgumentException('CBOR integer is not minimally encoded');
		}

		return $value;
	}

	private static function byte(string $bytes, int &$offset): int {
		if ($offset >= strlen($bytes)) {
			throw new InvalidArgumentException('CBOR ends early');
		}

		return ord($bytes[$offset++]);
	}

	private static function take(string $bytes, int &$offset, int $length): string {
		if ($length < 0 || $offset + $length > strlen($bytes)) {
			throw new InvalidArgumentException('CBOR ends early');
		}
		$slice = substr($bytes, $offset, $length);
		$offset += $length;

		return $slice;
	}
}
