<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

/**
 * OAuth client secrets, authorization codes and access tokens are stored as
 * `sha256:<hex>` digests, so a database read hands out no working
 * credentials. They are high-entropy random tokens, so a fast unsalted
 * digest suffices and lookups stay indexed equality queries. Bare stored
 * values are not accepted.
 */
class SecretHasher {
	public const PREFIX = 'sha256:';

	public function hash(string $secret): string {
		if ($secret === '') {
			return '';
		}

		return self::PREFIX . hash('sha256', $secret);
	}

	public function isHashed(string $stored): bool {
		return str_starts_with($stored, self::PREFIX);
	}

	/**
	 * Whether a presented secret matches the stored digest.
	 */
	public function matches(string $stored, string $presented): bool {
		if ($presented === '' || !$this->isHashed($stored)) {
			return false;
		}

		return hash_equals($stored, $this->hash($presented));
	}

	/**
	 * What a presented secret is stored under, for looking it up. A secret that
	 * already carries the hash prefix is refused, or a database dump would hand
	 * out working tokens; null for that and for an empty one.
	 */
	public function forLookup(string $secret): ?string {
		if ($secret === '' || $this->isHashed($secret)) {
			return null;
		}

		return $this->hash($secret);
	}
}
