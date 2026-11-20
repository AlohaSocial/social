<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

/**
 * The rotation keys of the operations this server signs. A DID moving here
 * (§13.1) lists the person's own keys first and this server's last; later
 * operations keep the head's order and change only this server's key and the
 * recovery key (§4.5). A higher-ranked key can undo, for 72 hours, an
 * operation a lower one signed.
 */
final class RotationKeys {
	/** the most rotation keys a did:plc may list */
	public const MAX = 5;

	/**
	 * @param mixed $listed the DID's rotation keys now, as the directory has them
	 * @param list<string> $oldServer the old server's own keys, left out
	 * @param string $recovery the person's recovery key here, '' for none
	 * @param string $here this server's rotation key
	 * @return list<string>
	 */
	public static function forMoveHere(mixed $listed, array $oldServer, string $recovery, string $here): array {
		$own = $recovery !== '' ? [$recovery] : [];
		foreach (is_array($listed) ? $listed : [] as $key) {
			if (is_string($key) && str_starts_with($key, 'did:key:') && $key !== $here
				&& !in_array($key, $oldServer, true) && !in_array($key, $own, true)) {
				$own[] = $key;
			}
		}

		return [...array_slice($own, 0, self::MAX - 1), $here];
	}

	/**
	 * The keys an operation this server signs carries: the head's, in
	 * their order, each key $replace names put in its place — a new key
	 * where the old one stood, or dropped for '' — and $add, when the head
	 * does not list it, placed next to this server's key: above it where a
	 * key of the person already ranks above this server, below it where
	 * this server's key is first, last where the head lists no key of this
	 * server.
	 *
	 * @param mixed $head the rotation keys of the operation this one follows
	 * @param array<string, string> $replace old did:key => new did:key, or '' to drop it
	 * @param string $ours this server's rotation key in use
	 * @param string $add a key to list, '' for none
	 * @return list<string>
	 * @throws \InvalidArgumentException when adding $add would list more than MAX keys
	 */
	public static function carry(mixed $head, array $replace, string $ours, string $add = ''): array {
		$keys = [];
		foreach (self::didKeys($head) as $key) {
			$key = array_key_exists($key, $replace) ? $replace[$key] : $key;
			if ($key !== '' && !in_array($key, $keys, true)) {
				$keys[] = $key;
			}
		}
		if ($add === '' || in_array($add, $keys, true)) {
			return $keys;
		}
		if (count($keys) >= self::MAX) {
			throw new \InvalidArgumentException('The DID lists ' . self::MAX . ' rotation keys already');
		}
		$position = array_search($ours, $keys, true);
		if ($position === false) {
			$keys[] = $add;
		} else {
			array_splice($keys, $position === 0 ? 1 : $position, 0, [$add]);
		}

		return $keys;
	}

	/**
	 * The keys of an operation this server built on $base, carried onto
	 * $head when the directory moved on in between: what it changed —
	 * keys it put in another's place, keys it dropped, a key it added —
	 * applied to $head's keys as carry() applies it.
	 *
	 * @return list<string>
	 */
	public static function rebase(mixed $base, mixed $mine, mixed $head, string $ours): array {
		$base = self::didKeys($base);
		$mine = self::didKeys($mine);
		$removed = array_values(array_diff($base, $mine));
		$added = array_values(array_diff($mine, $base));
		$replace = [];
		foreach ($removed as $i => $key) {
			$replace[$key] = $added[$i] ?? '';
		}
		$keys = self::carry($head, $replace, $ours);
		foreach (array_slice($added, count($removed)) as $key) {
			try {
				$keys = self::carry($keys, [], $ours, $key);
			} catch (\InvalidArgumentException) {
				break;
			}
		}

		return $keys;
	}

	/**
	 * The did:keys of a list a server answered.
	 *
	 * @return list<string>
	 */
	public static function didKeys(mixed $listed): array {
		return array_values(array_filter(is_array($listed) ? $listed : [], static fn ($key): bool => is_string($key) && str_starts_with($key, 'did:key:')));
	}
}
