<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where a post is on Bluesky, for a record that points at it — a reply, a
 * quote, a like, a repost. A local post that was published is its record;
 * a Bluesky post read here carries its URI and CID; anything else is not
 * on Bluesky. A reply also needs its thread's root, which is the parent's
 * own root when the parent is a reply.
 */
class PostRefs {
	public function __construct(
		private RepositoryService $repositories,
		private StreamRequest $streams,
		private AppViewClient $appView,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{uri: string, cid: string}|null
	 */
	public function strongRef(string $postId): ?array {
		if ($postId === '') {
			return null;
		}
		foreach ($this->repositories->getRecordsByLocalId($postId) as $record) {
			if ($record->collection === RecordMapper::POST) {
				return ['uri' => $record->uri(), 'cid' => $record->cid->toString()];
			}
		}
		if (!BlueskyIds::isPostId($postId)) {
			return null;
		}
		$details = $this->blueskyDetails($postId);
		$uri = (string)($details['uri'] ?? '');
		$cid = (string)($details['cid'] ?? '');

		return (Syntax::isAtUri($uri) && Cid::isValid($cid)) ? ['uri' => $uri, 'cid' => $cid] : null;
	}

	/**
	 * `reply.root` and `reply.parent` for a reply to a post that is on
	 * Bluesky, or null when the parent is not there.
	 *
	 * @return array{root: array{uri: string, cid: string}, parent: array{uri: string, cid: string}}|null
	 */
	public function replyRefs(string $parentId): ?array {
		$parent = $this->strongRef($parentId);
		if ($parent === null) {
			return null;
		}

		return ['root' => $this->rootOf($parentId, $parent) ?? $parent, 'parent' => $parent];
	}

	/**
	 * The root of the thread the parent is in: what its own record says
	 * when it is ours, what was stored with it when it was read here, and
	 * otherwise what the AppView says of it. Null when the parent is a root.
	 *
	 * @param array{uri: string, cid: string} $parent
	 * @return array{uri: string, cid: string}|null
	 */
	private function rootOf(string $parentId, array $parent): ?array {
		foreach ($this->repositories->getRecordsByLocalId($parentId) as $record) {
			if ($record->collection === RecordMapper::POST) {
				return self::ref($record->value()['reply']['root'] ?? null);
			}
		}
		$stored = self::ref($this->blueskyDetails($parentId)['reply_root'] ?? null);
		if ($stored !== null) {
			return $stored;
		}
		try {
			$answer = $this->appView->query('app.bsky.feed.getPosts', ['uris' => [$parent['uri']]]);

			return self::ref($answer['posts'][0]['record']['reply']['root'] ?? null);
		} catch (Throwable $e) {
			$this->logger->notice('Reply root not read from the AppView; the parent stands in', ['parent' => $parent['uri'], 'exception' => $e]);

			return null;
		}
	}

	private function blueskyDetails(string $postId): array {
		try {
			return $this->streams->getStreamById($postId)->getDetails(PostMapper::DETAIL);
		} catch (StreamNotFoundException) {
			return [];
		}
	}

	/**
	 * @return array{uri: string, cid: string}|null
	 */
	private static function ref(mixed $value): ?array {
		if (!is_array($value) || !is_string($value['uri'] ?? null) || !is_string($value['cid'] ?? null)) {
			return null;
		}
		if (!Syntax::isAtUri($value['uri']) || !Cid::isValid($value['cid'])) {
			return null;
		}

		return ['uri' => $value['uri'], 'cid' => $value['cid']];
	}
}
