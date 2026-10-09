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
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
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
		private IUserManager $userManager,
		private IThrottler $throttler,
		private ITimeFactory $time,
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
		$identity = $this->identityOf($identifier);
		$userId = $identity === null ? '' : $this->userIdOf($identity);
		$appPasswordId = $userId === '' ? null : $this->appPasswords->verify($userId, $password);
		if ($identity === null || $appPasswordId === null) {
			$this->throttler->registerAttempt(self::THROTTLE, $ip, ['identifier' => substr($identifier, 0, 100)]);

			throw new XrpcException(401, 'AuthenticationRequired', 'Invalid identifier or password');
		}
		if ($identity->state === Identity::STATE_TOMBSTONED || $identity->state === Identity::STATE_MOVED_AWAY) {
			throw new XrpcException(400, 'AccountTakedown', 'This account is no longer hosted here');
		}

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
		$this->request->removeSession($session['jti']);
		$identity = $this->identityByDid($session['did']);

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
		$identity = $this->identityByDid($session['did']);
		if (!$identity->isActive()) {
			throw new XrpcException(400, 'AccountDeactivated', 'This account is deactivated');
		}

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
	 * The account's e-mail address, as the Nextcloud account has it. It
	 * counts as confirmed: the account is one an administrator or the
	 * person manages, and Bluesky's apps offer video only to an account
	 * whose address is confirmed.
	 *
	 * @return array{email?: string, emailConfirmed: bool}
	 */
	private function email(string $userId): array {
		$email = (string)$this->userManager->get($userId)?->getEMailAddress();

		return $email === '' ? ['emailConfirmed' => false] : ['email' => $email, 'emailConfirmed' => true];
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
	 * The identity a sign-in names: a handle, a DID, or the e-mail address of
	 * the Nextcloud account.
	 */
	private function identityOf(string $identifier): ?Identity {
		$identifier = strtolower(trim(ltrim(trim($identifier), '@')));
		try {
			if (Syntax::isDid($identifier)) {
				return $this->identities->getByDid($identifier);
			}
			if (Syntax::isHandle($identifier)) {
				return $this->identities->getByHandle($identifier);
			}
			if (str_contains($identifier, '@')) {
				foreach ($this->userManager->getByEmail($identifier) as $user) {
					return $this->identities->getByActorId($this->actors->getFromUserId($user->getUID())->getId());
				}
			}
		} catch (AtprotoIdentityNotFoundException) {
		} catch (Throwable) {
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
