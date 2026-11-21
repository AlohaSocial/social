<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

use Exception;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Encrypts actor private keys at rest with the instance secret (ICrypto), so
 * a database dump alone cannot impersonate local actors. Legacy rows hold the
 * bare PEM, recognised by '-----BEGIN', until the EncryptPrivateKeys repair
 * step rewrites them.
 */
class PrivateKeyCipher {
	private const PEM_PREFIX = '-----BEGIN';

	private ICrypto $crypto;
	private LoggerInterface $logger;

	public function __construct(ICrypto $crypto, LoggerInterface $logger) {
		$this->crypto = $crypto;
		$this->logger = $logger;
	}

	/**
	 * The value to store for a private key.
	 */
	public function seal(string $privateKey): string {
		if ($privateKey === '') {
			return '';
		}

		return $this->crypto->encrypt($privateKey);
	}

	/**
	 * The private key held in a stored value — sealed, legacy plaintext or empty.
	 * An undecryptable value (the instance secret changed) yields '' so signing
	 * fails visibly instead of sending garbage signatures.
	 */
	public function open(string $stored): string {
		if ($stored === '' || $this->isPlain($stored)) {
			return $stored;
		}

		try {
			return $this->crypto->decrypt($stored);
		} catch (Exception $e) {
			$this->logger->error('Social: cannot decrypt an actor private key', ['exception' => $e]);

			return '';
		}
	}

	/**
	 * Whether a stored value opens: empty, legacy plaintext, or sealed with
	 * the secret in use. Nothing is logged for one that does not.
	 */
	public function opens(string $stored): bool {
		if ($stored === '' || $this->isPlain($stored)) {
			return true;
		}
		try {
			$this->crypto->decrypt($stored);

			return true;
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * The stored value sealed with the current secret, for one sealed with
	 * $oldSecret. A value that already opens is sealed afresh; one neither
	 * secret opens yields ''.
	 */
	public function reseal(string $stored, #[\SensitiveParameter] string $oldSecret): string {
		if ($stored === '') {
			return '';
		}
		$plain = $this->isPlain($stored) ? $stored : null;
		if ($plain === null) {
			try {
				$plain = $this->crypto->decrypt($stored);
			} catch (Exception) {
			}
		}
		if ($plain === null && $oldSecret !== '') {
			try {
				$plain = $this->crypto->decrypt($stored, $oldSecret);
			} catch (Exception) {
			}
		}

		return $plain === null ? '' : $this->seal($plain);
	}

	public function isPlain(string $stored): bool {
		return str_starts_with($stored, self::PEM_PREFIX);
	}
}
