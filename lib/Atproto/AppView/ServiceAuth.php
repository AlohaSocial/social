<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\AppView;

use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Protocol\Encoding;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Inter-service authentication tokens: the JWT a PDS mints so an AppView
 * answers a request as one of its users. Signed with the user's signing
 * key, addressed to one service and valid for one method, for a minute
 * unless asked for longer, as `com.atproto.server.getServiceAuth` hands out.
 * The same tokens arrive here too, from an account another PDS holds.
 */
class ServiceAuth {
	public const LIFETIME = 60;

	public function __construct(
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param string $did the user, the token's issuer
	 * @param string $audience the service's DID
	 * @param string $method the lexicon method the token is good for
	 * @param int $lifetime seconds until it expires
	 */
	public function token(PrivateKey $key, string $did, string $audience, string $method, int $lifetime = self::LIFETIME): string {
		$now = $this->time->getTime();
		$header = Encoding::base64UrlEncode((string)json_encode(['typ' => 'JWT', 'alg' => $key->publicKey()->curve->jwtAlgorithm()]));
		$payload = Encoding::base64UrlEncode((string)json_encode([
			'iss' => $did,
			'aud' => $audience,
			'lxm' => $method,
			'jti' => bin2hex(random_bytes(16)),
			'iat' => $now,
			'exp' => $now + $lifetime,
		], JSON_UNESCAPED_SLASHES));
		$signature = $key->sign($header . '.' . $payload);

		return $header . '.' . $payload . '.' . Encoding::base64UrlEncode($signature);
	}

	/**
	 * Who a token says signed it, unchecked: the DID whose key to check it with.
	 */
	public function issuer(string $token): string {
		$claims = self::decode($token)[1] ?? [];

		return is_string($claims['iss'] ?? null) ? $claims['iss'] : '';
	}

	/**
	 * Whether a token is good: signed with $key, addressed to $audience,
	 * for $method, and unexpired. Either form of S is taken, as a JOSE
	 * signature may come in either.
	 */
	public function verify(string $token, PublicKey $key, string $audience, string $method): bool {
		$decoded = self::decode($token);
		if ($decoded === null) {
			return false;
		}
		[$header, $claims, $signed, $signature] = $decoded;
		if (($header['alg'] ?? '') !== $key->curve->jwtAlgorithm()
			|| ($claims['aud'] ?? '') !== $audience
			|| ($claims['lxm'] ?? '') !== $method
			|| (int)($claims['exp'] ?? 0) <= $this->time->getTime()) {
			return false;
		}

		return $key->verify($signed, $signature, false);
	}

	/**
	 * @return array{0: array, 1: array, 2: string, 3: string}|null header, claims, the signed part and the signature
	 */
	private static function decode(string $token): ?array {
		$parts = explode('.', trim($token));
		if (count($parts) !== 3) {
			return null;
		}
		try {
			$header = json_decode(Encoding::base64UrlDecode($parts[0]), true);
			$claims = json_decode(Encoding::base64UrlDecode($parts[1]), true);
			$signature = Encoding::base64UrlDecode($parts[2]);
		} catch (Throwable) {
			return null;
		}
		if (!is_array($header) || !is_array($claims)) {
			return null;
		}

		return [$header, $claims, $parts[0] . '.' . $parts[1], $signature];
	}
}
