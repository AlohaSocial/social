<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

/**
 * The identifier grammars of AT Protocol, as the specifications and the
 * interop syntax fixtures define them. Every string that comes from outside
 * passes through one of these before it is used.
 */
final class Syntax {
	private const DISALLOWED_HANDLE_TLDS = ['alt', 'arpa', 'example', 'internal', 'invalid', 'local', 'localhost', 'onion'];

	/**
	 * A handle: a hostname of at least two labels, letters, digits and
	 * hyphens, no label over 63, no more than 253 in all, a TLD that does
	 * not start with a digit. Syntax only; see isResolvableHandle().
	 */
	public static function isHandle(string $handle): bool {
		return strlen($handle) <= 253
			&& preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $handle) === 1;
	}

	/**
	 * A handle this app will try to resolve: valid syntax on a top-level
	 * domain that can exist on the network. `.test` is kept, for the
	 * interop job; `handle.invalid` is what an unverified account shows as.
	 */
	public static function isResolvableHandle(string $handle): bool {
		if (!self::isHandle($handle)) {
			return false;
		}
		$tld = strtolower(substr($handle, (int)strrpos($handle, '.') + 1));

		return !in_array($tld, self::DISALLOWED_HANDLE_TLDS, true);
	}

	public static function normalizeHandle(string $handle): string {
		return strtolower($handle);
	}

	/**
	 * A DID: `did:<method>:<id>`, lower-case method, id of the allowed
	 * characters, not ending in a colon, at most 2 KB.
	 */
	public static function isDid(string $did): bool {
		return strlen($did) <= 2048 && preg_match('/^did:[a-z]+:[a-zA-Z0-9._:%-]*[a-zA-Z0-9._-]$/', $did) === 1;
	}

	/** `did:plc:` and 24 base32 characters */
	public static function isDidPlc(string $did): bool {
		return preg_match('/^did:plc:[a-z2-7]{24}$/', $did) === 1;
	}

	/**
	 * A namespaced identifier: a reversed domain authority of at least two
	 * segments, then a name that starts with a letter; 317 characters at most.
	 */
	public static function isNsid(string $nsid): bool {
		if (strlen($nsid) > 317) {
			return false;
		}
		$segments = explode('.', $nsid);
		if (count($segments) < 3) {
			return false;
		}
		$name = array_pop($segments);
		if (preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,62}$/', $name) !== 1) {
			return false;
		}
		foreach ($segments as $i => $segment) {
			if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $segment) !== 1) {
				return false;
			}
			if ($i === 0 && ctype_digit($segment[0])) {
				return false;
			}
		}

		return true;
	}

	/** 1 to 512 characters of `[a-zA-Z0-9._:~-]`, and not `.` or `..` */
	public static function isRecordKey(string $rkey): bool {
		return $rkey !== '.' && $rkey !== '..' && preg_match('/^[a-zA-Z0-9._:~-]{1,512}$/', $rkey) === 1;
	}

	/**
	 * `at://<authority>[/<collection>[/<rkey>]]`, no query or fragment
	 * (those exist in the grammar but never in a record reference).
	 *
	 * @return array{authority: string, collection: string, rkey: string}|null the parts, or null when it is not one
	 */
	public static function parseAtUri(string $uri): ?array {
		if (strlen($uri) > 8192 || preg_match('#^at://([^/?\#]+)(?:/([^/?\#]+)(?:/([^/?\#]+))?)?$#', $uri, $m) !== 1) {
			return null;
		}
		$authority = $m[1];
		if (!self::isDid($authority) && !self::isHandle($authority)) {
			return null;
		}
		$collection = $m[2] ?? '';
		$rkey = $m[3] ?? '';
		if ($collection !== '' && !self::isNsid($collection)) {
			return null;
		}
		if ($rkey !== '' && !self::isRecordKey($rkey)) {
			return null;
		}

		return ['authority' => $authority, 'collection' => $collection, 'rkey' => $rkey];
	}

	public static function isAtUri(string $uri): bool {
		return self::parseAtUri($uri) !== null;
	}

	public static function atUri(string $did, string $collection, string $rkey): string {
		return 'at://' . $did . '/' . $collection . '/' . $rkey;
	}

	/**
	 * An RFC 3339 datetime as records carry it: full date and time, a
	 * fraction is allowed, a zone is required (`Z` or an offset).
	 */
	public static function isDatetime(string $value): bool {
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])T([01]\d|2[0-3]):[0-5]\d:([0-5]\d|60)(\.\d+)?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/', $value) !== 1) {
			return false;
		}
		if (str_ends_with($value, '-00:00')) {
			return false;
		}

		return true;
	}

	/** the moment, as a record states it */
	public static function datetime(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z';
	}

	/**
	 * A BCP 47 language tag, loosely: what `langs` on a post may carry.
	 */
	public static function isLanguage(string $tag): bool {
		return preg_match('/^([a-z]{2,3}|[iIxX])(-[a-zA-Z0-9]{1,8})*$/', $tag) === 1 && strlen($tag) <= 128;
	}
}
