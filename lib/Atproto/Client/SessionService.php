<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoClientRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Service\EnabledUserService;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use Throwable;

/**
 * Sessions of Bluesky apps signed in with an app password (§6.3), as a PDS
 * hands them out: an access token for two hours and a refresh token for
 * ninety days, JWTs this server signs with its service key. The refresh
 * token's id is the session; the access token names it, so ending the
 * session — or revoking the password that opened it — ends both at once.
 * A wrong password counts against the caller's address in Nextcloud's own
 * brute-force protection.
 */
class SessionService {
	public const ACCESS_LIFETIME = 2 * 3600;
	public const REFRESH_LIFETIME = 90 * 86400;
	public const ACCESS_SCOPE = 'com.atproto.access';
	public const REFRESH_SCOPE = 'com.atproto.refresh';
	private const THROTTLE = 'social_atproto_session';

	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private InstanceKeyService $instanceKeys,
		private AppPasswordService $appPasswords,
		private AtprotoClientRequest $request,
		private ActorsRequest $actors,
		private IAccountManager $accountManager,
		private IUserManager $userManager,
		private IThrottler $throttler,
		private ITimeFactory $time,
		private EnabledUserService $enabledUsers,
	) {
	}

	/**
	 * `com.atproto.server.createSession`.
	 *
	 * @throws XrpcException
	 */
	public function create(string $identifier, string $password, string $ip): array {
		try {
			$this->throttler->sleepDelayOrThrowOnMax($ip, self::THROTTLE);
		} catch (MaxDelayReached) {
			throw new XrpcException(429, 'RateLimitExceeded', 'Too many attempts; try again later');
		}
		$credentials = $this->credentialsOf($identifier, $password);
		if ($credentials === null) {
			$this->throttler->registerAttempt(self::THROTTLE, $ip);

			throw new XrpcException(401, 'AuthenticationRequired', 'Invalid identifier or password');
		}
		[$identity, $userId, $appPasswordId] = $credentials;
		if ($identity->state === Identity::STATE_TOMBSTONED || $identity->state === Identity::STATE_MOVED_AWAY) {
			throw new XrpcException(400, 'AccountTakedown', 'This account is no longer hosted here');
		}
		self::assertActive($identity);

		return $this->open($identity, $userId, $appPasswordId);
	}

	/**
	 * `com.atproto.server.refreshSession`: the old session ends, a new one
	 * starts.
	 *
	 * @throws XrpcException
	 */
	public function refresh(string $authorization): array {
		$claims = $this->claims($authorization, self::REFRESH_SCOPE);
		$session = $this->request->getSession((string)$claims['jti']);
		if ($session === null || $session['expires'] < $this->time->getTime()) {
			throw new XrpcException(400, 'ExpiredToken', 'Token has been revoked');
		}
		$this->assertEnabled($session['user_id'], 400);
		if (!$this->request->removeSession($session['jti'])) {
			throw new XrpcException(400, 'ExpiredToken', 'Token has already been used');
		}
		$identity = $this->identityByDid($session['did']);
		self::assertActive($identity);
		if (!$this->appPasswords->exists($session['user_id'], $session['app_password_id'])) {
			throw new XrpcException(400, 'ExpiredToken', 'Token has been revoked');
		}

		return $this->open($identity, $session['user_id'], $session['app_password_id']);
	}

	/**
	 * `com.atproto.server.deleteSession`.
	 *
	 * @throws XrpcException
	 */
	public function delete(string $authorization): void {
		$claims = $this->claims($authorization, self::REFRESH_SCOPE);
		$this->request->removeSession((string)$claims['jti']);
	}

	/**
	 * The signed-in account an access token names, for every authenticated
	 * call; null without an `Authorization` header.
	 *
	 * @throws XrpcException for a token that is not good
	 */
	public function authenticate(string $authorization): ?ClientSession {
		if (trim($authorization) === '') {
			return null;
		}
		$claims = $this->claims($authorization, self::ACCESS_SCOPE);
		$session = $this->request->getSession((string)($claims['sid'] ?? ''));
		if ($session === null || $session['did'] !== ($claims['sub'] ?? '')) {
			throw new XrpcException(401, 'ExpiredToken', 'Token has been revoked');
		}
		if (!$this->appPasswords->exists($session['user_id'], $session['app_password_id'])) {
			$this->request->removeSession($session['jti']);
			throw new XrpcException(401, 'ExpiredToken', 'Token has been revoked');
		}
		$this->assertEnabled($session['user_id'], 401);
		$identity = $this->identityByDid($session['did']);
		self::assertActive($identity);

		$scopes = ['atproto', ClientSession::GENERIC, ClientSession::EMAIL];
		if ($this->request->isPrivileged($session['app_password_id'])) {
			// a privileged app password reaches the direct messages, as Bluesky's do
			$scopes[] = ClientSession::CHAT;
		}

		return new ClientSession($session['user_id'], $identity, $session['jti'], $scopes);
	}

	/**
	 * `com.atproto.server.getSession`.
	 */
	public function describe(ClientSession $session): array {
		return [
			'handle' => $session->identity->handle,
			'did' => $session->identity->did,
			'didDoc' => $this->identities->document($session->identity),
			'active' => $session->identity->isActive(),
		] + ($session->permissions()->mayReadEmail() ? $this->email($session->userId) : ['emailConfirmed' => false]);
	}

	/**
	 * `com.atproto.server.createAppPassword`.
	 *
	 * @throws XrpcException
	 */
	public function createAppPassword(ClientSession $session, string $name, bool $privileged): array {
		if ($privileged && !$session->may(ClientSession::CHAT)) {
			throw new XrpcException(403, 'InsufficientScope', 'This session cannot create an app password with chat access');
		}

		try {
			$created = $this->appPasswords->create($session->userId, $name, $privileged);
		} catch (MoveErrorException $e) {
			throw XrpcException::invalidRequest($e->getMessage());
		}

		$metadata = null;
		foreach ($this->appPasswords->list($session->userId) as $password) {
			if ($password['id'] === $created['id']) {
				$metadata = $password;
				break;
			}
		}
		if ($metadata === null) {
			throw new XrpcException(500, 'InternalServerError', 'The app password could not be read after creation');
		}

		return [
			'name' => $created['name'],
			'password' => $created['password'],
			'createdAt' => gmdate('Y-m-d\\TH:i:s\\Z', $metadata['creation']),
		] + ($privileged ? ['privileged' => true] : []);
	}

	/**
	 * `com.atproto.server.listAppPasswords`; password values and internal ids never leave the server.
	 */
	public function listAppPasswords(ClientSession $session): array {
		$passwords = [];
		foreach ($this->appPasswords->list($session->userId) as $password) {
			$passwords[] = [
				'name' => $password['name'],
				'createdAt' => gmdate('Y-m-d\\TH:i:s\\Z', $password['creation']),
			] + ($password['privileged'] ? ['privileged' => true] : []);
		}

		return ['passwords' => $passwords];
	}

	/**
	 * `com.atproto.server.revokeAppPassword`.
	 */
	public function revokeAppPassword(ClientSession $session, string $name): void {
		$this->appPasswords->revokeByName($session->userId, $name);
	}

	/**
	 * The account's e-mail address and its actual Nextcloud verification
	 * state. A configured address alone is not proof of ownership.
	 *
	 * @return array{email?: string, emailConfirmed: bool}
	 */
	private function email(string $userId): array {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return ['emailConfirmed' => false];
		}
		$property = $this->accountManager->getAccount($user)->getProperty(IAccountManager::PROPERTY_EMAIL);
		$email = trim($property->getValue());

		return $email === ''
			? ['emailConfirmed' => false]
			: ['email' => $email, 'emailConfirmed' => $property->getVerified() === IAccountManager::VERIFIED];
	}

	/**
	 * @throws XrpcException
	 */
	private function open(Identity $identity, string $userId, int $appPasswordId): array {
		$now = $this->time->getTime();
		$jti = bin2hex(random_bytes(16));
		$this->request->addSession($jti, $userId, $identity->did, $appPasswordId, $now + self::REFRESH_LIFETIME);

		return [
			'accessJwt' => $this->sign(['scope' => self::ACCESS_SCOPE, 'sub' => $identity->did, 'sid' => $jti, 'iat' => $now, 'exp' => $now + self::ACCESS_LIFETIME]),
			'refreshJwt' => $this->sign(['scope' => self::REFRESH_SCOPE, 'sub' => $identity->did, 'jti' => $jti, 'iat' => $now, 'exp' => $now + self::REFRESH_LIFETIME]),
			'handle' => $identity->handle,
			'did' => $identity->did,
			'didDoc' => $this->identities->document($identity),
			'active' => $identity->isActive(),
		] + $this->email($userId);
	}

	/**
	 * Refuses an account its owner switched off for Bluesky (§4.6): no app
	 * signs in to it, and none signed in goes on, until it is on again here.
	 *
	 * @throws XrpcException
	 */
	private static function assertActive(Identity $identity): void {
		if (!$identity->isActive()) {
			throw new XrpcException(400, 'AccountDeactivated', $identity->state === Identity::STATE_DEACTIVATED
				? 'This account is switched off for Bluesky; its owner can switch it on again in their settings on this server'
				: 'This account is deactivated');
		}
	}

	/**
	 * Refuses the sessions of a Nextcloud account that is disabled; they
	 * are kept, and work again once it is enabled.
	 *
	 * @throws XrpcException
	 */
	private function assertEnabled(string $userId, int $status): void {
		if (!$this->enabledUsers->isEnabled($userId)) {
			throw new XrpcException($status, 'AccountDeactivated', 'This account is disabled');
		}
	}

	/**
	 * A token of this server's, addressed to itself.
	 */
	public function sign(array $claims): string {
		$key = $this->instanceKeys->serviceKey();
		$claims['aud'] = $this->config->serviceDid();
		$header = Encoding::base64UrlEncode((string)json_encode(['typ' => 'at+jwt', 'alg' => $key->publicKey()->curve->jwtAlgorithm()]));
		$payload = Encoding::base64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

		return $header . '.' . $payload . '.' . Encoding::base64UrlEncode($key->sign($header . '.' . $payload));
	}

	/**
	 * A token of this server's, unexpired, of the scope asked for.
	 *
	 * @throws XrpcException
	 */
	public function claims(string $authorization, string $scope): array {
		if (preg_match('/^Bearer\s+([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', trim($authorization), $m) !== 1) {
			throw new XrpcException(401, 'AuthenticationRequired', 'A bearer token is required');
		}
		try {
			$valid = $this->instanceKeys->serviceKey()->publicKey()->verify($m[1] . '.' . $m[2], Encoding::base64UrlDecode($m[3]));
			$claims = json_decode(Encoding::base64UrlDecode($m[2]), true);
		} catch (Throwable) {
			$valid = false;
			$claims = null;
		}
		if (!$valid || !is_array($claims)) {
			throw new XrpcException(401, 'InvalidToken', 'Token could not be verified');
		}
		if (($claims['scope'] ?? '') !== $scope || ($claims['aud'] ?? '') !== $this->config->serviceDid()) {
			throw new XrpcException(400, 'InvalidToken', 'Bad token scope');
		}
		if ((int)($claims['exp'] ?? 0) < $this->time->getTime()) {
			throw new XrpcException(400, 'ExpiredToken', 'Token has expired');
		}

		return $claims;
	}

	/**
	 * The account a sign-in names, with the app password that authenticates
	 * it. An e-mail address may belong to more than one Nextcloud account, so
	 * each matching account is checked until its own app password matches.
	 *
	 * @return array{Identity, string, int}|null
	 */
	private function credentialsOf(string $identifier, string $password): ?array {
		$identifier = strtolower(trim(ltrim(trim($identifier), '@')));
		$identities = [];
		try {
			if (Syntax::isDid($identifier)) {
				$identities[] = $this->identities->getByDid($identifier);
			} elseif (Syntax::isHandle($identifier)) {
				$identities[] = $this->identities->getByHandle($identifier);
			} elseif (str_contains($identifier, '@')) {
				foreach ($this->userManager->getByEmail($identifier) as $user) {
					try {
						$identity = $this->identities->getByActorId($this->actors->getFromUserId($user->getUID())->getId());
						$identities[$identity->actorId] = $identity;
					} catch (ActorDoesNotExistException|AtprotoIdentityNotFoundException) {
						continue;
					}
				}
			}
		} catch (Throwable) {
			return null;
		}

		foreach ($identities as $identity) {
			$userId = $this->userIdOf($identity);
			if (!$this->enabledUsers->isEnabled($userId)) {
				continue;
			}
			$appPasswordId = $this->appPasswords->verify($userId, $password);
			if ($appPasswordId !== null) {
				return [$identity, $userId, $appPasswordId];
			}
		}

		return null;
	}

	private function userIdOf(Identity $identity): string {
		try {
			return $this->actors->getFromId($identity->actorId)->getUserId();
		} catch (Throwable) {
			return '';
		}
	}

	/**
	 * @throws XrpcException
	 */
	private function identityByDid(string $did): Identity {
		try {
			return $this->identities->getByDid($did);
		} catch (AtprotoIdentityNotFoundException) {
			throw new XrpcException(401, 'ExpiredToken', 'Token has been revoked');
		}
	}
}
