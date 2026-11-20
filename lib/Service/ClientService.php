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
use OCA\Social\Db\DeviceAuthorizationRequest;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InvalidGrantException;
use OCA\Social\Exceptions\InvalidScopeException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class ClientService
 *
 * @package OCA\Social\Service
 */
class ClientService {
	/** How often a token in use has its last use written, at most. */
	public const TIME_TOKEN_REFRESH = 300; // 5m

	/** `token_idle_days` when it is not set or not a number of days. */
	public const DEFAULT_TOKEN_IDLE_DAYS = 90;

	/**
	 * How long a registration nobody has authorized is kept. Mastodon vacuums
	 * those after a day; a week leaves room for somebody who registered a
	 * client and comes back to sign in with it later.
	 */
	public const TIME_UNUSED_APP_TTL = 604800; // 7d

	// an authorization code is single-use plumbing; it expires quickly
	public const TIME_CODE_TTL = 600; // 10m

	/**
	 * The PKCE transformations this server accepts (RFC 7636 §4.2); `plain`
	 * offers no protection against an intercepted authorization request.
	 */
	public const CODE_CHALLENGE_METHODS = ['S256'];

	// RFC 7636 §4.1: the verifier is 43 to 128 unreserved characters
	private const CODE_VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

	/** RFC 6749 §4.1.1 / RFC 8252: the out-of-band redirect, shown rather than sent */
	public const REDIRECT_URI_OOB = 'urn:ietf:wg:oauth:2.0:oob';

	/** what an app token may do: read what anybody may read */
	public const APP_TOKEN_SCOPES = ['read'];

	/** the path a move-in callback ends in, after any `/index.php` or subdirectory */
	public const MOVE_IN_CALLBACK_PATH = '/apps/social/migration/move-in/callback';

	/** `scheme://`, lower case: a web origin or a native application's own scheme */
	private const REDIRECT_URI_SCHEME_PATTERN = '/^([a-z][a-z0-9+.\-]*):\/\//';

	/**
	 * Schemes a browser executes rather than navigates to. A redirect URI with
	 * one of these is a link on the consent page that runs in the account's
	 * session, not an address a code can be sent to.
	 */
	private const REDIRECT_URI_FORBIDDEN_SCHEMES = ['javascript', 'data', 'vbscript', 'file'];

	use TStringTools;

	private ClientRequest $clientRequest;

	private SecretHasher $secretHasher;

	public function __construct(
		ClientRequest $clientRequest,
		SecretHasher $secretHasher,
		private ClientAuthRequest $clientAuthRequest,
		private ConfigService $configService,
		private PushSubscriptionService $pushSubscriptions,
		private DeviceAuthorizationRequest $deviceAuthorizations,
		private LoggerInterface $logger,
		private EnabledUserService $enabledUsers,
		private ?ITimeFactory $timeFactory = null,
		private ?ModerationService $moderation = null,
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

		foreach ($client->getAppRedirectUris() as $uri) {
			if (!self::isAcceptableRedirectUri((string)$uri)) {
				throw new ClientException('invalid redirect_uri: ' . $uri);
			}
		}

		// as Mastodon validates it: shown to people as a link to the app
		if ($client->getAppWebsite() !== '' && self::websiteLink($client->getAppWebsite()) === '') {
			throw new ClientException('invalid website');
		}

		$problem = self::migrationScopeProblem($client->getAppScopes(), $client->getAppRedirectUris());
		if ($problem !== null) {
			throw new ClientException($problem);
		}

		$client->setAppClientId($this->token(40));
		$client->setAppClientSecret($this->token(40));

		$this->clientRequest->saveApp($client);
	}

	/**
	 * An application's website as it may be offered as a link: an `http` or
	 * `https` address with a host, or '' for anything else, including rows
	 * registered before registration checked it.
	 */
	public static function websiteLink(string $website): string {
		$website = trim($website);
		if (preg_match('/^https?:\/\/[^\/\s]/i', $website) !== 1
			|| preg_match('/[\x00-\x20\x7f]/', $website) === 1
			|| (string)parse_url($website, PHP_URL_HOST) === '') {
			return '';
		}

		return $website;
	}

	/**
	 * Whether a URI may be registered as a redirect URI (RFC 6749 §3.1.2):
	 * the out-of-band urn, an `http`/`https` address with a host, or a native
	 * application's custom scheme — anything but a scheme a browser would run.
	 */
	public static function isAcceptableRedirectUri(string $uri): bool {
		if ($uri === self::REDIRECT_URI_OOB) {
			return true;
		}

		if (preg_match(self::REDIRECT_URI_SCHEME_PATTERN, $uri, $matches) !== 1) {
			return false;
		}

		$scheme = $matches[1];
		if (in_array($scheme, self::REDIRECT_URI_FORBIDDEN_SCHEMES, true)) {
			return false;
		}

		if (in_array($scheme, ['http', 'https'], true)) {
			return (string)parse_url($uri, PHP_URL_HOST) !== '';
		}

		return true;
	}

	/**
	 * Whether a URI is the move-in callback of an Aloha Social server: an
	 * `http`/`https` address with a host, no credentials, query or fragment,
	 * whose path ends in the callback route (`MigrationController::moveInFinishCallback()`).
	 */
	public static function isMoveInCallback(string $uri): bool {
		$parts = parse_url($uri);
		if (!is_array($parts)
			|| !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
			|| ($parts['host'] ?? '') === ''
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			return false;
		}

		return str_ends_with($parts['path'] ?? '', self::MOVE_IN_CALLBACK_PATH);
	}

	/**
	 * Why `write:migration` may not be held with these scopes and redirect
	 * URIs, or null when it may (or is not among them). It lets the holder
	 * move the account away, so only another Aloha Social server finishing a
	 * move-in holds it: alone, and for its one move-in callback.
	 *
	 * @param string[] $scopes
	 * @param string[] $redirectUris
	 */
	public static function migrationScopeProblem(array $scopes, array $redirectUris): ?string {
		$scopes = array_values(array_unique(array_filter($scopes, static fn ($scope): bool => $scope !== '')));
		if (!in_array(MoveFinishService::SCOPE, $scopes, true)) {
			return null;
		}
		if (count($scopes) !== 1) {
			return MoveFinishService::SCOPE . ' cannot be combined with other scopes';
		}
		if (count($redirectUris) !== 1 || !self::isMoveInCallback((string)reset($redirectUris))) {
			return MoveFinishService::SCOPE . ' is only for the move-in callback of an Aloha Social server';
		}

		return null;
	}

	/**
	 * Whether granting these scopes takes the account's password first:
	 * `write:migration`, which lets the holder move the account away, and any
	 * `admin` scope (`admin:read`, `admin:write` and their sub-scopes), which
	 * lets it use the administration API as an administrator.
	 *
	 * @param string[] $scopes
	 */
	public static function needsPasswordConfirmation(array $scopes): bool {
		foreach ($scopes as $scope) {
			if ($scope === MoveFinishService::SCOPE || self::isAdminScope((string)$scope)) {
				return true;
			}
		}

		return false;
	}

	/** `admin`, `admin:read`, `admin:write:accounts` and the like. */
	public static function isAdminScope(string $scope): bool {
		return $scope === 'admin' || str_starts_with($scope, 'admin:');
	}

	/**
	 * A client registration for a PeerTube app, made on the spot. PeerTube's
	 * `/oauth-clients/local` hands out one pair per instance; this app hashes
	 * client secrets, so it registers a fresh pair each call, which the
	 * client uses at once, as a Mastodon client does through `/api/v1/apps`.
	 *
	 * @throws ClientException
	 */
	public function registerPeerTubeClient(): SocialClient {
		$client = new SocialClient();
		$client->setAppName('PeerTube client')
			->setAppWebsite('https://joinpeertube.org')
			// the out-of-band urn, which is what a client with no callback of
			// its own uses and what `OAuthController` already understands
			->setAppRedirectUris([self::REDIRECT_URI_OOB])
			->setAppScopes(['read', 'write', 'follow']);

		$this->createApp($client);

		return $client;
	}

	/**
	 * Records that this account has authorized this app, and returns the code
	 * to hand back. One row per (app, account), so signing in with an app does
	 * not sign anybody else out of it.
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
			$client->getAuthCodeChallengeMethod(),
			$client->getAuthRedirectUri()
		);
	}

	/**
	 * Exchanges an authorization code for a token of the account the code
	 * belongs to. With PKCE (RFC 7636) only the matching verifier is accepted,
	 * and the `redirect_uri` has to be the one the code was issued for
	 * (RFC 6749 §4.1.3), so an intercepted code is of no use on its own.
	 *
	 * @throws ClientNotFoundException the code names no live authorization
	 * @throws InvalidGrantException the redirect_uri is not the one the code
	 *                               was issued for
	 * @throws ClientException it names one that has expired, or the verifier
	 *                         does not match the challenge it was bound to
	 */
	public function exchangeCode(
		SocialClient $client, string $code, string $codeVerifier = '', string $redirectUri = '',
	): SocialClient {
		$authorized = $this->clientAuthRequest->getByCode($client->getId(), $code);

		// authorize() stamps last_update at the authorization moment
		if ($authorized->getLastUpdate() > 0
			&& $authorized->getLastUpdate() + self::TIME_CODE_TTL < $this->now()) {
			throw new ClientException('code expired');
		}

		if ($authorized->getAuthRedirectUri() !== $redirectUri) {
			throw new InvalidGrantException('redirect_uri does not match the authorization request');
		}

		$this->confirmCodeVerifier($authorized, $codeVerifier);

		return $this->clientAuthRequest->exchange($client->getId(), $code, $this->token(80), $this->token(80));
	}

	/**
	 * The `refresh_token` grant (RFC 6749 §6): a new access token and a new
	 * refresh token in place of the ones the authorization holds. Rotated on
	 * every use; a token presented twice was copied, so the whole
	 * authorization goes (RFC 9700 §4.14.2). A refresh may narrow the scopes,
	 * never widen them. The Web Push subscription moves to the new token; a
	 * streaming connection on the old one is closed at the daemon's next check.
	 *
	 * @throws InvalidGrantException the token is unknown, spent, expired, the
	 *                               account's is disabled, or it belongs to
	 *                               another client
	 * @throws InvalidScopeException a scope that was not granted was asked for
	 */
	public function refresh(SocialClient $client, string $refreshToken, string $scope = ''): SocialClient {
		try {
			$stored = $this->clientAuthRequest->getByRefreshToken($refreshToken);
		} catch (ClientNotFoundException $e) {
			$this->revokeFamilyOfSpent($refreshToken);

			throw new InvalidGrantException('unknown refresh_token');
		}

		if ($stored->getId() !== $client->getId()) {
			throw new InvalidGrantException('refresh_token was not issued to this client');
		}

		$now = $this->now();
		if ($stored->getLastUpdate() + $this->idleSeconds() < $now) {
			$this->clientAuthRequest->revoke($stored->getAuthId());
			$this->deletePushSubscription($stored->getAuthUserId(), $stored->getToken());

			throw new InvalidGrantException('refresh_token expired');
		}

		if ($stored->getAuthUserId() === '' || !$this->enabledUsers->isEnabled($stored->getAuthUserId())) {
			throw new InvalidGrantException('the account is disabled');
		}

		$scopes = $stored->getAuthScopes();
		if (trim($scope) !== '') {
			$requested = array_values(array_unique(array_filter(
				$stored->getScopesFromString(trim($scope)), static fn (string $one): bool => $one !== ''
			)));
			if (array_diff($requested, $scopes) !== []) {
				throw new InvalidScopeException('scope exceeds what was granted');
			}
			$scopes = $requested;
		}

		$token = $this->token(80);
		$newRefreshToken = $this->token(80);
		if (!$this->clientAuthRequest->rotate($stored->getAuthId(), $refreshToken, $token, $newRefreshToken, $scopes, $now)) {
			// another request presented the same token a moment ago and won
			throw new InvalidGrantException('refresh_token already used');
		}

		$this->rebindPushSubscription($stored->getAuthUserId(), $stored->getToken(), $token);

		return $stored->setToken($token)
			->setRefreshToken($newRefreshToken)
			->setAuthScopes($scopes)
			->setTokenCreation($now)
			->setRefreshCount($stored->getRefreshCount() + 1)
			->setLastUpdate($now);
	}

	/**
	 * Revokes the authorization a spent refresh token was exchanged on, if
	 * it was: presenting it again means somebody else holds a copy.
	 */
	private function revokeFamilyOfSpent(string $refreshToken): void {
		$authId = $this->clientAuthRequest->spentOn($refreshToken);
		if ($authId === null) {
			return;
		}

		try {
			$family = $this->clientAuthRequest->getByAuthId($authId);
		} catch (ClientNotFoundException $e) {
			return;
		}

		$this->logger->warning('A spent OAuth refresh token was presented again; the authorization was revoked', [
			'user' => $family->getAuthUserId(),
			'app' => $family->getAppName(),
		]);
		$this->clientAuthRequest->revoke($authId);
		$this->deletePushSubscription($family->getAuthUserId(), $family->getToken());
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
	 * Takes one of an account's own authorizations back, looked up among
	 * theirs: the id is a countable number, and revoking somebody else's must
	 * be impossible.
	 *
	 * @return bool whether there was one to take back
	 */
	public function revokeAuthorizationOf(string $userId, int $authId): bool {
		foreach ($this->getAuthorizationsOf($userId) as $authorization) {
			if ($authorization->getAuthId() === $authId) {
				$this->clientAuthRequest->revoke($authId);
				$this->deletePushSubscription($userId, $authorization->getToken());

				return true;
			}
		}

		return false;
	}

	/**
	 * Signs every application out of one account: all its authorizations,
	 * with their access tokens and unexchanged codes, the device codes it
	 * approved and nobody has collected yet, and the Web Push subscriptions
	 * bound to those tokens. App registrations and app tokens are the
	 * instance's and stay.
	 *
	 * A streaming connection opened with one of the tokens is closed by the
	 * daemon at its next token check (`MastodonStreamingDaemon`).
	 *
	 * @return int how many authorizations were taken back
	 */
	public function revokeAllOf(string $userId): int {
		if ($userId === '') {
			return 0;
		}

		$count = count($this->clientAuthRequest->getByUser($userId));
		$this->clientAuthRequest->deleteRelatedId($userId);
		$this->deviceAuthorizations->deleteByUser($userId);
		try {
			$this->pushSubscriptions->deleteAll($userId);
		} catch (Throwable $e) {
			$this->logger->error('Could not remove the Mastodon Web Push subscriptions of a signed-out account', [
				'user' => $userId,
				'exception' => $e,
			]);
		}

		return $count;
	}

	/**
	 * The `client_credentials` grant (RFC 6749 §4.4): a token for the app
	 * itself, bound to no account, as Mastodon's apps and bots take before
	 * sign-in. Granted `read` only, whatever the app registered.
	 */
	public function issueAppToken(SocialClient $client): SocialClient {
		$token = $this->token(80);
		$this->clientAuthRequest->issueAppToken($client->getId(), self::APP_TOKEN_SCOPES, $token);

		return $this->clientAuthRequest->getByToken($token)->setToken($token);
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
	 * Whether the Nextcloud user behind a token or a session holds an account
	 * suspended here, which the client API refuses whatever it is asked.
	 */
	public function isSuspendedUser(string $userId): bool {
		return $this->moderation?->isSuspendedUser($userId) ?? false;
	}

	/**
	 * Whether this local account, already read for the request, is suspended
	 * here: the same answer as `isSuspendedUser()` without reading the account
	 * a second time.
	 */
	public function isSuspendedAccount(Person $account): bool {
		return $this->moderation?->isSuspended($account->getId()) ?? false;
	}

	/**
	 * @param string $token
	 *
	 * @return SocialClient
	 * @throws ClientNotFoundException
	 */
	public function getFromToken(string $token): SocialClient {
		$client = $this->clientAuthRequest->getByToken($token);

		$now = $this->now();
		if ($client->getLastUpdate() + $this->idleSeconds() < $now) {
			try {
				// only the authorization goes: the app registration is the
				// instance's, and taking it with an idle token made the client
				// register itself all over again
				$this->clientAuthRequest->revoke($client->getAuthId());
				$this->deletePushSubscription($client->getAuthUserId(), $client->getToken());
			} catch (Exception $e) {
			}

			throw new ClientNotFoundException();
		}

		// The idle limit above is about an unused token; this bounds a used
		// one, so a copied token does not live for ever. An authorization with
		// a refresh token is kept, so its client can refresh it.
		if ($this->expiredByAge($client, $now)) {
			if ($client->getRefreshToken() === '') {
				$this->clientAuthRequest->revoke($client->getAuthId());
				$this->deletePushSubscription($client->getAuthUserId(), $client->getToken());
			}

			throw new ClientNotFoundException('the access_token has expired');
		}

		// a disabled account keeps its tokens, and they work again once it is enabled
		if ($client->getAuthUserId() !== '' && !$this->enabledUsers->isEnabled($client->getAuthUserId())) {
			throw new ClientNotFoundException('the account is disabled');
		}

		// Keep the row's last_update roughly current (at most one write per
		// TIME_TOKEN_REFRESH), so a token in active use never reaches the
		// idle limit.
		if ($client->getLastUpdate() + self::TIME_TOKEN_REFRESH < $now) {
			$this->clientAuthRequest->touch($client->getAuthId(), $now);
		}

		return $client;
	}

	/**
	 * How long an authorization may go unused before it is removed:
	 * `token_idle_days`, at least a day.
	 */
	public function idleSeconds(): int {
		$days = $this->configService->getAppValueInt(ConfigService::SOCIAL_TOKEN_IDLE_DAYS);

		return max(1, $days > 0 ? $days : self::DEFAULT_TOKEN_IDLE_DAYS) * 86400;
	}

	/**
	 * The housekeeping `Cron\Cache` runs: authorizations idle past
	 * `token_idle_days` with their Web Push subscriptions, the spent refresh
	 * tokens of authorizations that are gone, and app registrations nobody
	 * ever authorized, which keeps the public `POST /api/v1/apps` from
	 * filling the table.
	 *
	 * @return int how many unused registrations were removed
	 */
	public function sweep(): int {
		$now = $this->now();
		$before = $now - $this->idleSeconds();
		foreach ($this->clientAuthRequest->getIdleSince($before) as $idle) {
			$this->deletePushSubscription($idle->getAuthUserId(), $idle->getToken());
		}
		$this->clientAuthRequest->deprecate($before);
		$this->clientAuthRequest->pruneSpentRefreshTokens();
		$this->deviceAuthorizations->prune($now);

		return $this->clientRequest->deleteNeverAuthorized($now - self::TIME_UNUSED_APP_TTL);
	}

	/**
	 * When the current access token stops working however much it is used,
	 * or 0 for never: `refreshed_token_hours` after a refresh issued it,
	 * `token_max_days` after any other issue (the grant, for a row without an
	 * issue date). A setting at 0, or a row with no date, is never.
	 */
	public function expiresAt(SocialClient $client): int {
		$issued = ($client->getTokenCreation() > 0) ? $client->getTokenCreation() : $client->getAuthCreation();
		if ($issued < 1) {
			return 0;
		}

		if ($client->getRefreshCount() > 0) {
			$hours = $this->configService->getAppValueInt(ConfigService::SOCIAL_REFRESHED_TOKEN_HOURS);
			if ($hours > 0) {
				return $issued + $hours * 3600;
			}
		}

		$days = $this->configService->getAppValueInt(ConfigService::SOCIAL_TOKEN_MAX_DAYS);

		return ($days < 1) ? 0 : $issued + $days * 86400;
	}

	private function expiredByAge(SocialClient $client, int $now): bool {
		$expiresAt = $this->expiresAt($client);

		return $expiresAt > 0 && $expiresAt < $now;
	}

	/**
	 * When the app is signed out however much it is used, as the Authorized
	 * apps page says it: the end of its access token's lifetime, or 0 — for
	 * a lifetime that does not end, and for an app that has refreshed its
	 * token before, which shows it renews it rather than signs out.
	 */
	public function signsOutAt(SocialClient $client): int {
		return ($client->getRefreshCount() > 0) ? 0 : $this->expiresAt($client);
	}

	/** Seconds the access token has left, for `expires_in`; null when it does not expire. */
	public function expiresIn(SocialClient $client): ?int {
		$expiresAt = $this->expiresAt($client);

		return ($expiresAt === 0) ? null : max(0, $expiresAt - $this->now());
	}

	/**
	 * Revokes the access or refresh token presented by a client (RFC 7009),
	 * which is the whole authorization either way. Unknown tokens
	 * are not an error — the outcome the caller asked for is true either way.
	 *
	 * @throws ClientException
	 */
	public function revokeToken(SocialClient $client, string $token): void {
		try {
			$stored = $this->clientAuthRequest->getByToken($token);
		} catch (ClientNotFoundException $e) {
			// RFC 7009 §2.1: a refresh token takes its access token with it
			$stored = $this->clientAuthRequest->getByRefreshToken($token);
		}
		if ($stored->getId() !== $client->getId()) {
			throw new ClientException('token does not belong to this client');
		}

		// one authorization, not the app row: revoking on one device must not
		// sign out everybody else who authorized the same client
		$this->clientAuthRequest->revoke($stored->getAuthId());
		$this->deletePushSubscription($stored->getAuthUserId(), $stored->getToken());
	}

	private function rebindPushSubscription(string $userId, string $storedToken, string $token): void {
		if ($userId === '' || !str_starts_with($storedToken, SecretHasher::PREFIX)) {
			return;
		}
		try {
			$this->pushSubscriptions->rebind(
				$userId,
				substr($storedToken, strlen(SecretHasher::PREFIX)),
				substr($this->secretHasher->hash($token), strlen(SecretHasher::PREFIX)),
				$token
			);
		} catch (Throwable $e) {
			$this->logger->error('Could not move a Mastodon Web Push subscription to a refreshed token', [
				'user' => $userId,
				'exception' => $e,
			]);
		}
	}

	private function deletePushSubscription(string $userId, string $storedToken): void {
		if ($userId === '' || !str_starts_with($storedToken, SecretHasher::PREFIX)) {
			return;
		}
		try {
			$this->pushSubscriptions->delete($userId, substr($storedToken, strlen(SecretHasher::PREFIX)));
		} catch (Throwable $e) {
			$this->logger->error('Could not remove a revoked Mastodon Web Push subscription', [
				'user' => $userId,
				'exception' => $e,
			]);
		}
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

			// a token that can move the account away is only ever sent to the
			// registered move-in callback, never handed to a polling device
			if (in_array(MoveFinishService::SCOPE, $scopes, true)) {
				$problem = self::migrationScopeProblem($scopes, $client->getAppRedirectUris())
					?? (array_key_exists('redirect_uri', $data) ? null : MoveFinishService::SCOPE . ' is granted only through the authorization code flow');
				if ($problem !== null) {
					throw new ClientException($problem);
				}
			}
		}

		// `code` and `auth_scopes` are not checked here: they live on the
		// authorization the code finds, checked by exchangeCode() and enforced
		// per request by each controller's checkTokenScope().
	}

	private function now(): int {
		return $this->timeFactory?->getTime() ?? time();
	}
}
