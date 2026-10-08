<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Crypto;

use InvalidArgumentException;
use OCA\Social\Atproto\Protocol\Encoding;
use OpenSSLAsymmetricKey;

/**
 * A public key on one of the two curves: what a did:key names, and what a
 * signature is checked against. Verification is OpenSSL's; this class
 * converts between the protocol's forms (compressed point, raw 64-byte
 * signature, low-S) and OpenSSL's (affine coordinates, DER).
 */
final class PublicKey {
	private ?OpenSSLAsymmetricKey $handle = null;

	/**
	 * @param string $x 32 bytes
	 * @param string $y 32 bytes
	 */
	public function __construct(
		public readonly Curve $curve,
		public readonly string $x,
		public readonly string $y,
	) {
		if (strlen($x) !== 32 || strlen($y) !== 32) {
			throw new InvalidArgumentException('Coordinates must be 32 bytes');
		}
	}

	/**
	 * @throws InvalidArgumentException when it is not a k256 or p256 did:key, or not a point on the curve
	 */
	public static function fromDidKey(string $didKey): self {
		if (!str_starts_with($didKey, 'did:key:z')) {
			throw new InvalidArgumentException('Not a did:key');
		}
		$prefixed = Encoding::base58Decode(substr($didKey, 9));
		$curve = Curve::fromDidKeyPrefix($prefixed);

		return self::fromCompressed($curve, substr($prefixed, 2));
	}

	/**
	 * @param string $point 33 bytes: 0x02 or 0x03 and x
	 * @throws InvalidArgumentException
	 */
	public static function fromCompressed(Curve $curve, string $point): self {
		if (strlen($point) !== 33 || ($point[0] !== "\x02" && $point[0] !== "\x03")) {
			throw new InvalidArgumentException('Not a compressed point');
		}
		$p = $curve->fieldPrime();
		$x = BigNum::fromBytes(substr($point, 1));
		if ($x->compare($p) >= 0) {
			throw new InvalidArgumentException('x is not in the field');
		}
		// y² = x³ + ax + b; p ≡ 3 (mod 4) on both curves, so y = (y²)^((p+1)/4)
		$ySquared = $x->modMultiply($x, $p)->modMultiply($x, $p)
			->add($curve->a()->modMultiply($x, $p))
			->add($curve->b())
			->mod($p);
		$y = $ySquared->modPow(self::quarter($p), $p);
		if ($y->modMultiply($y, $p)->compare($ySquared) !== 0) {
			throw new InvalidArgumentException('Point is not on the curve');
		}
		if ($y->isOdd() !== ($point[0] === "\x03")) {
			$y = $p->subtract($y);
		}

		return new self($curve, $x->toBytes(32), $y->toBytes(32));
	}

	/**
	 * @param string $point 65 bytes: 0x04, x, y
	 */
	public static function fromUncompressed(Curve $curve, string $point): self {
		if (strlen($point) !== 65 || $point[0] !== "\x04") {
			throw new InvalidArgumentException('Not an uncompressed point');
		}

		return new self($curve, substr($point, 1, 32), substr($point, 33, 32));
	}

	public function compressed(): string {
		return (BigNum::fromBytes($this->y)->isOdd() ? "\x03" : "\x02") . $this->x;
	}

	public function didKey(): string {
		return 'did:key:z' . Encoding::base58Encode($this->curve->didKeyPrefix() . $this->compressed());
	}

	/** the `publicKeyMultibase` of a DID document's Multikey */
	public function multibase(): string {
		return 'z' . Encoding::base58Encode($this->curve->didKeyPrefix() . $this->compressed());
	}

	/**
	 * Whether $signature (raw r || s, 64 bytes, low-S) signs SHA-256 of $message.
	 */
	public function verify(string $message, string $signature): bool {
		if (strlen($signature) !== 64) {
			return false;
		}
		$r = BigNum::fromBytes(substr($signature, 0, 32));
		$s = BigNum::fromBytes(substr($signature, 32));
		if ($r->isZero() || $s->isZero() || $s->compare($this->curve->halfOrder()) > 0 || $r->compare($this->curve->order()) >= 0) {
			return false;
		}
		$der = Der::sequence(Der::unsignedInteger($r) . Der::unsignedInteger($s));

		return openssl_verify($message, $der, $this->opensslKey(), OPENSSL_ALGO_SHA256) === 1;
	}

	private function opensslKey(): OpenSSLAsymmetricKey {
		if ($this->handle === null) {
			$key = openssl_pkey_new(['ec' => ['curve_name' => $this->curve->opensslName(), 'x' => $this->x, 'y' => $this->y]]);
			if ($key === false) {
				throw new InvalidArgumentException('OpenSSL rejected the public key: ' . (string)openssl_error_string());
			}
			$this->handle = $key;
		}

		return $this->handle;
	}

	private static function quarter(BigNum $p): BigNum {
		// (p + 1) / 4, as a shift of the limb representation would be: p + 1 is
		// divisible by 4 on both curves, so this is an exact division
		$pPlusOne = $p->add(BigNum::fromInt(1));
		$bytes = $pPlusOne->toBytes(33);
		$out = '';
		$carry = 0;
		for ($i = 0; $i < 33; $i++) {
			$value = ($carry << 8) | ord($bytes[$i]);
			$out .= chr($value >> 2);
			$carry = $value & 3;
		}

		return BigNum::fromBytes($out);
	}
}
