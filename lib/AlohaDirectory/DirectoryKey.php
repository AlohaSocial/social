<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AlohaDirectory;

use InvalidArgumentException;
use OCA\Social\Atproto\Protocol\Encoding;

/**
 * The Ed25519 key pair this server signs its requests to the Aloha Directory
 * with. It is used for nothing else: not the ActivityPub actor keys, not the
 * AT Protocol signing key.
 */
final class DirectoryKey {
	private function __construct(
		private string $secretKey,
		private string $publicKey,
	) {
	}

	public static function generate(): self {
		$pair = sodium_crypto_sign_keypair();

		return new self(sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair));
	}

	/**
	 * @param string $secretKey the 64-byte libsodium secret key
	 */
	public static function fromSecretKey(string $secretKey): self {
		if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
			throw new InvalidArgumentException('Not an Ed25519 secret key');
		}

		return new self($secretKey, sodium_crypto_sign_publickey_from_secretkey($secretKey));
	}

	public function secretKey(): string {
		return $this->secretKey;
	}

	/** The raw public key, base64url without padding, as the directory expects it. */
	public function publicKey(): string {
		return Encoding::base64UrlEncode($this->publicKey);
	}

	/** base64url(SHA-256(raw public key)): the `keyid` of every signature. */
	public function keyId(): string {
		return Encoding::base64UrlEncode(hash('sha256', $this->publicKey, true));
	}

	public function sign(string $message): string {
		return sodium_crypto_sign_detached($message, $this->secretKey);
	}
}
