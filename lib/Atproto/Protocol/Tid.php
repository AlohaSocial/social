<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Protocol;
final class Tid {
	private const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';
	private static int $last = 0;
	public static function next(?string $previous = null): string {
		$floor = $previous === null || $previous === '' ? 0 : self::decode($previous) >> 10;
		self::$last = max((int)(microtime(true) * 1000000), self::$last + 1, $floor + 1);
		return self::encode((self::$last << 10) | random_int(0, 1023));
	}
	public static function encode(int $value): string {
		if ($value < 0) { throw new \InvalidArgumentException('Invalid TID'); }
		$out = '';
		for ($i = 0; $i < 13; $i++) { $out = self::ALPHABET[$value & 31] . $out; $value >>= 5; }
		return $out;
	}
	public static function decode(string $value): int {
		if (!preg_match('/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/D', $value)) { throw new \InvalidArgumentException('Invalid TID'); }
		$out = 0;
		foreach (str_split($value) as $char) { $out = ($out << 5) | strpos(self::ALPHABET, $char); }
		return $out;
	}
}
