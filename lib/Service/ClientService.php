<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Tools\Traits\TStringTools;

/**
 * Class ClientService
 *
 * @package OCA\Social\Service
 */
class ClientService {
	public const TIME_TOKEN_REFRESH = 300; // 5m
	//	const TIME_TOKEN_TTL = 21600; // 6h
	//	const TIME_AUTH_TTL = 30672000; // 1y

	// looks like there is no token refresh. token must have been used in the last year.
	public const TIME_TOKEN_TTL = 30672000; // 1y

	// an authorization code is single-use plumbing; it expires quickly
	public const TIME_CODE_TTL = 600; // 10m

	/**
	 * The PKCE transformations this server accepts (RFC 7636 §4.2).
	 *
	 * `plain` is deliberately absent: it offers no protection against an
	 * intercepted authorization request, and the discovery document names
	 * exactly what is implemented here.
	 */
	public const CODE_CHALLENGE_METHODS = ['S256'];

	// RFC 7636 §4.1: the verifier is 43 to 128 unreserved characters
	private const CODE_VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

	use TStringTools;

	private ClientRequest $clientRequest;

	private SecretHasher $secretHasher;

	public function __construct(
		ClientRequest $clientRequest,
		SecretHasher $secretHasher,
		private ClientAuthRequest $clientAuthRequest,
	) {
		$this->clientRequest = $clientRequest;
		$this->secretHasher = $secretHasher;
	}

	/**
	 * @param SocialClient $client
	 *
	 * @throws ClientException
	 */
	public function createApp(SocialClient $client): void {
		if ($client->getAppName() === '') {
			throw new ClientException('missing client_name');
		}

		if (empty($client->getAppRedirectUris())) {
			throw new ClientException('missing redirect_uris');
		}

		$client->setAppClientId($this->token(40));
		$client->setAppClientSecret($this->token(40));

		$this->clientRequest->saveApp($client);
	}

	/**
	 * A client registration for a PeerTube app, made on the spot.
	 *
	 * PeerTube's `/oauth-clients/local` hands out **one** pair per instance,
	 * the same one to everybody, for ever. This app mints a pair per client
	 * and **hashes the secret** — deliberately, and as a fix to a real
	 * problem — so there is no stored plaintext to hand back a second time,
	 * and a route that promised the same pair every call would have to undo
	 * that.
	 *
	 * So it registers a fresh one each time and answers with its credentials.
	 * That satisfies what the route is actually for: a client fetches a pair
	 * immediately before logging in and uses it at once. Nothing in PeerTube's
	 * flow requires the pair to be the *same* one, only a working one — and a
	 * registration per call is exactly what a Mastodon client does through
	 * `/api/v1/apps`, which is what the rest of this API is built on.
	 *
	 * @throws ClientException
	 */
	public function registerPeerTubeClient(): SocialClient {
		$client = new SocialClient();
		$client->setAppName('PeerTube client')
			->setAppWebsite('https://joinpeertube.org')
			// the out-of-band urn, which is what a client with no callback of
			// its own uses and what `OAuthController` already understands
			->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob'])
			->setAppScopes(['read', 'write', 'follow']);

		$this->createApp($client);

		return $client;
	}

	/**
	 * Records that this account has authorized this app, and returns the code
	 * to hand back.
	 *
	 * One row per (app, account): an app registration used to hold a single
	 * authorization in its own row, so the second person to sign in with a
	 * client signed the first one out. Re-authorizing replaces that account's
	 * row and nobody else's.
	 */
	public function authClient(SocialClient $client): void {
		$client->setAuthCode($this->token(60));

		$this->clientAuthRequest->authorize(
			$client->getId(),
			$client->getAuthUserId(),
			$client->getAuthAccount(),
			$client->getAuthScopes(),
			$client->getAuthCode(),
			$client->getAuthCodeChallenge(),
			$client->getAuthCodeChallengeMethod()
		);
	}

	/**
	 * Exchanges an authorization code for a token.
	 *
	 * The code decides whose authorization this is, so what comes back is that
	 * account's — not whatever the app row last held.
	 *
	 * An authorization made with PKCE (RFC 7636) is only exchangeable against
	 * the verifier its challenge was derived from, so a code intercepted on
	 * the redirect — a custom scheme another app can claim, a proxy reading
	 * the query string — is of no use on its own.
	 *
	 * @throws ClientNotFoundException the code names no live authorization
	 * @throws ClientException it names one that has expired, or the verifier
	 *                         does not match the challenge it was bound to
	 */
	public function exchangeCode(SocialClient $client, string $code, string $codeVerifier = ''): SocialClient {
		$authorized = $this->clientAuthRequest->getByCode($client->getId(), $code);

		// authorize() stamps last_update at the authorization moment
		if ($authorized->getLastUpdate() > 0
			&& $authorized->getLastUpdate() + self::TIME_CODE_TTL < time()) {
			throw new ClientException('code expired');
		}

		$this->confirmCodeVerifier($authorized, $codeVerifier);

		return $this->clientAuthRequest->exchange($client->getId(), $code, $this->token(80));
	}

	/**
	 * @throws ClientException
	 */
	private function confirmCodeVerifier(SocialClient $authorized, string $codeVerifier): void {
		$challenge = $authorized->getAuthCodeChallenge();
		if ($challenge === '') {
			return;
		}

		if (preg_match(self::CODE_VERIFIER_PATTERN, $codeVerifier) !== 1
			|| !hash_equals($challenge, self::codeChallenge($codeVerifier))) {
			throw new ClientException('invalid code_verifier');
		}
	}

	/** The `S256` challenge of a verifier: base64url of its SHA-256, unpadded. */
	public static function codeChallenge(string $codeVerifier): string {
		return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
	}

	/** What one account has authorized. @return SocialClient[] */
	public function getAuthorizationsOf(string $userId): array {
		return $this->clientAuthRequest->getByUser($userId);
	}

	/**
	 * Takes one of an account's own authorizations back.
	 *
	 * Scoped to that account by looking it up among theirs rather than by
	 * deleting the id it was handed: the id is a number a caller can count
	 * upwards, and revoking somebody else's token — signing a stranger's
	 * phone out — has to be impossible rather than unlikely.
	 *
	 * @return bool whether there was one to take back
	 */
	public function revokeAuthorizationOf(string $userId, int $authId): bool {
		foreach ($this->getAuthorizationsOf($userId) as $authorization) {
			if ($authorization->getAuthId() === $authId) {
				$this->clientAuthRequest->revoke($authId);

				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $clientId
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getFromClientId(string $clientId): SocialClient {
		return $this->clientRequest->getFromClientId($clientId);
	}

	/**
	 * @param string $token
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getFromToken(string $token): SocialClient {
		$client = $this->clientAuthRequest->getByToken($token);

		if ($client->getLastUpdate() + self::TIME_TOKEN_TTL < time()) {
			try {
				// only the authorization goes: the app registration is the
				// instance's, and taking it with an idle token made the client
				// register itself all over again
				$this->clientAuthRequest->deprecate();
			} catch (Exception $e) {
			}

			throw new ClientNotFoundException();
		}

		// Keep the row's last_update roughly current (at most one write per
		// TIME_TOKEN_REFRESH), so a token in active use never reaches the TTL.
		// The old inverted comparison only refreshed *recently written* rows, so
		// any token idle for five minutes stopped refreshing and died a year
		// after its first burst of use, no matter how actively it was used since.
		if ($client->getLastUpdate() + self::TIME_TOKEN_REFRESH < time()) {
			$this->clientAuthRequest->touch($client->getAuthId());
		}

		return $client;
	}

	/**
	 * Revokes the access token presented by a client (RFC 7009). Unknown tokens
	 * are not an error — the outcome the caller asked for is true either way.
	 *
	 * @throws ClientException
	 */
	public function revokeToken(SocialClient $client, string $token): void {
		$stored = $this->clientAuthRequest->getByToken($token);
		if ($stored->getId() !== $client->getId()) {
			throw new ClientException('token does not belong to this client');
		}

		// one authorization, not the app row: revoking on one device must not
		// sign out everybody else who authorized the same client
		$this->clientAuthRequest->revoke($stored->getAuthId());
	}

	/**
	 * @param SocialClient $client
	 * @param array $data
	 *
	 * @throws ClientException
	 */
	public function confirmData(SocialClient $client, array $data) {
		if (array_key_exists('redirect_uri', $data)
			&& !in_array($data['redirect_uri'], $client->getAppRedirectUris(), true)) {
			throw new ClientException('unknown redirect_uri');
		}

		if (array_key_exists('client_secret', $data)
			&& !$this->secretHasher->matches($client->getAppClientSecret(), (string)$data['client_secret'])) {
			throw new ClientException('wrong client_secret');
		}

		if (array_key_exists('app_scopes', $data)) {
			$scopes = $data['app_scopes'];
			if (!is_array($scopes)) {
				$scopes = $client->getScopesFromString($scopes);
			}

			foreach ($scopes as $scope) {
				if (!in_array($scope, $client->getAppScopes(), true)) {
					throw new ClientException('invalid scope');
				}
			}
		}

		// Neither `code` nor `auth_scopes` is among what this checks. An app row
		// no longer carries either, because it no longer carries one
		// authorization: the code is what *finds* the authorization, and both
		// it and the scopes granted with it live on the row it names — the
		// code checked by exchangeCode(), the scopes enforced per request by
		// each controller's checkTokenScope().
	}
}
