<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\Auth;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\KeyManager;

/** Short-lived method-bound service authentication, signed by the account's repository key. */
class ServiceAuth {
	public function __construct(
		private readonly IdentityService $identities,
		private readonly KeyManager $keys,
	) {
	}
	public static function encode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}
	public function token(string $did, string $method): string {
		$identity = $this->identities->getIdentityByDid($did);
		if (!$this->identities->isEnabled() || !$identity || $identity['state'] !== IdentityService::STATE_ACTIVE) {
			throw new \RuntimeException('Active native identity required');
		}
		return self::sign($did, $this->identities->getSigningKey($identity['actor_id']), $method, $this->keys, time());
	}
	public static function sign(string $did, #[\SensitiveParameter] string $key, string $method, KeyManager $keys, int $time): string {
		if (!preg_match('/^app\.bsky\.[a-zA-Z0-9.]+$/D', $method)) {
			throw new \InvalidArgumentException('Invalid AppView method');
		}
		$header = self::encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256K'], JSON_THROW_ON_ERROR));
		$claims = self::encode(json_encode(['iss' => $did, 'aud' => 'did:web:api.bsky.app', 'iat' => $time, 'exp' => $time + 60, 'lxm' => $method, 'jti' => self::encode(random_bytes(16))], JSON_THROW_ON_ERROR));
		$message = $header . '.' . $claims;
		return $message . '.' . self::encode($keys->sign($message, $key));
	}
}
