<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

/**
 * OAuth client secrets, authorization codes and access tokens are stored as
 * `sha256:<hex>` digests, so a database read no longer hands out working
 * credentials. The values are high-entropy random tokens, so a fast unsalted
 * digest is the right tool — it also keeps the token lookup a plain indexed
 * equality query.
 *
 * Rows written before hashing existed held the bare value; migration
 * `Version1000Date20261008000100` hashed what was left of them, or took it
 * back, and nothing here accepts a bare stored value any more.
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
	 * What a presented secret is stored under, for looking it up.
	 *
	 * A presented secret that already carries the hash prefix is refused
	 * rather than hashed again: the prefixed shape is what the column holds,
	 * and accepting it would make a database dump, a backup or a read-only SQL
	 * flaw hand out working tokens — the one thing hashing them is for. Null
	 * for that and for an empty one.
	 */
	public function forLookup(string $secret): ?string {
		if ($secret === '' || $this->isHashed($secret)) {
			return null;
		}

		return $this->hash($secret);
	}
}
