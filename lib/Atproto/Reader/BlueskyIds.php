<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Protocol\Syntax;

/**
 * The ids Bluesky things carry here. Every id in this app is an https URL —
 * `id_prim`, the import validation and the origin checks all say so — and
 * an `at://` URI is none, so a Bluesky account is `https://bsky.app/profile/<did>`
 * and a post `https://bsky.app/profile/<did>/post/<rkey>`: the addresses
 * the Bluesky web app answers for them, by DID so a handle change moves
 * nothing. The `at://` URI travels in `details.atproto`.
 */
final class BlueskyIds {
	public const HOST = 'bsky.app';
	private const ORIGIN = 'https://bsky.app';
	public const POST = 'app.bsky.feed.post';

	public static function actorId(string $did): string {
		return self::ORIGIN . '/profile/' . $did;
	}

	public static function postId(string $did, string $rkey): string {
		return self::actorId($did) . '/post/' . $rkey;
	}

	/** The followers collection a follow row points at; nothing is served there. */
	public static function followersId(string $did): string {
		return self::actorId($did) . '/followers';
	}

	public static function isActorId(string $id): bool {
		return preg_match('#^https://bsky\.app/profile/(did:[a-z]+:[A-Za-z0-9._:%-]+)$#', $id, $m) === 1 && Syntax::isDid($m[1]);
	}

	public static function isPostId(string $id): bool {
		return self::parsePostId($id) !== null;
	}

	/** The DID of an actor or post id, '' for any other id. */
	public static function didOf(string $id): string {
		if (preg_match('#^https://bsky\.app/profile/(did:[a-z]+:[A-Za-z0-9._:%-]+)(?:/|$)#', $id, $m) === 1 && Syntax::isDid($m[1])) {
			return $m[1];
		}

		return '';
	}

	/**
	 * @return array{did: string, rkey: string}|null
	 */
	public static function parsePostId(string $id): ?array {
		if (preg_match('#^https://bsky\.app/profile/(did:[a-z]+:[A-Za-z0-9._:%-]+)/post/([a-zA-Z0-9._~-]{1,512})$#', $id, $m) === 1 && Syntax::isDid($m[1])) {
			return ['did' => $m[1], 'rkey' => $m[2]];
		}

		return null;
	}

	public static function atUri(string $did, string $collection, string $rkey): string {
		return 'at://' . $did . '/' . $collection . '/' . $rkey;
	}

	/** The post id of an `at://` post URI, '' when it is not one. */
	public static function postIdOfUri(string $uri): string {
		$parsed = Syntax::parseAtUri($uri);
		if ($parsed === null || $parsed['collection'] !== self::POST || $parsed['rkey'] === '' || !Syntax::isDid($parsed['authority'])) {
			return '';
		}

		return self::postId($parsed['authority'], $parsed['rkey']);
	}

	/** The page on bsky.app, by handle so people see a name. */
	public static function profileUrl(string $handleOrDid): string {
		return self::ORIGIN . '/profile/' . $handleOrDid;
	}

	public static function postUrl(string $handleOrDid, string $rkey): string {
		return self::profileUrl($handleOrDid) . '/post/' . $rkey;
	}

	/**
	 * Whether an account string is a Bluesky handle rather than a Fediverse
	 * address: no `@` and a domain shape with a real top-level domain.
	 */
	public static function isHandle(string $account): bool {
		return !str_contains($account, '@') && str_contains($account, '.') && Syntax::isResolvableHandle(strtolower($account));
	}
}
