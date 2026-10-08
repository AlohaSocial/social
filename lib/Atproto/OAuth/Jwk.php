<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Protocol\Encoding;

/**
 * Public keys as JSON Web Keys (RFC 7517), as a DPoP proof carries its key
 * and a confidential client publishes its own: elliptic curve keys on P-256
 * or secp256k1, the two curves AT Protocol knows.
 */
final class Jwk {
	private const CURVES = ['P-256' => Curve::P256, 'secp256k1' => Curve::K256];

	/**
	 * @throws InvalidArgumentException when it is not a public EC key on a known curve
	 */
	public static function publicKey(array $jwk): PublicKey {
		if (isset($jwk['d'])) {
			throw new InvalidArgumentException('A private key was sent');
		}
		$curve = self::CURVES[(string)($jwk['crv'] ?? '')] ?? null;
		if (($jwk['kty'] ?? '') !== 'EC' || $curve === null || !is_string($jwk['x'] ?? null) || !is_string($jwk['y'] ?? null)) {
			throw new InvalidArgumentException('Not an EC public key on P-256 or secp256k1');
		}
		$x = Encoding::base64UrlDecode($jwk['x']);
		$y = Encoding::base64UrlDecode($jwk['y']);
		if (strlen($x) !== 32 || strlen($y) !== 32) {
			throw new InvalidArgumentException('Coordinates must be 32 bytes');
		}
		$key = new PublicKey($curve, $x, $y);
		if (!$key->isOnCurve()) {
			throw new InvalidArgumentException('The point is not on the curve');
		}

		return $key;
	}

	/**
	 * The key's thumbprint (RFC 7638): what a token is bound to.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function thumbprint(array $jwk): string {
		self::publicKey($jwk);
		$canonical = '{"crv":' . json_encode((string)$jwk['crv']) . ',"kty":"EC","x":' . json_encode((string)$jwk['x']) . ',"y":' . json_encode((string)$jwk['y']) . '}';

		return Encoding::base64UrlEncode(hash('sha256', $canonical, true));
	}

	/** The JOSE algorithm a key signs with. */
	public static function algorithm(PublicKey $key): string {
		return $key->curve->jwtAlgorithm();
	}
}
