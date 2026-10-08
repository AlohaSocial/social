<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Protocol\Encoding;

/**
 * What a Bluesky app makes for OAuth, made here: a key as a JWK, a DPoP
 * proof, a client assertion, a PKCE pair.
 */
final class OAuthKit {
	public static function jwk(PrivateKey $key, array $extra = []): array {
		$public = $key->publicKey();

		return [
			'kty' => 'EC',
			'crv' => $public->curve->jwtAlgorithm() === 'ES256' ? 'P-256' : 'secp256k1',
			'x' => Encoding::base64UrlEncode($public->x),
			'y' => Encoding::base64UrlEncode($public->y),
		] + $extra;
	}

	public static function sign(PrivateKey $key, array $header, array $claims): string {
		$input = Encoding::base64UrlEncode((string)json_encode($header, JSON_UNESCAPED_SLASHES)) . '.' . Encoding::base64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

		return $input . '.' . Encoding::base64UrlEncode($key->sign($input));
	}

	public static function proof(PrivateKey $key, string $method, string $url, int $iat, string $nonce, string $accessToken = '', ?string $jti = null): string {
		$claims = ['jti' => $jti ?? bin2hex(random_bytes(8)), 'htm' => $method, 'htu' => $url, 'iat' => $iat, 'nonce' => $nonce];
		if ($accessToken !== '') {
			$claims['ath'] = Encoding::base64UrlEncode(hash('sha256', $accessToken, true));
		}

		return self::sign($key, ['typ' => 'dpop+jwt', 'alg' => $key->publicKey()->curve->jwtAlgorithm(), 'jwk' => self::jwk($key)], $claims);
	}

	/**
	 * @return array{0: string, 1: string} the verifier and its S256 challenge
	 */
	public static function pkce(): array {
		$verifier = Encoding::base64UrlEncode(random_bytes(32));

		return [$verifier, Encoding::base64UrlEncode(hash('sha256', $verifier, true))];
	}

	/** A good client metadata document of a public web client, changed as asked. */
	public static function clientDocument(array $override = []): array {
		return $override + [
			'client_id' => 'https://app.example.com/oauth-client-metadata.json',
			'client_name' => 'Example',
			'application_type' => 'web',
			'grant_types' => ['authorization_code', 'refresh_token'],
			'response_types' => ['code'],
			'scope' => 'atproto transition:generic',
			'redirect_uris' => ['https://app.example.com/callback'],
			'token_endpoint_auth_method' => 'none',
			'dpop_bound_access_tokens' => true,
		];
	}
}
