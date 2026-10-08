<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use OCA\Social\Service\CurlService;
use OCP\ICache;
use OCP\ICacheFactory;
use Throwable;

/**
 * The client metadata of a Bluesky app (the client ID metadata document
 * AT Protocol's OAuth profile requires): fetched from the `client_id`,
 * which is its URL, checked against what the profile requires, and kept a
 * few minutes.
 *
 * A `client_id` of `http://localhost` is the profile's development
 * exception: no document is fetched, the redirect addresses and scope come
 * from its query, and it is a public client.
 */
class ClientMetadataService {
	/** how long a fetched document is kept */
	private const CACHE_SECONDS = 300;
	private const LOCALHOST_REDIRECTS = ['http://127.0.0.1/', 'http://[::1]/'];

	private ?ICache $cache = null;

	public function __construct(
		private CurlService $curl,
		ICacheFactory $cacheFactory,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed('social.atproto.oauth.client');
		}
	}

	/**
	 * @param bool $fresh whether to fetch it again, as a refresh of a
	 *                    confidential client's session must, to see its keys
	 * @return array the metadata, checked
	 * @throws OAuthException `invalid_client` when it cannot be had or is not good
	 */
	public function get(string $clientId, bool $fresh = false): array {
		if (self::isLocalhost($clientId)) {
			return self::localhost($clientId);
		}
		self::assertClientId($clientId);
		$key = hash('sha256', $clientId);
		if (!$fresh && is_array($cached = $this->cache?->get($key))) {
			return $cached;
		}
		try {
			$status = 0;
			$type = '';
			$metadata = $this->curl->retrieveJson('get', $clientId, [
				'headers' => ['Accept' => 'application/json'],
				'json_headers' => false,
				'accept_errors' => true,
				'timeout' => 10,
			], $status, $type);
		} catch (Throwable $e) {
			throw OAuthException::invalidClient('The client metadata could not be fetched: ' . $e->getMessage());
		}
		if ($status !== 200 || !str_starts_with(strtolower($type), 'application/json')) {
			throw OAuthException::invalidClient('The client metadata must be answered with 200 and application/json');
		}
		$metadata = self::checked($clientId, $metadata);
		$this->cache?->set($key, $metadata, self::CACHE_SECONDS);

		return $metadata;
	}

	/**
	 * Whether a redirect address is one the client declared, by the rules of
	 * its kind: exact for a web client, a loopback address whatever its port
	 * for the localhost exception.
	 */
	public static function allowsRedirect(array $metadata, string $redirectUri): bool {
		if (self::isLocalhost((string)$metadata['client_id'])) {
			$asked = parse_url($redirectUri);
			foreach ($metadata['redirect_uris'] as $declared) {
				$allowed = parse_url($declared);
				if (is_array($asked) && is_array($allowed) && ($asked['scheme'] ?? '') === 'http'
					&& ($asked['host'] ?? '') === ($allowed['host'] ?? '') && ($asked['path'] ?? '/') === ($allowed['path'] ?? '/')) {
					return true;
				}
			}

			return false;
		}

		return in_array($redirectUri, $metadata['redirect_uris'], true);
	}

	/** Whether the client authenticates with a key (`private_key_jwt`). */
	public static function isConfidential(array $metadata): bool {
		return ($metadata['token_endpoint_auth_method'] ?? 'none') === 'private_key_jwt';
	}

	public static function isLocalhost(string $clientId): bool {
		$url = parse_url($clientId);

		return is_array($url) && ($url['scheme'] ?? '') === 'http' && ($url['host'] ?? '') === 'localhost'
			&& !isset($url['port']) && in_array($url['path'] ?? '', ['', '/'], true);
	}

	/**
	 * @throws OAuthException
	 */
	private static function assertClientId(string $clientId): void {
		$url = parse_url($clientId);
		if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') === '' || isset($url['port'])
			|| isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || strlen($clientId) > 2048) {
			throw OAuthException::invalidClient('The client_id must be an https URL without a port, credentials or fragment');
		}
	}

	/**
	 * @throws OAuthException
	 */
	private static function checked(string $clientId, array $metadata): array {
		$fail = static fn (string $why) => throw OAuthException::invalidClient('Client metadata: ' . $why);
		if (($metadata['client_id'] ?? null) !== $clientId) {
			$fail('client_id must be the address it was fetched from');
		}
		$applicationType = $metadata['application_type'] ?? 'web';
		if (!in_array($applicationType, ['web', 'native'], true)) {
			$fail('application_type must be web or native');
		}
		foreach (['grant_types' => 'authorization_code', 'response_types' => 'code'] as $field => $required) {
			if (!is_array($metadata[$field] ?? null) || !in_array($required, $metadata[$field], true)) {
				$fail($field . ' must include ' . $required);
			}
		}
		if (!in_array('atproto', explode(' ', (string)($metadata['scope'] ?? '')), true)) {
			$fail('scope must include atproto');
		}
		if (($metadata['dpop_bound_access_tokens'] ?? false) !== true) {
			$fail('dpop_bound_access_tokens must be true');
		}
		$method = $metadata['token_endpoint_auth_method'] ?? 'none';
		if (!in_array($method, ['none', 'private_key_jwt'], true)) {
			$fail('token_endpoint_auth_method must be none or private_key_jwt');
		}
		if ($method === 'private_key_jwt' && (isset($metadata['jwks']) === isset($metadata['jwks_uri']))) {
			$fail('a confidential client publishes either jwks or jwks_uri');
		}
		if (isset($metadata['jwks_uri']) && !str_starts_with((string)$metadata['jwks_uri'], 'https://')) {
			$fail('jwks_uri must be https');
		}
		$uris = $metadata['redirect_uris'] ?? null;
		if (!is_array($uris) || $uris === []) {
			$fail('redirect_uris must name at least one address');
		}
		$host = (string)parse_url($clientId, PHP_URL_HOST);
		foreach ($uris as $uri) {
			if (!is_string($uri) || !self::redirectFits($uri, $applicationType, $host)) {
				$fail('redirect_uri ' . (is_string($uri) ? $uri : '?') . ' is not allowed for a ' . $applicationType . ' client');
			}
		}

		return $metadata;
	}

	/**
	 * A web client's redirect is https; a native client's is a custom scheme
	 * of its host in reverse order (`com.example.app:/callback`), or https on
	 * the client's own host.
	 */
	private static function redirectFits(string $uri, string $applicationType, string $host): bool {
		$url = parse_url($uri);
		if (!is_array($url) || isset($url['fragment'])) {
			return false;
		}
		$scheme = strtolower($url['scheme'] ?? '');
		if ($scheme === 'https') {
			return ($url['host'] ?? '') !== '' && ($applicationType === 'web' || strcasecmp($url['host'], $host) === 0);
		}
		if ($applicationType !== 'native') {
			return false;
		}
		$reversed = implode('.', array_reverse(explode('.', strtolower($host))));

		return ($scheme === $reversed || str_starts_with($scheme, $reversed . '.'))
			&& preg_match('#^[a-z0-9.+-]+:/[^/]#i', $uri) === 1;
	}

	/**
	 * The virtual document of the localhost exception.
	 *
	 * @throws OAuthException
	 */
	private static function localhost(string $clientId): array {
		$redirects = [];
		$scope = 'atproto';
		$query = (string)parse_url($clientId, PHP_URL_QUERY);
		foreach (explode('&', $query) as $pair) {
			if ($pair === '') {
				continue;
			}
			[$name, $value] = array_map('urldecode', explode('=', $pair, 2) + [1 => '']);
			if ($name === 'redirect_uri') {
				$host = (string)parse_url($value, PHP_URL_HOST);
				if (!str_starts_with($value, 'http://') || !in_array($host, ['127.0.0.1', '[::1]'], true)) {
					throw OAuthException::invalidClient('A localhost client redirects to 127.0.0.1 or [::1] over http');
				}
				$redirects[] = $value;
			} elseif ($name === 'scope') {
				$scope = $value;
			}
		}

		return [
			'client_id' => $clientId,
			'client_name' => 'Development client',
			'redirect_uris' => $redirects === [] ? self::LOCALHOST_REDIRECTS : $redirects,
			'scope' => $scope,
			'response_types' => ['code'],
			'grant_types' => ['authorization_code', 'refresh_token'],
			'token_endpoint_auth_method' => 'none',
			'application_type' => 'native',
			'dpop_bound_access_tokens' => true,
		];
	}
}
