<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Model\InstanceKey;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Security\PrivateKeyCipher;

/**
 * The instance's own keys: the rotation key listed first on every account's
 * DID, and the service key its did:web document names. Each is made on
 * first need and held sealed with the instance secret.
 *
 * A rotated rotation key stays, retired, for the PLC's 72-hour window — an
 * operation signed with it is still good until then — and is dropped after.
 */
class InstanceKeyService {
	/** how long a retired rotation key is kept: the PLC recovery window */
	public const RETIRED_KEY_LIFETIME = 72 * 3600;

	/** @var array<string, PrivateKey> */
	private array $opened = [];

	public function __construct(
		private AtprotoIdentityRequest $identityRequest,
		private PrivateKeyCipher $cipher,
	) {
	}

	public function rotationKey(): PrivateKey {
		return $this->current(InstanceKey::KIND_ROTATION);
	}

	public function serviceKey(): PrivateKey {
		return $this->current(InstanceKey::KIND_SERVICE);
	}

	/** when the rotation key in use was made, 0 when there is none yet */
	public function rotationKeyAge(): int {
		foreach ($this->identityRequest->getInstanceKeys(InstanceKey::KIND_ROTATION) as $key) {
			if ($key->retired === 0) {
				return $key->creation;
			}
		}

		return 0;
	}

	/**
	 * Makes a new rotation key and retires the one in use.
	 *
	 * @return array{0: PrivateKey, 1: PrivateKey} the new key and the old one, which still signs the handover
	 */
	public function rotate(): array {
		$old = $this->rotationKey();
		$keys = $this->identityRequest->getInstanceKeys(InstanceKey::KIND_ROTATION);
		foreach ($keys as $key) {
			if ($key->retired === 0) {
				$this->identityRequest->retireInstanceKey($key->id);
			}
		}
		unset($this->opened[InstanceKey::KIND_ROTATION]);
		$new = $this->create(InstanceKey::KIND_ROTATION);

		return [$new, $old];
	}

	/**
	 * Drops retired keys past the window.
	 *
	 * @return int how many went
	 */
	public function pruneRetired(int $now): int {
		$dropped = 0;
		foreach ($this->identityRequest->getInstanceKeys(InstanceKey::KIND_ROTATION) as $key) {
			if ($key->retired !== 0 && $key->retired < $now - self::RETIRED_KEY_LIFETIME) {
				$this->identityRequest->deleteInstanceKey($key->id);
				$dropped++;
			}
		}

		return $dropped;
	}

	/**
	 * @throws AtprotoException when a stored key cannot be opened
	 */
	private function current(string $kind): PrivateKey {
		if (isset($this->opened[$kind])) {
			return $this->opened[$kind];
		}
		foreach ($this->identityRequest->getInstanceKeys($kind) as $key) {
			if ($key->retired !== 0) {
				continue;
			}
			$secret = $this->cipher->open($key->sealedPrivateKey);
			if ($secret === '') {
				throw new AtprotoException('The instance ' . $kind . ' key cannot be opened: the instance secret changed');
			}

			return $this->opened[$kind] = new PrivateKey(Curve::K256, $secret);
		}

		return $this->create($kind);
	}

	private function create(string $kind): PrivateKey {
		$key = PrivateKey::generate(Curve::K256);
		$this->identityRequest->createInstanceKey($kind, $this->cipher->seal($key->secret()), $key->didKey());

		return $this->opened[$kind] = $key;
	}
}
