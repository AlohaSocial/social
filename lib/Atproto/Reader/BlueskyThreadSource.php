<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\Thread\ThreadSource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A conversation on Bluesky as the AppView has it (`getPostThread`), each post
 * not held here stored like any Bluesky post (`PostStore`), parents first so
 * every reply's parent is there before it.
 */
class BlueskyThreadSource implements ThreadSource {
	/** how deep the replies, and how high the parents, are asked for */
	private const DEPTH = 10;
	private const PARENT_HEIGHT = 20;
	/** Keep a badly branched conversation from turning one job into an unbounded crawl. */
	private const MAX_CONTINUATION_READS = 20;
	private const CONTINUATIONS = 'social.atproto.thread.continuations';
	private const SEARCH_CURSORS = 'social.atproto.thread.search-cursors';
	private const CONTINUATION_TTL = 3600;
	private const THREAD_VIEW = 'app.bsky.feed.defs#threadViewPost';
	private const NOT_FOUND_VIEW = 'app.bsky.feed.defs#notFoundPost';
	private const BLOCKED_VIEW = 'app.bsky.feed.defs#blockedPost';
	private bool $deepRepliesPending = false;
	/** @var list<string> direct replies recovered by the V2 read which have replies of their own */
	private array $missingReplyContinuations = [];

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PostRefs $refs,
		private PostStore $store,
		private LabelerService $labelers,
		private LoggerInterface $logger,
		private DurableCache $durableCache,
		private ?IdentityService $identities = null,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return $this->config->isEnabled() && $this->refs->strongRef($post->getId()) !== null;
	}

	#[\Override]
	public function fill(Stream $post, int $budget): int {
		return $this->fillWithTimeout($post, $budget, null);
	}

	/** Short bounded read for a thread currently being opened; background fills retain the normal timeout. */
	public function fillVisible(Stream $post, int $budget, ?Person $viewer = null): int {
		return $this->fillWithTimeout($post, $budget, AppViewClient::INTERACTIVE_TIMEOUT, $viewer);
	}

	/** Whether the last interactive read needs a background fill for omitted or depth-limited replies. */
	public function hasDeepRepliesPending(): bool {
		return $this->deepRepliesPending;
	}

	private function fillWithTimeout(Stream $post, int $budget, ?int $timeout, ?Person $viewer = null): int {
		$this->deepRepliesPending = false;
		$this->missingReplyContinuations = [];
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		if ($uri === '' || $budget <= 0) {
			return 0;
		}
		$identity = null;
		$key = null;
		if ($timeout !== null && $viewer !== null && $this->identities !== null) {
			try {
				$identity = $this->identities->activeForActor($viewer);
				$key = $identity === null ? null : $this->identities->signingKey($identity);
			} catch (Throwable $e) {
				// A missing or unreadable linked key should not make public replies
				// disappear; fall back to the public AppView for this read.
				$this->logger->info('Bluesky thread read could not use the linked account', ['exception' => $e]);
				$identity = null;
				$key = null;
			}
		}
		try {
			$params = ['uri' => $uri, 'depth' => self::DEPTH, 'parentHeight' => self::PARENT_HEIGHT];
			$headers = ['atproto-accept-labelers' => $this->labelers->acceptHeader()];
			// Count the AppView's network time, not local URI resolution, toward
			// the shared interactive request budget.
			$deadline = $timeout === null ? null : hrtime(true) + ($timeout * 1_000_000_000);
			$answer = $this->query('app.bsky.feed.getPostThread', $params, $headers, $timeout, $identity, $key);
			$thread = $answer['thread'] ?? null;
			if (!is_array($thread) || !in_array($thread['$type'] ?? null, [self::THREAD_VIEW, self::NOT_FOUND_VIEW, self::BLOCKED_VIEW], true)) {
				throw new \UnexpectedValueException('Bluesky thread response has no recognized thread view');
			}
			$this->assertThreadTarget($thread, $uri);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky thread not read', ['uri' => $uri, 'exception' => $e]);

			throw $e;
		}

		$stored = $this->storeThread($thread, $budget);
		// the replies its author hid on Bluesky are hidden here too
		$this->store->rememberHiddenReplies(is_array($answer['threadgate'] ?? null) ? $answer['threadgate'] : null);
		$threadRootUri = self::threadRootUri($post, $thread, $uri);
		foreach (self::nodesWithMissingDirectReplies($thread) as $missing) {
			if ($stored >= $budget) {
				$this->deepRepliesPending = $deadline !== null;

				break;
			}
			if ($this->remainingTimeout($deadline) === 0) {
				$this->deepRepliesPending = true;

				break;
			}
			$stored += $this->fillMissingDirectReplies(
				$missing['uri'],
				$threadRootUri,
				$headers,
				$deadline,
				$budget - $stored,
				$missing['knownReplies'],
				$missing['expectedReplies'],
				$identity,
				$key,
			);
		}

		// The public AppView caps getPostThread's reply depth at ten even when
		// a larger value is requested. A normal interactive read stays one short
		// request; the queued fill follows only branches that reached that cap,
		// so deeply nested replies arrive without making the opened page wait.
		if ($timeout === null) {
			$pending = array_merge($this->savedContinuations($uri), self::continuationsOf($thread, self::DEPTH), $this->missingReplyContinuations);
			$pending = array_values(array_unique($pending));
			$seen = [$uri => true];
			$reads = 0;
			$retry = [];
			while ($pending !== [] && $stored < $budget && $reads < self::MAX_CONTINUATION_READS) {
				$nextUri = array_shift($pending);
				if ($nextUri === '' || isset($seen[$nextUri])) {
					continue;
				}
				$seen[$nextUri] = true;
				$reads++;
				try {
					$answer = $this->query('app.bsky.feed.getPostThread', [
						'uri' => $nextUri,
						'depth' => self::DEPTH,
						'parentHeight' => self::PARENT_HEIGHT,
					], $headers, AppViewClient::INTERACTIVE_TIMEOUT, null, null);
					$continued = $answer['thread'] ?? null;
					if (!is_array($continued) || ($continued['$type'] ?? null) !== self::THREAD_VIEW) {
						continue;
					}
					$this->assertThreadTarget($continued, $nextUri);
					$stored += $this->storeThread($continued, $budget - $stored);
					$pending = array_merge($pending, self::continuationsOf($continued, self::DEPTH));
				} catch (Throwable $e) {
					// A deep branch can disappear or be unavailable independently of
					// the rest of the conversation.
					$this->logger->debug('A deep Bluesky reply branch was not read', ['uri' => $nextUri, 'exception' => $e]);
					$retry[] = $nextUri;
				}
			}
			$pending = array_values(array_unique(array_merge($pending, $retry)));
			$this->saveContinuations($uri, $pending);
			$this->deepRepliesPending = $this->deepRepliesPending || $pending !== [];
		} else {
			$this->deepRepliesPending = $this->deepRepliesPending || $this->savedContinuations($uri) !== [] || self::continuationsOf($thread, self::DEPTH) !== [] || $this->missingReplyContinuations !== [];
		}

		return $stored;
	}

	/** Reject a valid-looking AppView thread that belongs to another requested post. */
	private function assertThreadTarget(array $thread, string $expectedUri): void {
		$post = $thread['post'] ?? null;
		$actualUri = ($thread['$type'] ?? null) === self::THREAD_VIEW && is_array($post)
			? ($post['uri'] ?? null)
			: ($thread['uri'] ?? null);
		if (!is_string($actualUri) || $actualUri !== $expectedUri) {
			throw new \UnexpectedValueException('Bluesky thread response does not match the requested post URI');
		}
	}

	/** The stable thread endpoint can omit direct replies; V2 and search can recover them. */
	private function fillMissingDirectReplies(string $uri, string $threadRootUri, array $headers, ?int $deadline, int $budget, array $knownReplies, int $expectedReplies, ?Identity $identity, ?PrivateKey $key): int {
		$timeout = $this->remainingTimeout($deadline);
		if ($timeout === 0) {
			$this->deepRepliesPending = true;

			return 0;
		}
		try {
			$params = [
				'anchor' => $uri,
				'above' => 'false',
				'below' => 1,
				'branchingFactor' => 0,
				'sort' => 'oldest',
			];
			$answer = $this->query('app.bsky.unspecced.getPostThreadV2', $params, $headers, $timeout, $identity, $key);
			$items = $answer['thread'] ?? null;
			if (!is_array($items)) {
				$searched = $this->fillRepliesFromSearch($uri, $threadRootUri, $headers, $deadline, $budget, $knownReplies, $expectedReplies, $identity, $key);
				if ($searched !== null) {
					return $searched;
				}

				$this->deepRepliesPending = $this->deepRepliesPending || $timeout !== null;

				return 0;
			}
		} catch (Throwable $e) {
			// The V2 thread view is still unspecced and is absent on some AppViews.
			$this->logger->debug('Bluesky V2 thread view not available; keeping the standard thread response', ['uri' => $uri, 'exception' => $e]);
			// SearchPostsV2 is a specced, cursor-paged route. Use it to recover
			// replies when this older AppView has no thread V2 endpoint.
			$searched = $this->fillRepliesFromSearch($uri, $threadRootUri, $headers, $deadline, $budget, $knownReplies, $expectedReplies, $identity, $key);
			if ($searched !== null) {
				return $searched;
			}

			$this->deepRepliesPending = $this->deepRepliesPending || $timeout !== null;

			return 0;
		}

		$views = [];
		$directReplies = $knownReplies;
		foreach ($items as $item) {
			if (!is_array($item) || (int)($item['depth'] ?? -1) < 0) {
				continue;
			}
			$value = is_array($item['value'] ?? null) ? $item['value'] : [];
			if (($value['$type'] ?? '') !== 'app.bsky.unspecced.defs#threadItemPost' || !is_array($value['post'] ?? null)) {
				continue;
			}
			$post = $value['post'];
			$views[] = [(int)$item['depth'], $post];
			if ((int)$item['depth'] === 1 && is_string($post['uri'] ?? null)) {
				$directReplies[] = $post['uri'];
			}
			if ((int)($item['depth'] ?? -1) === 1 && (int)($post['replyCount'] ?? 0) > 0 && is_string($post['uri'] ?? null)
				&& !in_array($post['uri'], $knownReplies, true)) {
				$this->missingReplyContinuations[] = $post['uri'];
			}
		}
		$this->missingReplyContinuations = array_values(array_unique($this->missingReplyContinuations));
		$this->deepRepliesPending = $this->deepRepliesPending || ($deadline !== null && (
			($answer['hasOtherReplies'] ?? false) === true
			|| ($expectedReplies > 0 && count(array_unique($directReplies)) < $expectedReplies)
		));
		usort($views, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
		$stored = 0;
		foreach ($views as [, $view]) {
			if ($stored >= $budget) {
				break;
			}
			if ($this->store->storePost($view, false)) {
				$stored++;
			} else {
				$this->store->refreshKnownPostCountsFromView($view);
			}
		}
		$this->store->rememberHiddenReplies(is_array($answer['threadgate'] ?? null) ? $answer['threadgate'] : null);
		if ($expectedReplies > count(array_unique($directReplies)) && $stored < $budget) {
			// Some AppViews return a valid but truncated V2 page without setting
			// hasOtherReplies. The specced, cursor-paged search route can still
			// recover the rest of the thread.
			$searched = $this->fillRepliesFromSearch($uri, $threadRootUri, $headers, $deadline, $budget - $stored, $directReplies, $expectedReplies, $identity, $key);
			if ($searched !== null) {
				$stored += $searched;
			}
		}

		return $stored;
	}

	/** Read this thread as its viewer when they linked Bluesky; otherwise use the public AppView. */
	private function query(string $method, array $params, array $headers, ?int $timeout, ?Identity $identity, ?PrivateKey $key): array {
		if ($identity !== null && $key !== null) {
			return $this->appView->queryAs($identity->did, $key, $method, $params, $timeout, $headers);
		}

		return $timeout === null
			? $this->appView->query($method, $params, $headers)
			: $this->appView->query($method, $params, $headers, $timeout);
	}

	/**
	 * Recover replies through the specced search route when the experimental
	 * thread V2 route is unavailable or returns fewer direct replies than the
	 * stable thread view says exist.
	 *
	 * @return int|null stored new posts, or null when this AppView cannot answer
	 */
	private function fillRepliesFromSearch(string $uri, string $threadRootUri, array $headers, ?int $deadline, int $budget, array $knownReplies, int $expectedReplies, ?Identity $identity = null, ?PrivateKey $key = null): ?int {
		if ($budget <= 0) {
			$this->deepRepliesPending = $this->deepRepliesPending || $deadline !== null;

			return 0;
		}

		$direct = array_fill_keys($knownReplies, true);
		$cursorKey = self::searchCursorKey($threadRootUri, $uri);
		$cursor = $this->savedSearchCursor($cursorKey);
		$seenCursors = [];
		$stored = 0;
		$pages = 0;
		try {
			do {
				$timeout = $this->remainingTimeout($deadline);
				if ($timeout === 0) {
					$this->deepRepliesPending = true;
					$this->saveSearchCursor($cursorKey, $cursor);

					return $stored;
				}
				$params = [
					'threadRootUri' => $threadRootUri,
					'repliesOnly' => 'true',
					'allTime' => 'true',
					'sort' => 'recent',
					'limit' => min(100, max(1, $budget - $stored)),
				] + ($cursor === '' ? [] : ['cursor' => $cursor]);
				$answer = $this->query('app.bsky.feed.searchPostsV2', $params, $headers, $timeout, $identity, $key);
				$posts = $answer['posts'] ?? null;
				if (!is_array($posts)) {
					$this->deepRepliesPending = $this->deepRepliesPending || $cursor !== '';

					return null;
				}

				foreach ($posts as $view) {
					if (!is_array($view)) {
						continue;
					}
					$record = is_array($view['record'] ?? null) ? $view['record'] : [];
					$reply = is_array($record['reply'] ?? null) ? $record['reply'] : [];
					$root = is_array($reply['root'] ?? null) ? (string)($reply['root']['uri'] ?? '') : '';
					if ($root !== $threadRootUri) {
						continue;
					}
					$parent = is_array($reply['parent'] ?? null) ? (string)($reply['parent']['uri'] ?? '') : '';
					$postUri = (string)($view['uri'] ?? '');
					if ($parent === $uri && $postUri !== '') {
						$direct[$postUri] = true;
					}
					if ($this->store->storePost($view, false)) {
						$stored++;
					} else {
						$this->store->refreshKnownPostCountsFromView($view);
					}
					if ($stored >= $budget) {
						break;
					}
				}

				$next = $answer['cursor'] ?? '';
				if (!is_string($next)) {
					$this->deepRepliesPending = $this->deepRepliesPending || $cursor !== '';

					return null;
				}
				if ($next === '' || isset($seenCursors[$next])) {
					$cursor = '';
					break;
				}
				$seenCursors[$next] = true;
				$cursor = $next;
				$this->saveSearchCursor($cursorKey, $cursor);
				$pages++;
			} while ($deadline === null && $cursor !== '' && $stored < $budget && $pages < 20);
		} catch (Throwable $e) {
			$this->logger->debug('Bluesky thread search fallback not available', ['uri' => $uri, 'exception' => $e]);
			$this->deepRepliesPending = $this->deepRepliesPending || $cursor !== '';

			return null;
		}

		$this->deepRepliesPending = $this->deepRepliesPending || ($cursor !== ''
			|| ($deadline !== null && $expectedReplies > 0 && count($direct) < $expectedReplies));
		if ($cursor === '') {
			$this->saveSearchCursor($cursorKey, '');
		}

		return $stored;
	}

	/** Remaining whole seconds in the visible thread's shared request budget. */
	private function remainingTimeout(?int $deadline): ?int {
		if ($deadline === null) {
			return null;
		}
		$remaining = $deadline - hrtime(true);

		return $remaining <= 0 ? 0 : (int)floor($remaining / 1_000_000_000);
	}

	/** @return list<string> */
	private function savedContinuations(string $uri): array {
		try {
			$value = $this->durableCache->getShared(self::CONTINUATIONS, hash('sha256', $uri));
		} catch (Throwable $e) {
			$this->logger->debug('Bluesky thread continuations were not read from cache', ['uri' => $uri, 'exception' => $e]);

			return [];
		}

		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}

	/** @param list<string> $uris */
	private function saveContinuations(string $uri, array $uris): void {
		$key = hash('sha256', $uri);
		try {
			if ($uris === []) {
				$this->durableCache->removeShared(self::CONTINUATIONS, $key);

				return;
			}
			$this->durableCache->setShared(self::CONTINUATIONS, $key, $uris, self::CONTINUATION_TTL);
		} catch (Throwable $e) {
			$this->logger->debug('Bluesky thread continuations were not saved to cache', ['uri' => $uri, 'exception' => $e]);
		}
	}

	private function saveSearchCursor(string $key, string $cursor): void {
		try {
			if ($cursor === '') {
				$this->durableCache->removeShared(self::SEARCH_CURSORS, $key);

				return;
			}
			$this->durableCache->setShared(self::SEARCH_CURSORS, $key, $cursor, self::CONTINUATION_TTL);
		} catch (Throwable $e) {
			$this->logger->debug('Bluesky thread search cursor was not saved to cache', ['key' => $key, 'exception' => $e]);
		}
	}

	private function savedSearchCursor(string $key): string {
		try {
			$cursor = $this->durableCache->getShared(self::SEARCH_CURSORS, $key);

			return is_string($cursor) ? $cursor : '';
		} catch (Throwable $e) {
			$this->logger->debug('Bluesky thread search cursor was not read from cache', ['key' => $key, 'exception' => $e]);

			return '';
		}
	}

	private static function searchCursorKey(string $rootUri, string $parentUri): string {
		return hash('sha256', $rootUri . "\0" . $parentUri);
	}

	/** The URI search indexes replies under their conversation root, not the opened reply. */
	private static function threadRootUri(Stream $post, array $thread, string $uri): string {
		$root = (string)($post->getDetails(PostMapper::DETAIL)['reply_root']['uri'] ?? '');
		if (Syntax::isAtUri($root)) {
			return $root;
		}

		// Posts imported before reply_root was kept in local details still
		// arrive with their parent chain from getPostThread. Walk to its oldest
		// available ancestor before falling back to the opened post itself.
		$ancestor = $thread;
		while (is_array($ancestor['parent'] ?? null) && ($ancestor['parent']['$type'] ?? '') === self::THREAD_VIEW) {
			$ancestor = $ancestor['parent'];
		}
		$root = (string)($ancestor['post']['uri'] ?? '');

		return Syntax::isAtUri($root) ? $root : $uri;
	}

	/** Every node whose visible direct replies do not match its AppView count. */
	private static function nodesWithMissingDirectReplies(array $thread): array {
		$missing = [];
		$pending = [[$thread, 0]];
		while ($pending !== []) {
			[$node, $depth] = array_shift($pending);
			if (($node['$type'] ?? '') !== self::THREAD_VIEW) {
				continue;
			}
			$post = is_array($node['post'] ?? null) ? $node['post'] : [];
			$replies = is_array($node['replies'] ?? null) ? $node['replies'] : [];
			$replyCount = $post['replyCount'] ?? null;
			$knownReplies = [];
			foreach ($replies as $reply) {
				$replyUri = is_array($reply) && is_array($reply['post'] ?? null) ? (string)($reply['post']['uri'] ?? '') : '';
				if ($replyUri !== '') {
					$knownReplies[] = $replyUri;
				}
				if (is_array($reply) && ($reply['$type'] ?? '') === self::THREAD_VIEW) {
					$pending[] = [$reply, $depth + 1];
				}
			}
			$uri = (string)($post['uri'] ?? '');
			if ($depth < self::DEPTH && $uri !== '' && is_int($replyCount) && $replyCount > count($replies)) {
				$missing[] = [
					'uri' => $uri,
					'knownReplies' => $knownReplies,
					'expectedReplies' => $replyCount,
				];
			}
		}

		return $missing;
	}

	/** Store a thread parent-first and count only new records. */
	private function storeThread(array $thread, int $budget): int {
		$stored = 0;
		foreach (self::postsOf($thread, $budget) as $view) {
			if ($stored >= $budget) {
				break;
			}
			if ($this->store->storePost($view, false)) {
				$stored++;
			} else {
				// Feed/profile imports may already have stored the post. Its
				// AppView thread view still carries newer counts, so do not leave
				// the opened conversation showing the old likes and replies.
				$this->store->refreshKnownPostCountsFromView($view);
			}
		}

		return $stored;
	}

	/**
	 * Thread views at the AppView depth limit are read as the root of another
	 * bounded request. Replies the AppView omitted for other reasons are not
	 * retried because their node does not sit at that depth limit.
	 *
	 * @return list<string> AT URIs
	 */
	public static function continuationsOf(array $thread, int $depth): array {
		$uris = [];
		$level = [[$thread, 0]];
		while ($level !== []) {
			$next = [];
			foreach ($level as [$node, $at]) {
				if (!is_array($node) || ($node['$type'] ?? '') !== self::THREAD_VIEW) {
					continue;
				}
				if ($at >= $depth) {
					$post = is_array($node['post'] ?? null) ? $node['post'] : [];
					$uri = (string)($post['uri'] ?? '');
					if ($uri !== '' && (int)($post['replyCount'] ?? 0) > 0) {
						$uris[] = $uri;
					}
					continue;
				}
				foreach (is_array($node['replies'] ?? null) ? $node['replies'] : [] as $reply) {
					$next[] = [$reply, $at + 1];
				}
			}
			$level = $next;
		}

		return array_values(array_unique($uris));
	}

	/**
	 * The post views of a thread: the parents from the top, the post, then
	 * the replies level by level.
	 *
	 * @return list<array>
	 */
	public static function postsOf(array $thread, int $limit): array {
		$posts = [];
		for ($parent = $thread['parent'] ?? null; is_array($parent) && ($parent['$type'] ?? '') === self::THREAD_VIEW; $parent = $parent['parent'] ?? null) {
			array_unshift($posts, $parent['post'] ?? null);
		}
		if (($thread['$type'] ?? '') === self::THREAD_VIEW) {
			$posts[] = $thread['post'] ?? null;
		}
		$level = [$thread];
		while ($level !== [] && count($posts) < $limit * 2) {
			$next = [];
			foreach ($level as $node) {
				foreach (is_array($node['replies'] ?? null) ? $node['replies'] : [] as $reply) {
					if (is_array($reply) && ($reply['$type'] ?? '') === self::THREAD_VIEW) {
						$posts[] = $reply['post'] ?? null;
						$next[] = $reply;
					}
				}
			}
			$level = $next;
		}

		return array_values(array_filter($posts, 'is_array'));
	}
}
