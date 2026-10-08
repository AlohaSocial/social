<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\AppView;

use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Protocol\Encoding;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Inter-service authentication tokens: the JWT a PDS mints so an AppView
 * answers a request as one of its users. Signed with the user's signing
 * key, addressed to one service and valid for one method and one minute,
 * as `com.atproto.server.getServiceAuth` would hand out.
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
	 */
	public function token(PrivateKey $key, string $did, string $audience, string $method): string {
		$now = $this->time->getTime();
		$header = Encoding::base64UrlEncode((string)json_encode(['typ' => 'JWT', 'alg' => $key->publicKey()->curve->jwtAlgorithm()]));
		$payload = Encoding::base64UrlEncode((string)json_encode([
			'iss' => $did,
			'aud' => $audience,
			'lxm' => $method,
			'jti' => bin2hex(random_bytes(16)),
			'iat' => $now,
			'exp' => $now + self::LIFETIME,
		], JSON_UNESCAPED_SLASHES));
		$signature = $key->sign($header . '.' . $payload);

		return $header . '.' . $payload . '.' . Encoding::base64UrlEncode($signature);
	}
}
