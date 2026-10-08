<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use InvalidArgumentException;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Who a client is, on each request it makes to the authorization server.
 *
 * A public client is its `client_id` and nothing more. A confidential one
 * signs a client assertion (RFC 7523) with a key it publishes in its
 * metadata; the key it used — id, algorithm, thumbprint — is what the
 * session is bound to, and a refresh must be made with the same key, still
 * published.
 */
class ClientAuthenticator {
	public const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
	private const MAX_AGE = 300;

	public function __construct(
		private AtprotoOAuthRequest $request,
		private CurlService $curl,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array $metadata the client's, checked
	 * @param array<string, mixed> $params the request's form fields
	 * @param string $issuer this authorization server, the assertion's audience
	 * @return array{method: string, kid?: string, alg?: string, jkt?: string}
	 * @throws OAuthException `invalid_client`
	 */
	public function authenticate(array $metadata, array $params, string $issuer): array {
		$assertion = (string)($params['client_assertion'] ?? '');
		if (!ClientMetadataService::isConfidential($metadata)) {
			if ($assertion !== '' || isset($params['client_secret'])) {
				throw OAuthException::invalidClient('This client is public and does not authenticate');
			}

			return ['method' => 'none'];
		}
		if (($params['client_assertion_type'] ?? '') !== self::ASSERTION_TYPE || $assertion === '') {
			throw OAuthException::invalidClient('A confidential client authenticates with a JWT client assertion');
		}
		try {
			$jws = Jose::decode($assertion);
		} catch (InvalidArgumentException) {
			throw OAuthException::invalidClient('The client assertion is not a JWT');
		}
		$kid = (string)($jws['header']['kid'] ?? '');
		$algorithm = (string)($jws['header']['alg'] ?? '');
		$signedBy = null;
		foreach ($this->keys($metadata) as $jwk) {
			if (($kid !== '' && ($jwk['kid'] ?? '') !== $kid) || (isset($jwk['alg']) && $jwk['alg'] !== $algorithm)) {
				continue;
			}
			try {
				if (Jose::verify($jws, Jwk::publicKey($jwk))) {
					$signedBy = $jwk;
					break;
				}
			} catch (InvalidArgumentException) {
			}
		}
		if ($signedBy === null) {
			throw OAuthException::invalidClient('The client assertion is not signed by a key of the client');
		}
		$claims = $jws['claims'];
		$clientId = (string)$metadata['client_id'];
		$now = $this->time->getTime();
		$iat = is_int($claims['iat'] ?? null) ? $claims['iat'] : 0;
		$audience = is_array($claims['aud'] ?? null) ? $claims['aud'] : [(string)($claims['aud'] ?? '')];
		if (($claims['iss'] ?? '') !== $clientId || ($claims['sub'] ?? '') !== $clientId || !in_array($issuer, $audience, true)) {
			throw OAuthException::invalidClient('The client assertion is not this client\'s for this server');
		}
		if ($iat < $now - self::MAX_AGE || $iat > $now + 60 || (isset($claims['exp']) && (int)$claims['exp'] < $now)) {
			throw OAuthException::invalidClient('The client assertion is not fresh');
		}
		$jti = (string)($claims['jti'] ?? '');
		if ($jti === '' || !$this->request->firstUse(hash('sha256', 'assertion|' . $clientId . '|' . $jti), $now + 2 * self::MAX_AGE)) {
			throw OAuthException::invalidClient('The client assertion has no id or was used before');
		}

		return ['method' => 'private_key_jwt', 'kid' => $kid, 'alg' => $algorithm, 'jkt' => Jwk::thumbprint($signedBy)];
	}

	/**
	 * Whether a refresh authenticated the way the session started.
	 *
	 * @param array{method: string, kid?: string, alg?: string, jkt?: string} $bound
	 * @param array{method: string, kid?: string, alg?: string, jkt?: string} $now
	 */
	public static function same(array $bound, array $now): bool {
		return ($bound['method'] ?? '') === ($now['method'] ?? '')
			&& ($bound['kid'] ?? '') === ($now['kid'] ?? '')
			&& ($bound['alg'] ?? '') === ($now['alg'] ?? '')
			&& ($bound['jkt'] ?? '') === ($now['jkt'] ?? '');
	}

	/**
	 * @return list<array> the client's published keys
	 * @throws OAuthException
	 */
	private function keys(array $metadata): array {
		$jwks = $metadata['jwks'] ?? null;
		if (isset($metadata['jwks_uri'])) {
			try {
				$jwks = $this->curl->retrieveJson('get', (string)$metadata['jwks_uri'], [
					'headers' => ['Accept' => 'application/json'],
					'json_headers' => false,
					'timeout' => 10,
				]);
			} catch (Throwable $e) {
				throw OAuthException::invalidClient('The client keys could not be fetched: ' . $e->getMessage());
			}
		}
		$keys = [];
		foreach (is_array($jwks) && is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [] as $key) {
			if (is_array($key)) {
				$keys[] = $key;
			}
		}

		return $keys;
	}
}
