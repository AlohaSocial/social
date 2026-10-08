<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use InvalidArgumentException;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\OAuthRequest;
use OCA\Social\Atproto\Model\OAuthSession;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * The OAuth authorization server of this PDS, by AT Protocol's profile
 * (§6.3): pushed authorization requests, PKCE S256, DPoP-bound tokens with
 * server nonces, client metadata documents for client IDs, the `atproto`
 * scope and the transitional ones.
 *
 * It shares the issuer and the `/oauth/authorize`, `/oauth/token` and
 * `/oauth/revoke` addresses with the Mastodon OAuth server, because AT
 * Protocol requires the issuer to be the bare origin; a request is this
 * server's when it carries what only AT Protocol's profile sends (a pushed
 * `request_uri`, a `DPoP` proof, a URL as `client_id`). Its tokens and
 * sessions are its own and good for the Bluesky surface only.
 */
class AuthorizationServer {
	public const REQUEST_URI_PREFIX = 'urn:ietf:params:oauth:request_uri:req-';
	/** what a Bluesky app may be granted; DMs (chat.bsky) are not offered here (D15) */
	public const SCOPES = ['atproto', 'transition:generic', 'transition:email'];
	/** listed, so an app that asks for it is not refused outright; never granted */
	public const SCOPES_LISTED = ['atproto', 'transition:generic', 'transition:chat.bsky', 'transition:email'];
	public const ACCESS_LIFETIME = 900;
	private const REQUEST_LIFETIME = 300;
	private const CODE_LIFETIME = 300;
	/** an untrusted public client's session and each of its refresh tokens */
	private const PUBLIC_LIFETIME = 14 * 86400;
	/** a confidential client's refresh token; its session has no end */
	private const CONFIDENTIAL_REFRESH_LIFETIME = 180 * 86400;

	public function __construct(
		private AtprotoConfig $config,
		private AtprotoOAuthRequest $request,
		private ClientMetadataService $clients,
		private ClientAuthenticator $authenticator,
		private DpopVerifier $dpop,
		private IdentityService $identities,
		private InstanceKeyService $instanceKeys,
		private ActorsRequest $actors,
		private ITimeFactory $time,
	) {
	}

	/** The issuer: this server's origin, no path, no slash. */
	public function issuer(): string {
		return rtrim($this->config->pdsEndpoint(), '/');
	}

	/**
	 * What AT Protocol's profile adds to the authorization server metadata
	 * (RFC 8414) the Mastodon OAuth server already publishes here.
	 */
	public function metadata(): array {
		$issuer = $this->issuer();

		return [
			'issuer' => $issuer,
			'pushed_authorization_request_endpoint' => $issuer . '/oauth/par',
			'require_pushed_authorization_requests' => true,
			'authorization_response_iss_parameter_supported' => true,
			'dpop_signing_alg_values_supported' => Jose::ALGORITHMS,
			'client_id_metadata_document_supported' => true,
			'token_endpoint_auth_signing_alg_values_supported' => Jose::ALGORITHMS,
			'response_modes_supported' => ['query', 'fragment'],
			'protected_resources' => [$issuer],
		];
	}

	/**
	 * The protected resource metadata of this PDS
	 * (`/.well-known/oauth-protected-resource`).
	 */
	public function resourceMetadata(): array {
		return [
			'resource' => $this->issuer(),
			'authorization_servers' => [$this->issuer()],
			'scopes_supported' => self::SCOPES_LISTED,
			'bearer_methods_supported' => ['header'],
			'resource_documentation' => 'https://atproto.com',
		];
	}

	/**
	 * A pushed authorization request (RFC 9126).
	 *
	 * @param array<string, mixed> $params the form fields
	 * @return array{request_uri: string, expires_in: int}
	 * @throws OAuthException
	 */
	public function par(array $params, string $dpopProof): array {
		$clientId = self::field($params, 'client_id');
		$metadata = $this->clients->get($clientId);
		$clientAuth = $this->authenticator->authenticate($metadata, $params, $this->issuer());
		$jkt = $this->dpop->verify($dpopProof, 'POST', $this->issuer() . '/oauth/par');
		if (isset($params['request'])) {
			throw new OAuthException('request_not_supported', 'Request objects are not supported');
		}
		if (self::field($params, 'response_type') !== 'code') {
			throw new OAuthException('unsupported_response_type', 'Only the code response type is supported');
		}
		$challenge = self::field($params, 'code_challenge');
		if (self::field($params, 'code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
			throw OAuthException::invalidRequest('PKCE with S256 is required');
		}
		if ($this->request->challengeSeen($challenge)) {
			throw OAuthException::invalidRequest('That code challenge was used before');
		}
		$state = self::field($params, 'state');
		if ($state === '' || strlen($state) > 1024) {
			throw OAuthException::invalidRequest('A state is required');
		}
		$redirectUri = self::field($params, 'redirect_uri');
		if (!ClientMetadataService::allowsRedirect($metadata, $redirectUri)) {
			throw OAuthException::invalidRequest('The redirect_uri is not one the client declared');
		}
		$requested = self::scopes(self::field($params, 'scope'));
		$declared = self::scopes((string)($metadata['scope'] ?? ''));
		if (!in_array('atproto', $requested, true)) {
			throw new OAuthException('invalid_scope', 'The atproto scope is required');
		}
		if (array_diff($requested, $declared) !== []) {
			throw new OAuthException('invalid_scope', 'The client did not declare every scope it asks for');
		}
		$responseMode = self::field($params, 'response_mode') ?: 'query';
		if (!in_array($responseMode, ['query', 'fragment'], true)) {
			throw OAuthException::invalidRequest('Only the query and fragment response modes are supported');
		}

		$requestId = bin2hex(random_bytes(24));
		$this->request->addRequest($requestId, $clientId, $clientAuth, [
			'redirect_uri' => $redirectUri,
			'scope' => implode(' ', $requested),
			'state' => $state,
			'code_challenge' => $challenge,
			'login_hint' => mb_substr(self::field($params, 'login_hint'), 0, 256),
			'response_mode' => $responseMode,
		], $jkt, $this->time->getTime() + self::REQUEST_LIFETIME);

		return ['request_uri' => self::REQUEST_URI_PREFIX . $requestId, 'expires_in' => self::REQUEST_LIFETIME - 1];
	}

	/**
	 * A request waiting for a person's answer, for the consent page.
	 *
	 * @return array{request: OAuthRequest, metadata: array, identity: Identity, scopes: string[]}
	 * @throws OAuthException
	 */
	public function pending(string $clientId, string $requestUri, string $userId): array {
		$request = $this->waiting($clientId, $requestUri);
		$identity = $this->identityOfUser($userId);
		$hint = strtolower(ltrim(trim((string)($request->params['login_hint'] ?? '')), '@'));
		if ($hint !== '' && $hint !== strtolower($identity->handle) && $hint !== $identity->did) {
			throw new OAuthException('login_required', 'The app asked to sign in ' . $hint . ', and you are signed in as ' . $identity->handle);
		}

		return [
			'request' => $request,
			'metadata' => $this->clients->get($clientId),
			'identity' => $identity,
			'scopes' => self::granted($request->params['scope'] ?? ''),
		];
	}

	/**
	 * The person agreed: a code for the app, at its redirect address.
	 *
	 * @return string where to send the browser
	 * @throws OAuthException
	 */
	public function approve(string $clientId, string $requestUri, string $userId): string {
		$pending = $this->pending($clientId, $requestUri, $userId);
		$request = $pending['request'];
		$code = 'cod-' . Encoding::base64UrlEncode(random_bytes(32));
		if (!$this->request->approveRequest($request->requestId, $userId, $pending['identity']->did, hash('sha256', $code), $this->time->getTime() + self::CODE_LIFETIME)) {
			throw OAuthException::invalidRequest('This request was answered already');
		}

		return $this->redirect($request, ['code' => $code, 'state' => $request->params['state'], 'iss' => $this->issuer()]);
	}

	/**
	 * Where the browser goes when the person says no: the app's redirect
	 * address, told `access_denied`. The request is left to expire.
	 */
	public function refusal(OAuthRequest $request): string {
		return $this->redirect($request, [
			'error' => 'access_denied',
			'error_description' => 'The account holder did not allow it',
			'state' => $request->params['state'],
			'iss' => $this->issuer(),
		]);
	}

	/**
	 * The token endpoint: a code exchanged, or a refresh token rotated.
	 *
	 * @param array<string, mixed> $params the form fields
	 * @throws OAuthException
	 */
	public function token(array $params, string $dpopProof): array {
		$clientId = self::field($params, 'client_id');
		$grant = self::field($params, 'grant_type');
		$metadata = $this->clients->get($clientId);
		if ($grant === 'refresh_token' && ClientMetadataService::isConfidential($metadata)) {
			// a key the client no longer publishes ends the session
			$metadata = $this->clients->get($clientId, true);
		}
		$clientAuth = $this->authenticator->authenticate($metadata, $params, $this->issuer());
		$jkt = $this->dpop->verify($dpopProof, 'POST', $this->issuer() . '/oauth/token');

		return match ($grant) {
			'authorization_code' => $this->exchange($params, $metadata, $clientAuth, $jkt),
			'refresh_token' => $this->refresh($params, $metadata, $clientAuth, $jkt),
			default => throw new OAuthException('unsupported_grant_type', 'Only authorization_code and refresh_token are supported'),
		};
	}

	/**
	 * Ends the session a token belongs to (RFC 7009). Unknown tokens are not
	 * an error.
	 */
	public function revoke(string $token): void {
		$session = $this->request->getSessionByRefresh(hash('sha256', $token));
		if ($session === null && str_contains($token, '.')) {
			try {
				$session = $this->request->getSession((string)($this->accessClaims($token)['sid'] ?? ''));
			} catch (OAuthException) {
			}
		}
		if ($session !== null) {
			$this->request->removeSession($session->sessionId);
		}
	}

	/**
	 * The account an app's DPoP-bound access token speaks for, on the PDS.
	 *
	 * @param string $authorization the `Authorization` header, `DPoP …`
	 * @param string $url the address the client used, without query
	 * @throws OAuthException 401 with the challenge the client acts on
	 */
	public function authenticate(string $authorization, string $dpopProof, string $method, string $url): ClientSession {
		$token = trim(substr($authorization, 5));
		try {
			$claims = $this->accessClaims($token);
			$jkt = $this->dpop->verify($dpopProof, $method, $url, $token);
		} catch (OAuthException $e) {
			throw self::challenge($e);
		}
		if (!hash_equals((string)($claims['cnf']['jkt'] ?? ''), $jkt)) {
			throw self::challenge(new OAuthException('invalid_token', 'The token is bound to another key', 401));
		}
		$session = $this->request->getSession((string)($claims['sid'] ?? ''));
		if ($session === null || $session->did !== ($claims['sub'] ?? null) || ($session->expires !== 0 && $session->expires < $this->time->getTime())) {
			throw self::challenge(new OAuthException('invalid_token', 'The session has ended', 401));
		}
		try {
			$identity = $this->identities->getByDid($session->did);
		} catch (Throwable) {
			throw self::challenge(new OAuthException('invalid_token', 'The account is gone', 401));
		}
		if (!$identity->isActive()) {
			throw self::challenge(new OAuthException('invalid_token', 'The account is deactivated', 401));
		}
		if ($session->lastUsed < $this->time->getTime() - 3600) {
			$this->request->sessionUsed($session->sessionId);
		}

		return new ClientSession($session->userId, $identity, $session->sessionId, $session->scopes());
	}

	/**
	 * A person's apps signed in through OAuth, for their settings: which
	 * app, by the address it is known by, what it may do, and when.
	 *
	 * @return list<array{id: int, client_id: string, client: string, scopes: string[], created: int, last_used: int}>
	 */
	public function sessionsOf(string $userId): array {
		return array_map(static fn (OAuthSession $session): array => [
			'id' => $session->id,
			'client_id' => $session->clientId,
			'client' => parse_url($session->clientId, PHP_URL_HOST) ?: $session->clientId,
			'scopes' => $session->scopes(),
			'created' => $session->creation,
			'last_used' => $session->lastUsed,
		], $this->request->getSessionsOfUser($userId));
	}

	/**
	 * Signs one of a person's apps out: its tokens stop working at once.
	 */
	public function endSession(string $userId, int $id): void {
		$this->request->removeSessionOfUser($userId, $id);
	}

	/**
	 * The scopes of a request that are granted: those offered here.
	 *
	 * @return string[]
	 */
	public static function granted(string $scope): array {
		return array_values(array_intersect(self::scopes($scope), self::SCOPES));
	}

	/**
	 * @throws OAuthException
	 */
	private function exchange(array $params, array $metadata, array $clientAuth, string $jkt): array {
		$code = self::field($params, 'code');
		$request = $this->request->getRequestByCode(hash('sha256', $code));
		$now = $this->time->getTime();
		if ($request === null || $request->clientId !== $metadata['client_id'] || $request->expires < $now) {
			throw OAuthException::invalidGrant('The code is not good');
		}
		if ($request->sessionId !== '') {
			// a code used twice: whoever has it has no business with the session
			$this->request->removeSession($request->sessionId);
			throw OAuthException::invalidGrant('The code was used before');
		}
		if (!ClientAuthenticator::same($request->clientAuth, $clientAuth)) {
			throw OAuthException::invalidGrant('The client authenticated differently than when it asked');
		}
		if (!hash_equals($request->dpopJkt, $jkt)) {
			throw OAuthException::invalidGrant('The DPoP key is not the one the request was made with');
		}
		if (self::field($params, 'redirect_uri') !== ($request->params['redirect_uri'] ?? '')) {
			throw OAuthException::invalidGrant('The redirect_uri is not the one the request named');
		}
		$verifier = self::field($params, 'code_verifier');
		if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1
			|| !hash_equals((string)($request->params['code_challenge'] ?? ''), Encoding::base64UrlEncode(hash('sha256', $verifier, true)))) {
			throw OAuthException::invalidGrant('The code verifier does not match the challenge');
		}
		$identity = $this->identityOfUser($request->userId);
		if ($identity->did !== $request->did) {
			throw OAuthException::invalidGrant('The account is not the one that agreed');
		}

		$confidential = $clientAuth['method'] === 'private_key_jwt';
		$sessionId = bin2hex(random_bytes(24));
		$refresh = 'ref-' . Encoding::base64UrlEncode(random_bytes(32));
		$scope = implode(' ', self::granted($request->params['scope'] ?? ''));
		if (!$this->request->exchangeCode($request->id, $sessionId)) {
			throw OAuthException::invalidGrant('The code was used before');
		}
		$this->request->addSession(new OAuthSession(
			0, $sessionId, $request->userId, $identity->did, $request->clientId, $clientAuth, $scope, $jkt,
			hash('sha256', $refresh),
			$now + ($confidential ? self::CONFIDENTIAL_REFRESH_LIFETIME : self::PUBLIC_LIFETIME),
			$confidential ? 0 : $now + self::PUBLIC_LIFETIME,
		));

		return $this->tokens($sessionId, $identity->did, $request->clientId, $scope, $jkt, $refresh);
	}

	/**
	 * @throws OAuthException
	 */
	private function refresh(array $params, array $metadata, array $clientAuth, string $jkt): array {
		$presented = hash('sha256', self::field($params, 'refresh_token'));
		$session = $this->request->getSessionByRefresh($presented);
		if ($session === null) {
			$replayed = $this->request->getSessionByPreviousRefresh($presented);
			if ($replayed !== null) {
				// a refresh token used after it was replaced: one of the two
				// holders is not the app
				$this->request->removeSession($replayed->sessionId);
			}
			throw OAuthException::invalidGrant('The refresh token is not good');
		}
		$now = $this->time->getTime();
		if ($session->clientId !== $metadata['client_id'] || $session->refreshExpires < $now
			|| ($session->expires !== 0 && $session->expires < $now)) {
			throw OAuthException::invalidGrant('The refresh token is not good');
		}
		if (!ClientAuthenticator::same($session->clientAuth, $clientAuth)) {
			throw OAuthException::invalidGrant('The client authenticated differently than when the session started');
		}
		if (!hash_equals($session->dpopJkt, $jkt)) {
			throw OAuthException::invalidGrant('The DPoP key is not the one the session is bound to');
		}
		$identity = $this->identityOfUser($session->userId);
		$confidential = $clientAuth['method'] === 'private_key_jwt';
		$refreshExpires = $now + ($confidential ? self::CONFIDENTIAL_REFRESH_LIFETIME : self::PUBLIC_LIFETIME);
		if ($session->expires !== 0) {
			$refreshExpires = min($refreshExpires, $session->expires);
		}
		$refresh = 'ref-' . Encoding::base64UrlEncode(random_bytes(32));
		if (!$this->request->rotateRefresh($session->sessionId, $presented, hash('sha256', $refresh), $refreshExpires)) {
			throw OAuthException::invalidGrant('The refresh token was used at the same time');
		}

		return $this->tokens($session->sessionId, $identity->did, $session->clientId, $session->scope, $jkt, $refresh);
	}

	private function tokens(string $sessionId, string $did, string $clientId, string $scope, string $jkt, string $refresh): array {
		$now = $this->time->getTime();
		$key = $this->instanceKeys->serviceKey();
		$header = Encoding::base64UrlEncode((string)json_encode(['typ' => 'at+jwt', 'alg' => $key->publicKey()->curve->jwtAlgorithm()]));
		$payload = Encoding::base64UrlEncode((string)json_encode([
			'iss' => $this->issuer(),
			'aud' => $this->config->serviceDid(),
			'sub' => $did,
			'client_id' => $clientId,
			'scope' => $scope,
			'cnf' => ['jkt' => $jkt],
			'sid' => $sessionId,
			'jti' => bin2hex(random_bytes(16)),
			'iat' => $now,
			'exp' => $now + self::ACCESS_LIFETIME,
		], JSON_UNESCAPED_SLASHES));

		return [
			'access_token' => $header . '.' . $payload . '.' . Encoding::base64UrlEncode($key->sign($header . '.' . $payload)),
			'token_type' => 'DPoP',
			'expires_in' => self::ACCESS_LIFETIME,
			'refresh_token' => $refresh,
			'scope' => $scope,
			'sub' => $did,
		];
	}

	/**
	 * The claims of an access token of this server's OAuth sessions: signed
	 * with the service key, unexpired, and bound to a DPoP key. An app
	 * password session's token has no `cnf` and is not one.
	 *
	 * @throws OAuthException
	 */
	private function accessClaims(string $token): array {
		try {
			$jws = Jose::decode($token);
		} catch (InvalidArgumentException) {
			throw new OAuthException('invalid_token', 'The token is not readable', 401);
		}
		$valid = ($jws['header']['typ'] ?? '') === 'at+jwt'
			&& $this->instanceKeys->serviceKey()->publicKey()->verify($jws['input'], $jws['signature']);
		$claims = $jws['claims'];
		if (!$valid || ($claims['iss'] ?? '') !== $this->issuer() || ($claims['aud'] ?? '') !== $this->config->serviceDid()
			|| !is_array($claims['cnf'] ?? null) || ($claims['sid'] ?? '') === '') {
			throw new OAuthException('invalid_token', 'The token is not one of this server\'s', 401);
		}
		if ((int)($claims['exp'] ?? 0) < $this->time->getTime()) {
			throw new OAuthException('invalid_token', 'The token has expired', 401);
		}

		return $claims;
	}

	/**
	 * @throws OAuthException
	 */
	private function waiting(string $clientId, string $requestUri): OAuthRequest {
		if (!str_starts_with($requestUri, self::REQUEST_URI_PREFIX)) {
			throw OAuthException::invalidRequest('Not a request_uri of this server');
		}
		$request = $this->request->getRequest(substr($requestUri, strlen(self::REQUEST_URI_PREFIX)));
		if ($request === null || $request->clientId !== $clientId || $request->expires < $this->time->getTime() || $request->isApproved()) {
			throw OAuthException::invalidRequest('This sign-in request has expired or was answered already; start again from the app');
		}

		return $request;
	}

	/**
	 * @throws OAuthException
	 */
	private function identityOfUser(string $userId): Identity {
		try {
			$identity = $this->identities->forActor($this->actors->getFromUserId($userId));
		} catch (Throwable) {
			$identity = null;
		}
		if ($identity === null || !$identity->isActive()) {
			throw new OAuthException('access_denied', 'This account has no active Bluesky identity here');
		}

		return $identity;
	}

	private function redirect(OAuthRequest $request, array $answer): string {
		$uri = (string)$request->params['redirect_uri'];
		if (($request->params['response_mode'] ?? 'query') === 'fragment') {
			return $uri . '#' . http_build_query($answer, '', '&', PHP_QUERY_RFC3986);
		}

		return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($answer, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * A resource server's refusal: 401 with the DPoP challenge (RFC 9449 §7.1).
	 */
	private static function challenge(OAuthException $e): OAuthException {
		$headers = $e->headers;
		$headers['WWW-Authenticate'] = 'DPoP error="' . $e->error . '", error_description="' . addcslashes($e->getMessage(), '"\\') . '"';

		return new OAuthException($e->error, $e->getMessage(), 401, $headers);
	}

	/** @return string[] */
	private static function scopes(string $scope): array {
		return array_values(array_unique(array_filter(explode(' ', $scope))));
	}

	private static function field(array $params, string $name): string {
		return is_string($params[$name] ?? null) ? $params[$name] : '';
	}
}
