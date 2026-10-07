<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\Protocol;

/** Canonical DAG-CBOR with typed bytes/CID links and bounded decoding. */
final class DagCbor {
	public static function encode(mixed $value, int $depth = 0): string {
		if ($depth > 64) {
			throw new \InvalidArgumentException('CBOR nesting exceeds limit');
		}
		if ($value === null) {
			return "\xf6";
		}
		if (is_bool($value)) {
			return $value ? "\xf5" : "\xf4";
		}
		if (is_int($value)) {
			return self::head($value < 0 ? 1 : 0, $value < 0 ? -1 - $value : $value);
		}
		if ($value instanceof Bytes) {
			return self::head(2, strlen($value->value)) . $value->value;
		}
		if ($value instanceof Cid) {
			return "\xd8\x2a" . self::encode(new Bytes("\0" . Cid::decode($value->value)), $depth + 1);
		}
		if (is_string($value)) {
			if (!mb_check_encoding($value, 'UTF-8')) {
				throw new \InvalidArgumentException('CBOR text must be UTF-8');
			}
			return self::head(3, strlen($value)) . $value;
		}
		if (is_array($value) || $value instanceof \stdClass) {
			$list = is_array($value) && array_is_list($value);
			$items = (array)$value;
			if (!$list) {
				if (count($items) === 1 && isset($items['$bytes'])) {
					$bytes = base64_decode($items['$bytes'], true);
					if ($bytes === false) {
						throw new \InvalidArgumentException('Invalid base64 bytes');
					}
					return self::encode(new Bytes($bytes), $depth + 1);
				}
				if (count($items) === 1 && isset($items['$link'])) {
					return self::encode(new Cid($items['$link']), $depth + 1);
				}
				uksort($items, static fn ($a, $b) => strlen((string)$a) <=> strlen((string)$b) ?: strcmp((string)$a, (string)$b));
			}
			$out = self::head($list ? 4 : 5, count($items));
			foreach ($items as $key => $item) {
				if (!$list) {
					$out .= self::encode((string)$key, $depth + 1);
				}
				$out .= self::encode($item, $depth + 1);
			}
			return $out;
		}
		throw new \InvalidArgumentException('Unsupported CBOR value');
	}
	private static function head(int $major, int $n): string {
		if ($n < 24) {
			return chr(($major << 5) | $n);
		}
		if ($n <= 255) {
			return chr(($major << 5) | 24) . chr($n);
		}
		if ($n <= 65535) {
			return chr(($major << 5) | 25) . pack('n', $n);
		}
		if ($n <= 0xffffffff) {
			return chr(($major << 5) | 26) . pack('N', $n);
		}
		return chr(($major << 5) | 27) . pack('J', $n);
	}
	public static function decode(string $bytes): mixed {
		if (strlen($bytes) > 16 * 1024 * 1024) {
			throw new \UnexpectedValueException('CBOR size exceeds limit');
		}
		$offset = 0;
		$value = self::read($bytes, $offset, 0);
		if ($offset !== strlen($bytes)) {
			throw new \UnexpectedValueException('Trailing CBOR bytes');
		}
		return $value;
	}
	private static function take(string $bytes, int &$offset, int $n): string {
		if ($n < 0 || $n > strlen($bytes) - $offset) {
			throw new \UnexpectedValueException('Truncated CBOR');
		}
		$value = substr($bytes, $offset, $n);
		$offset += $n;
		return $value;
	}
	private static function read(string $bytes, int &$offset, int $depth): mixed {
		if ($depth > 64) {
			throw new \UnexpectedValueException('CBOR nesting exceeds limit');
		}
		$byte = ord(self::take($bytes, $offset, 1));
		$major = $byte >> 5;
		$info = $byte & 31;
		if ($major === 7) {
			return match ($info) {
				20 => false, 21 => true, 22 => null, default => throw new \UnexpectedValueException('Unsupported CBOR simple value')
			};
		}
		$n = match ($info) {
			24 => ord(self::take($bytes, $offset, 1)), 25 => unpack('n', self::take($bytes, $offset, 2))[1],
			26 => unpack('N', self::take($bytes, $offset, 4))[1], 27 => unpack('J', self::take($bytes, $offset, 8))[1],
			default => $info < 24 ? $info : throw new \UnexpectedValueException('Indefinite CBOR is forbidden'),
		};
		if (!is_int($n) || $n < 0) {
			throw new \UnexpectedValueException('CBOR integer overflow');
		}
		if ($info >= 24 && $n < match ($info) {
			24 => 24, 25 => 256, 26 => 65536, 27 => 0x100000000
		}) {
			throw new \UnexpectedValueException('Non-canonical CBOR integer');
		}
		if ($major === 0) {
			return $n;
		}
		if ($major === 1) {
			return -1 - $n;
		}
		if ($major === 2) {
			return new Bytes(self::take($bytes, $offset, $n));
		}
		if ($major === 3) {
			$text = self::take($bytes, $offset, $n);
			if (!mb_check_encoding($text, 'UTF-8')) {
				throw new \UnexpectedValueException('Invalid UTF-8');
			}
			return $text;
		}
		if ($major === 6 && $n === 42) {
			$link = self::read($bytes, $offset, $depth + 1);
			if (!$link instanceof Bytes || !str_starts_with($link->value, "\0")) {
				throw new \UnexpectedValueException('Invalid CID link');
			}
			return new Cid('b' . Cid::base32(substr($link->value, 1)));
		}
		if (!in_array($major, [4, 5], true) || $n > strlen($bytes) - $offset) {
			throw new \UnexpectedValueException('Invalid CBOR collection');
		}
		$items = [];
		$previous = null;
		for ($i = 0; $i < $n; $i++) {
			if ($major === 4) {
				$items[] = self::read($bytes, $offset, $depth + 1);
				continue;
			}
			$key = self::read($bytes, $offset, $depth + 1);
			if (!is_string($key) || ($previous !== null && (strlen($previous) > strlen($key) || (strlen($previous) === strlen($key) && strcmp($previous, $key) >= 0)))) {
				throw new \UnexpectedValueException('Non-canonical CBOR map');
			}
			$previous = $key;
			$items[$key] = self::read($bytes, $offset, $depth + 1);
		}
		return $major === 5 && $items === [] ? new \stdClass() : $items;
	}
	public static function jsonValue(mixed $value): mixed {
		if ($value instanceof Cid) {
			return ['$link' => $value->value];
		}
		if ($value instanceof Bytes) {
			return ['$bytes' => base64_encode($value->value)];
		}
		if (is_array($value)) {
			return array_map(self::jsonValue(...), $value);
		}
		return $value;
	}
}
