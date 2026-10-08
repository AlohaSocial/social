<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Crypto;

use InvalidArgumentException;

/**
 * Unsigned integers of a few hundred bits, in 16-bit limbs, for the little
 * arithmetic the curves need that OpenSSL does not expose: comparing a
 * signature's s with n/2, computing n - s, and recovering y from x for a
 * compressed point. Neither gmp nor bcmath is required of the host.
 *
 * Values are immutable; limbs are little-endian with no leading zero limbs.
 */
final class BigNum {
	private const BASE = 0x10000;

	/**
	 * @param int[] $limbs
	 */
	private function __construct(
		private readonly array $limbs,
	) {
	}

	public static function zero(): self {
		return new self([]);
	}

	public static function fromInt(int $value): self {
		if ($value < 0) {
			throw new InvalidArgumentException('BigNum is unsigned');
		}
		$limbs = [];
		while ($value > 0) {
			$limbs[] = $value & 0xffff;
			$value >>= 16;
		}

		return new self($limbs);
	}

	public static function fromBytes(string $bytes): self {
		$limbs = [];
		for ($i = strlen($bytes); $i > 0; $i -= 2) {
			$limbs[] = $i >= 2
				? (ord($bytes[$i - 2]) << 8) | ord($bytes[$i - 1])
				: ord($bytes[0]);
		}

		return new self(self::trim($limbs));
	}

	public static function fromHex(string $hex): self {
		return self::fromBytes((string)hex2bin(str_pad($hex, strlen($hex) + strlen($hex) % 2, '0', STR_PAD_LEFT)));
	}

	/**
	 * Big-endian, left-padded with zeros to $length bytes.
	 */
	public function toBytes(int $length): string {
		$out = '';
		foreach ($this->limbs as $limb) {
			$out = chr($limb >> 8) . chr($limb & 0xff) . $out;
		}
		$out = ltrim($out, "\0");
		if (strlen($out) > $length) {
			throw new InvalidArgumentException('Value does not fit in ' . $length . ' bytes');
		}

		return str_pad($out, $length, "\0", STR_PAD_LEFT);
	}

	public function isZero(): bool {
		return $this->limbs === [];
	}

	public function isOdd(): bool {
		return ($this->limbs[0] ?? 0) & 1 ? true : false;
	}

	public function compare(BigNum $other): int {
		$a = $this->limbs;
		$b = $other->limbs;
		if (count($a) !== count($b)) {
			return count($a) <=> count($b);
		}
		for ($i = count($a) - 1; $i >= 0; $i--) {
			if ($a[$i] !== $b[$i]) {
				return $a[$i] <=> $b[$i];
			}
		}

		return 0;
	}

	public function add(BigNum $other): self {
		$a = $this->limbs;
		$b = $other->limbs;
		$n = max(count($a), count($b));
		$out = [];
		$carry = 0;
		for ($i = 0; $i < $n; $i++) {
			$sum = ($a[$i] ?? 0) + ($b[$i] ?? 0) + $carry;
			$out[] = $sum & 0xffff;
			$carry = $sum >> 16;
		}
		if ($carry > 0) {
			$out[] = $carry;
		}

		return new self($out);
	}

	/**
	 * @throws InvalidArgumentException when the result would be negative
	 */
	public function subtract(BigNum $other): self {
		if ($this->compare($other) < 0) {
			throw new InvalidArgumentException('BigNum subtraction below zero');
		}
		$a = $this->limbs;
		$b = $other->limbs;
		$out = [];
		$borrow = 0;
		foreach ($a as $i => $limb) {
			$diff = $limb - ($b[$i] ?? 0) - $borrow;
			if ($diff < 0) {
				$diff += self::BASE;
				$borrow = 1;
			} else {
				$borrow = 0;
			}
			$out[] = $diff;
		}

		return new self(self::trim($out));
	}

	public function multiply(BigNum $other): self {
		$a = $this->limbs;
		$b = $other->limbs;
		if ($a === [] || $b === []) {
			return self::zero();
		}
		$out = array_fill(0, count($a) + count($b), 0);
		$na = count($a);
		$nb = count($b);
		for ($i = 0; $i < $na; $i++) {
			$carry = 0;
			$x = $a[$i];
			for ($j = 0; $j < $nb; $j++) {
				$product = $out[$i + $j] + $x * $b[$j] + $carry;
				$out[$i + $j] = $product & 0xffff;
				$carry = $product >> 16;
			}
			$k = $i + $nb;
			while ($carry > 0) {
				$product = $out[$k] + $carry;
				$out[$k] = $product & 0xffff;
				$carry = $product >> 16;
				$k++;
			}
		}

		return new self(self::trim($out));
	}

	/**
	 * The remainder of this divided by $modulus (Knuth's algorithm D).
	 */
	public function mod(BigNum $modulus): self {
		if ($modulus->isZero()) {
			throw new InvalidArgumentException('Modulus is zero');
		}
		if ($this->compare($modulus) < 0) {
			return $this;
		}
		if (count($modulus->limbs) === 1) {
			$remainder = 0;
			for ($i = count($this->limbs) - 1; $i >= 0; $i--) {
				$remainder = (($remainder << 16) | $this->limbs[$i]) % $modulus->limbs[0];
			}

			return self::fromInt($remainder);
		}

		// normalise so the divisor's top limb has its high bit set
		$shift = 0;
		$top = $modulus->limbs[count($modulus->limbs) - 1];
		while ($top < 0x8000) {
			$top <<= 1;
			$shift++;
		}
		$u = self::shiftLeftLimbs($this->limbs, $shift);
		$v = self::shiftLeftLimbs($modulus->limbs, $shift);
		$n = count($v);
		$u[] = 0;
		$m = count($u) - $n - 1;
		$vTop = $v[$n - 1];
		$vNext = $v[$n - 2];

		for ($j = $m; $j >= 0; $j--) {
			$numerator = ($u[$j + $n] << 16) | $u[$j + $n - 1];
			$qhat = intdiv($numerator, $vTop);
			$rhat = $numerator - $qhat * $vTop;
			while ($qhat >= self::BASE || $qhat * $vNext > (($rhat << 16) | $u[$j + $n - 2])) {
				$qhat--;
				$rhat += $vTop;
				if ($rhat >= self::BASE) {
					break;
				}
			}
			$borrow = 0;
			$carry = 0;
			for ($i = 0; $i < $n; $i++) {
				$product = $qhat * $v[$i] + $carry;
				$carry = $product >> 16;
				$diff = $u[$i + $j] - ($product & 0xffff) - $borrow;
				if ($diff < 0) {
					$diff += self::BASE;
					$borrow = 1;
				} else {
					$borrow = 0;
				}
				$u[$i + $j] = $diff;
			}
			$diff = $u[$j + $n] - $carry - $borrow;
			if ($diff < 0) {
				$u[$j + $n] = $diff + self::BASE;
				// qhat was one too large: add the divisor back
				$carry = 0;
				for ($i = 0; $i < $n; $i++) {
					$sum = $u[$i + $j] + $v[$i] + $carry;
					$u[$i + $j] = $sum & 0xffff;
					$carry = $sum >> 16;
				}
				$u[$j + $n] = ($u[$j + $n] + $carry) & 0xffff;
			} else {
				$u[$j + $n] = $diff;
			}
		}

		$remainder = array_slice($u, 0, $n);

		return new self(self::trim(self::shiftRightLimbs($remainder, $shift)));
	}

	public function modMultiply(BigNum $other, BigNum $modulus): self {
		return $this->multiply($other)->mod($modulus);
	}

	public function modPow(BigNum $exponent, BigNum $modulus): self {
		$result = self::fromInt(1)->mod($modulus);
		$base = $this->mod($modulus);
		for ($i = count($exponent->limbs) - 1; $i >= 0; $i--) {
			for ($bit = 15; $bit >= 0; $bit--) {
				$result = $result->modMultiply($result, $modulus);
				if (($exponent->limbs[$i] >> $bit) & 1) {
					$result = $result->modMultiply($base, $modulus);
				}
			}
		}

		return $result;
	}

	/**
	 * @param int[] $limbs
	 * @return int[]
	 */
	private static function trim(array $limbs): array {
		while ($limbs !== [] && $limbs[count($limbs) - 1] === 0) {
			array_pop($limbs);
		}

		return $limbs;
	}

	/**
	 * @param int[] $limbs
	 * @return int[]
	 */
	private static function shiftLeftLimbs(array $limbs, int $bits): array {
		if ($bits === 0) {
			return $limbs;
		}
		$out = [];
		$carry = 0;
		foreach ($limbs as $limb) {
			$value = ($limb << $bits) | $carry;
			$out[] = $value & 0xffff;
			$carry = $value >> 16;
		}
		if ($carry > 0) {
			$out[] = $carry;
		}

		return $out;
	}

	/**
	 * @param int[] $limbs
	 * @return int[]
	 */
	private static function shiftRightLimbs(array $limbs, int $bits): array {
		if ($bits === 0) {
			return $limbs;
		}
		$out = [];
		$carry = 0;
		for ($i = count($limbs) - 1; $i >= 0; $i--) {
			$value = ($carry << 16) | $limbs[$i];
			$out[$i] = $value >> $bits;
			$carry = $value & ((1 << $bits) - 1);
		}
		ksort($out);

		return array_values($out);
	}
}
