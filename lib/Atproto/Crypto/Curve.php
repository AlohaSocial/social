<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Crypto;

use InvalidArgumentException;

/**
 * The two curves AT Protocol allows and the constants this app needs of
 * them: the OpenSSL name, the did:key multicodec prefix, the JWT algorithm,
 * the field prime and the group order for low-S and point decompression.
 */
enum Curve: string {
	case K256 = 'k256';
	case P256 = 'p256';

	public function opensslName(): string {
		return match ($this) {
			self::K256 => 'secp256k1',
			self::P256 => 'prime256v1',
		};
	}

	/** the multicodec varint that precedes the compressed point in a did:key */
	public function didKeyPrefix(): string {
		return match ($this) {
			self::K256 => "\xe7\x01",
			self::P256 => "\x80\x24",
		};
	}

	public function jwtAlgorithm(): string {
		return match ($this) {
			self::K256 => 'ES256K',
			self::P256 => 'ES256',
		};
	}

	public static function fromDidKeyPrefix(string $prefixed): self {
		foreach (self::cases() as $curve) {
			if (str_starts_with($prefixed, $curve->didKeyPrefix())) {
				return $curve;
			}
		}

		throw new InvalidArgumentException('Not a k256 or p256 did:key');
	}

	public static function fromJwtAlgorithm(string $algorithm): self {
		foreach (self::cases() as $curve) {
			if ($curve->jwtAlgorithm() === $algorithm) {
				return $curve;
			}
		}

		throw new InvalidArgumentException('Unsupported JWT algorithm: ' . $algorithm);
	}

	/** the prime of the field */
	public function fieldPrime(): BigNum {
		return BigNum::fromHex(match ($this) {
			self::K256 => 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f',
			self::P256 => 'ffffffff00000001000000000000000000000000ffffffffffffffffffffffff',
		});
	}

	/** the order of the base point */
	public function order(): BigNum {
		return BigNum::fromHex(match ($this) {
			self::K256 => 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141',
			self::P256 => 'ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551',
		});
	}

	/** floor(n / 2): the largest s a low-S signature may carry */
	public function halfOrder(): BigNum {
		return BigNum::fromHex(match ($this) {
			self::K256 => '7fffffffffffffffffffffffffffffff5d576e7357a4501ddfe92f46681b20a0',
			self::P256 => '7fffffff800000007fffffffffffffffde737d56d38bcf4279dce5617e3192a8',
		});
	}

	/** y² = x³ + ax + b */
	public function a(): BigNum {
		return match ($this) {
			self::K256 => BigNum::zero(),
			self::P256 => BigNum::fromHex('ffffffff00000001000000000000000000000000fffffffffffffffffffffffc'),
		};
	}

	public function b(): BigNum {
		return BigNum::fromHex(match ($this) {
			self::K256 => '0000000000000000000000000000000000000000000000000000000000000007',
			self::P256 => '5ac635d8aa3a93e7b3ebbd55769886bc651d06b0cc53b0f63bce3c3e27d2604b',
		});
	}
}
