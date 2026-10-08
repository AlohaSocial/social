<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Protocol\Encoding;

/**
 * Compact JSON Web Signatures (RFC 7515), read and checked: the DPoP
 * proofs and client assertions of OAuth. Only ES256 and ES256K are taken,
 * and a key signs with its own curve's algorithm only.
 */
final class Jose {
	public const ALGORITHMS = ['ES256', 'ES256K'];

	/**
	 * @return array{header: array, claims: array, input: string, signature: string}
	 * @throws InvalidArgumentException when it is not a compact JWS with JSON parts
	 */
	public static function decode(string $jws): array {
		$parts = explode('.', $jws);
		if (count($parts) !== 3 || strlen($jws) > 8192) {
			throw new InvalidArgumentException('Not a compact JWS');
		}
		foreach ($parts as $part) {
			if (preg_match('/^[A-Za-z0-9_-]+$/', $part) !== 1) {
				throw new InvalidArgumentException('Not base64url');
			}
		}
		$header = json_decode(Encoding::base64UrlDecode($parts[0]), true);
		$claims = json_decode(Encoding::base64UrlDecode($parts[1]), true);
		if (!is_array($header) || !is_array($claims)) {
			throw new InvalidArgumentException('Not JSON');
		}

		return ['header' => $header, 'claims' => $claims, 'input' => $parts[0] . '.' . $parts[1], 'signature' => Encoding::base64UrlDecode($parts[2])];
	}

	/**
	 * Whether a decoded JWS is signed by a key, with that key's algorithm.
	 */
	public static function verify(array $decoded, PublicKey $key): bool {
		$algorithm = (string)($decoded['header']['alg'] ?? '');

		return in_array($algorithm, self::ALGORITHMS, true)
			&& $algorithm === $key->curve->jwtAlgorithm()
			&& $key->verify($decoded['input'], $decoded['signature'], false);
	}
}
