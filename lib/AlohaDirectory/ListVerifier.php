<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AlohaDirectory;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use JsonException;
use OCA\Social\Atproto\Protocol\Encoding;

/**
 * Verifies the lists the Aloha Directory publishes, the way every client has
 * to (the directory's `docs/lists.md`):
 *
 * 1. `keys.json` is signed by one of the pinned root keys and names the list keys;
 * 2. `servers.json` and `search.json` are signed by one of those list keys;
 * 3. a serial is accepted only when it is higher than the last one, so an old
 *    list, one that still contained a server since removed, cannot be replayed;
 * 4. expiry is reported, not enforced: an expired list is still used, marked old.
 *
 * Only the signature makes a list trustworthy, so it may come from any mirror.
 */
class ListVerifier {
	private const MAX_ENVELOPE_BYTES = 2_000_000;
	private const MAX_SIGNATURES = 8;
	private const CLOCK_SKEW_SECONDS = 300;
	private const TOO_OLD_DAYS = 90;

	/**
	 * @param array<string, string> $rootKeys kid => raw Ed25519 public key
	 *
	 * @return array{payload: array<string, mixed>, listKeys: array<string, string>}
	 */
	public function verifyKeySet(string $json, array $rootKeys, ?int $lastSerial, DateTimeImmutable $now): array {
		$payload = $this->verifyEnvelope($json, $rootKeys, 'keys', $lastSerial, $now);

		$keys = $payload['keys'] ?? null;
		if (!is_array($keys) || !array_is_list($keys)) {
			throw new ListVerificationException('The key set has no keys');
		}
		$listKeys = [];
		foreach ($keys as $key) {
			if (!is_array($key) || !is_string($key['kid'] ?? null) || !is_string($key['public_key'] ?? null)) {
				throw new ListVerificationException('The key set has a malformed key');
			}
			if (($key['alg'] ?? null) !== 'Ed25519' || ($key['status'] ?? null) !== 'active') {
				continue;
			}
			if (isset($key['not_after']) && (!is_string($key['not_after']) || $this->date($key['not_after']) < $now)) {
				continue;
			}
			$listKeys[$key['kid']] = self::publicKey($key['public_key']);
		}
		if ($listKeys === []) {
			throw new ListVerificationException('The key set has no active list key');
		}

		return ['payload' => $payload, 'listKeys' => $listKeys];
	}

	/**
	 * @param array<string, string> $listKeys kid => raw public key, from verifyKeySet()
	 * @param 'servers'|'search' $list
	 *
	 * @return array{payload: array<string, mixed>, expired: bool, tooOld: bool}
	 */
	public function verifyList(string $json, array $listKeys, string $list, ?int $lastSerial, DateTimeImmutable $now): array {
		$payload = $this->verifyEnvelope($json, $listKeys, $list, $lastSerial, $now);
		$issued = $this->date((string)$payload['issued_at']);

		return [
			'payload' => $payload,
			'expired' => $this->date((string)$payload['expires_at']) < $now,
			'tooOld' => $issued->modify('+' . self::TOO_OLD_DAYS . ' days') < $now,
		];
	}

	/** A pinned or listed public key: base64url of 32 raw bytes. */
	public static function publicKey(string $encoded): string {
		try {
			$key = Encoding::base64UrlDecode($encoded);
		} catch (InvalidArgumentException) {
			throw new ListVerificationException('A public key is not base64url');
		}
		if (strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			throw new ListVerificationException('A public key is not 32 bytes long');
		}

		return $key;
	}

	/**
	 * @param array<string, string> $trustedKeys
	 *
	 * @return array<string, mixed>
	 */
	private function verifyEnvelope(string $json, array $trustedKeys, string $list, ?int $lastSerial, DateTimeImmutable $now): array {
		if (strlen($json) > self::MAX_ENVELOPE_BYTES) {
			throw new ListVerificationException('The list is too large');
		}
		try {
			$envelope = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new ListVerificationException('The list is not JSON');
		}
		if (!is_array($envelope) || !is_string($envelope['payload'] ?? null) || !is_array($envelope['signatures'] ?? null)) {
			throw new ListVerificationException('The list is not a signed envelope');
		}
		$signatures = $envelope['signatures'];
		if ($signatures === [] || count($signatures) > self::MAX_SIGNATURES || !array_is_list($signatures)) {
			throw new ListVerificationException('The list has a wrong number of signatures');
		}
		try {
			$payloadBytes = Encoding::base64UrlDecode($envelope['payload']);
		} catch (InvalidArgumentException) {
			throw new ListVerificationException('The payload is not base64url');
		}

		if (!$this->signedByOneOf($payloadBytes, $signatures, $trustedKeys)) {
			throw new ListVerificationException('The list has no valid signature from a trusted key');
		}

		try {
			$payload = json_decode($payloadBytes, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new ListVerificationException('The payload is not JSON');
		}
		if (!is_array($payload)) {
			throw new ListVerificationException('The payload is not an object');
		}
		if (($payload['list'] ?? null) !== $list) {
			throw new ListVerificationException('Expected the list "' . $list . '"');
		}
		if (($payload['version'] ?? null) !== 1) {
			throw new ListVerificationException('Unsupported list version');
		}
		if (!is_int($payload['serial'] ?? null) || $payload['serial'] < 1) {
			throw new ListVerificationException('The list has no serial');
		}
		if ($lastSerial !== null && $payload['serial'] <= $lastSerial) {
			throw new ListVerificationException('The serial is not higher than the last accepted one');
		}
		if (!is_string($payload['issued_at'] ?? null) || !is_string($payload['expires_at'] ?? null)) {
			throw new ListVerificationException('The list has no timestamps');
		}
		$this->date($payload['expires_at']);
		if ($this->date($payload['issued_at'])->getTimestamp() > $now->getTimestamp() + self::CLOCK_SKEW_SECONDS) {
			throw new ListVerificationException('The list was issued in the future');
		}

		return $payload;
	}

	/**
	 * @param list<mixed> $signatures
	 * @param array<string, string> $trustedKeys
	 */
	private function signedByOneOf(string $payload, array $signatures, array $trustedKeys): bool {
		foreach ($signatures as $signature) {
			if (!is_array($signature) || !is_string($signature['kid'] ?? null) || !is_string($signature['sig'] ?? null)) {
				throw new ListVerificationException('The list has a malformed signature');
			}
			// an unknown or revoked key is skipped: another signature may still be valid
			$key = $trustedKeys[$signature['kid']] ?? null;
			if (($signature['alg'] ?? null) !== 'Ed25519' || $key === null) {
				continue;
			}
			try {
				$sig = Encoding::base64UrlDecode($signature['sig']);
			} catch (InvalidArgumentException) {
				continue;
			}
			if (strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
				&& sodium_crypto_sign_verify_detached($sig, $payload, $key)) {
				return true;
			}
		}

		return false;
	}

	private function date(string $value): DateTimeImmutable {
		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			throw new ListVerificationException('The list has an invalid timestamp');
		}
	}
}
