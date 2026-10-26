<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Profile;

use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Xrpc\XrpcException;

/** Ordered profile links, kept in their native records rather than four Fediverse fields. */
class ProfileLinks {
	public const COLLECTION = 'app.bsky.actor.link';
	public const MAX_LINKS = 10;

	public function __construct(
		private RepositoryService $repositories,
	) {
	}

	/** @return list<array{uri: string, cid: string}> */
	public static function references(string $did, mixed $links): array {
		if (!is_array($links) || !array_is_list($links) || count($links) > self::MAX_LINKS) {
			throw XrpcException::invalidRequest('Profile links must be a list of at most ten record references');
		}
		$refs = [];
		foreach ($links as $link) {
			$uri = is_array($link) ? ($link['uri'] ?? null) : null;
			$cid = is_array($link) ? ($link['cid'] ?? null) : null;
			$parsed = is_string($uri) ? Syntax::parseAtUri($uri) : null;
			if ($parsed === null || $parsed['authority'] !== $did || $parsed['collection'] !== self::COLLECTION
				|| !Tid::isValid($parsed['rkey']) || !is_string($cid) || !Cid::isValid($cid)) {
				throw XrpcException::invalidRequest('Each profile link must reference a link record in this account repository');
			}
			$refs[] = ['uri' => $uri, 'cid' => $cid];
		}

		return $refs;
	}

	/** Read only the selected versions, in profile order; no destination or icon is fetched. */
	public function forIdentity(Identity $identity): array {
		$profile = $this->repositories->getRecord($identity->did, RecordMapper::PROFILE, RecordMapper::PROFILE_RKEY);
		$links = [];
		foreach (self::references($identity->did, $profile?->value()['links'] ?? []) as $reference) {
			$parsed = Syntax::parseAtUri($reference['uri']);
			if ($parsed === null) {
				continue;
			}
			$record = $this->repositories->getRecord($identity->did, self::COLLECTION, $parsed['rkey']);
			if ($record !== null && $record->cid->toString() === $reference['cid']) {
				$links[] = ['uri' => $record->uri(), 'cid' => $record->cid->toString()] + $record->value();
			}
		}

		return self::views($links);
	}

	/** Local records and AppView profileLinkViews share the same visible fields. */
	public static function views(mixed $links): array {
		$views = [];
		foreach (is_array($links) ? array_slice($links, 0, self::MAX_LINKS) : [] as $link) {
			if (!is_array($link) || !is_string($link['url'] ?? null)) {
				continue;
			}
			$url = trim($link['url']);
			$parts = parse_url($url);
			if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
				|| isset($parts['user']) || isset($parts['pass']) || filter_var($url, FILTER_VALIDATE_URL) === false) {
				continue;
			}
			$labels = $link['labels'] ?? [];
			if (is_array($labels) && is_array($labels['values'] ?? null)) {
				$labels = $labels['values'];
			}
			if (is_array($labels) && array_intersect(['!hide', '!takedown'], array_column($labels, 'val')) !== []) {
				continue;
			}
			$title = is_string($link['title'] ?? null) ? trim(strip_tags($link['title'])) : '';
			$views[] = [
				'url' => $url,
				'title' => $title === '' ? $parts['host'] : mb_substr($title, 0, 320),
			];
		}

		return $views;
	}
}
