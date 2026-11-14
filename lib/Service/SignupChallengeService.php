<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;

/**
 * The proof of work the registration form carries: a hashcash puzzle that
 * makes each registration cost the sender some computation, without asking
 * any third party.
 *
 * A challenge is `<expires>.<bits>.<id>.<mac>`: when it stops being accepted,
 * how many leading zero bits the answer has to reach, a random id, and an
 * HMAC over the rest and the form it belongs to, keyed with the instance
 * secret. Nothing is stored when one is issued. The browser looks for a
 * nonce (up to NONCE_MAX_LENGTH letters and digits) for which
 * SHA-256(challenge ‖ nonce) starts with that many zero bits; checking it
 * here is one HMAC and one hash. A challenge is good once: the id of an
 * accepted one is claimed in the `DurableCache` table until it expires.
 */
class SignupChallengeService {
	public const CACHE_NAMESPACE = 'social.signup-pow';

	/** Seconds a challenge is accepted after it is issued. */
	public const TTL = 1200;

	public const NONCE_MAX_LENGTH = 32;

	/** What the HMAC binds a challenge to, so no other form accepts it. */
	private const PURPOSE = 'signup';

	public function __construct(
		private ExternalUserService $externalUserService,
		private DurableCache $cache,
		private ISecureRandom $secureRandom,
		private IConfig $config,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * A fresh challenge at the administrator's difficulty.
	 *
	 * @return array{challenge: string, bits: int, expires: int}
	 */
	public function issue(): array {
		$expires = $this->timeFactory->getTime() + self::TTL;
		$bits = $this->externalUserService->proofOfWorkBits();
		$body = $expires . '.' . $bits . '.' . $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

		return [
			'challenge' => $body . '.' . $this->mac($body),
			'bits' => $bits,
			'expires' => $expires,
		];
	}

	/**
	 * Whether a nonce solves a challenge this server issued, which has not
	 * expired and was not accepted before. Accepting it uses it up.
	 */
	public function verify(string $challenge, string $nonce): bool {
		$parts = explode('.', $challenge);
		if (count($parts) !== 4 || preg_match('/^[A-Za-z0-9]{1,' . self::NONCE_MAX_LENGTH . '}$/', $nonce) !== 1) {
			return false;
		}
		[$expires, $bits, $id, $mac] = $parts;
		if (!hash_equals($this->mac($expires . '.' . $bits . '.' . $id), $mac)) {
			return false;
		}

		$left = (int)$expires - $this->timeFactory->getTime();
		if ($left <= 0 || (int)$bits < ExternalUserService::MIN_PROOF_OF_WORK_BITS) {
			return false;
		}
		if (self::leadingZeroBits(hash('sha256', $challenge . $nonce, true)) < (int)$bits) {
			return false;
		}

		return $this->cache->addShared(self::CACHE_NAMESPACE, $id, 1, $left + 60);
	}

	/** How many bits at the start of a binary string are zero. */
	public static function leadingZeroBits(string $bytes): int {
		$bits = 0;
		$length = strlen($bytes);
		for ($i = 0; $i < $length; $i++) {
			$byte = ord($bytes[$i]);
			if ($byte === 0) {
				$bits += 8;
				continue;
			}

			while (($byte & 0x80) === 0) {
				$bits++;
				$byte <<= 1;
			}

			return $bits;
		}

		return $bits;
	}

	private function mac(string $body): string {
		return hash_hmac(
			'sha256',
			self::PURPOSE . "\0" . $body,
			'social-signup-pow' . "\0" . $this->config->getSystemValueString('secret', '')
		);
	}
}
