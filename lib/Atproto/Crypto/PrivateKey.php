<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Crypto;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;
use SensitiveParameter;

/**
 * A signing key: 32 secret bytes on one curve. OpenSSL does the signing
 * (ECDSA over SHA-256) and derives the public point; this class turns the
 * DER it produces into the protocol's raw low-S form.
 *
 * The secret is held only for as long as the object lives; what is stored
 * is `secret()`, sealed by the caller.
 */
final class PrivateKey {
	private ?OpenSSLAsymmetricKey $handle = null;
	private ?PublicKey $public = null;

	/**
	 * @param string $secret 32 bytes
	 */
	public function __construct(
		public readonly Curve $curve,
		#[SensitiveParameter]
		private readonly string $secret,
	) {
		if (strlen($secret) !== 32) {
			throw new InvalidArgumentException('A private key is 32 bytes');
		}
	}

	public static function generate(Curve $curve): self {
		$key = openssl_pkey_new(['curve_name' => $curve->opensslName(), 'private_key_type' => OPENSSL_KEYTYPE_EC]);
		if ($key === false) {
			throw new RuntimeException('OpenSSL could not generate a key: ' . (string)openssl_error_string());
		}
		$details = openssl_pkey_get_details($key);
		if ($details === false || !isset($details['ec']['d'])) {
			throw new RuntimeException('OpenSSL did not return the key');
		}

		return new self($curve, str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT));
	}

	/** the secret bytes, for sealing */
	public function secret(): string {
		return $this->secret;
	}

	public function publicKey(): PublicKey {
		if ($this->public === null) {
			$details = openssl_pkey_get_details($this->opensslKey());
			if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
				throw new RuntimeException('OpenSSL did not derive the public key');
			}
			$this->public = new PublicKey(
				$this->curve,
				str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT),
				str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT),
			);
		}

		return $this->public;
	}

	public function didKey(): string {
		return $this->publicKey()->didKey();
	}

	/**
	 * Signs SHA-256 of $message: raw r || s, 64 bytes, s in its low form.
	 */
	public function sign(string $message): string {
		$der = '';
		if (!openssl_sign($message, $der, $this->opensslKey(), OPENSSL_ALGO_SHA256)) {
			throw new RuntimeException('OpenSSL could not sign: ' . (string)openssl_error_string());
		}
		[$r, $s] = Der::parseSignature($der);
		if ($s->compare($this->curve->halfOrder()) > 0) {
			$s = $this->curve->order()->subtract($s);
		}

		return $r->toBytes(32) . $s->toBytes(32);
	}

	private function opensslKey(): OpenSSLAsymmetricKey {
		if ($this->handle === null) {
			$key = openssl_pkey_new(['ec' => ['curve_name' => $this->curve->opensslName(), 'd' => $this->secret]]);
			if ($key === false) {
				throw new RuntimeException('OpenSSL rejected the private key: ' . (string)openssl_error_string());
			}
			$this->handle = $key;
		}

		return $this->handle;
	}
}
