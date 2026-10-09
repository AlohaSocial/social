<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Service\StreamService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A person's bookmarks, the same in Social and in a Bluesky app signed in
 * here. A Bluesky bookmark lives at the AppView, privately, and is what the
 * app's bookmark button shows: one made here, of a post that is on Bluesky,
 * is told to it; one the app makes through this server is made here too.
 * The app's list of bookmarks is this person's bookmarks here
 * (`getBookmarks`), the posts that are on Bluesky among them, so the
 * bookmarks made before are in it as well.
 */
class BlueskyBookmarks {
	public const CREATE = 'app.bsky.bookmark.createBookmark';
	public const DELETE = 'app.bsky.bookmark.deleteBookmark';
	public const LIST = 'app.bsky.bookmark.getBookmarks';
	private const PAGE = 50;

	/** whether a bookmark an app made is being applied here, so it is not told back */
	private bool $fromApp = false;

	public function __construct(
		private AppViewClient $appView,
		private IdentityService $identities,
		private PostRefs $refs,
		private LocalRecordResolver $local,
		private PostStore $store,
		private StreamActionService $streamActions,
		private StreamService $streams,
		private AccountService $accounts,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A bookmark just set or taken away here: told to the AppView, for a
	 * post that is on Bluesky and a person with a Bluesky identity.
	 */
	public function bookmarked(Person $viewer, string $postId, bool $on): void {
		if ($this->fromApp) {
			return;
		}
		try {
			$ref = $this->refs->strongRef($postId);
			$identity = $ref === null ? null : $this->identities->forActor($viewer, false);
			if ($ref === null || $identity === null) {
				return;
			}
			$this->appView->procedureAs($identity->did, $this->identities->signingKey($identity), $on ? self::CREATE : self::DELETE, $on ? $ref : ['uri' => $ref['uri']]);
		} catch (Throwable $e) {
			$this->logger->warning('Bookmark not told to Bluesky', ['post' => $postId, 'exception' => $e]);
		}
	}

	/**
	 * A bookmark an app set or took away through this server, which the
	 * AppView took: the same here.
	 */
	public function fromApp(ClientSession $session, string $method, array $body): void {
		$uri = (string)($body['uri'] ?? '');
		$postId = $this->local->postId($uri);
		if ($postId === '') {
			return;
		}
		try {
			if ($method === self::CREATE && !$this->store->isKnown($postId)) {
				$this->store->storeByUri($uri);
			}
			$viewer = $this->accounts->getActorFromUserId($session->userId);
			$this->fromApp = true;
			$this->streamActions->setActionBool($viewer->getId(), $postId, StreamAction::BOOKMARKED, $method === self::CREATE);
		} catch (Throwable $e) {
			$this->logger->info('A Bluesky app\'s bookmark not made here', ['uri' => $uri, 'exception' => $e]);
		} finally {
			$this->fromApp = false;
		}
	}

	/**
	 * `getBookmarks`: the person's bookmarks here that are on Bluesky, newest
	 * first, each as the AppView shows the post to them.
	 */
	public function list(ClientSession $session, int $limit, string $cursor): array {
		$viewer = $this->accounts->getActorFromUserId($session->userId);
		$limit = max(1, min(100, $limit > 0 ? $limit : self::PAGE));
		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::BOOKMARKS)->setLimit($limit)->setFormat(ACore::FORMAT_LOCAL);
		if (ctype_digit($cursor)) {
			$options->setMaxId($cursor);
		}
		$this->streams->setViewer($viewer);
		$posts = $this->streams->getTimeline($options);

		$refs = [];
		foreach ($posts as $post) {
			$ref = $this->refs->strongRef($post->getId());
			if ($ref !== null) {
				$refs[] = $ref;
			}
		}
		$views = [];
		if ($refs !== []) {
			try {
				$identity = $this->identities->forActor($viewer, false);
				$params = ['uris' => array_column($refs, 'uri')];
				$answer = $identity !== null
					? $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), 'app.bsky.feed.getPosts', $params)
					: $this->appView->query('app.bsky.feed.getPosts', $params);
				foreach (is_array($answer['posts'] ?? null) ? $answer['posts'] : [] as $view) {
					if (is_array($view) && is_string($view['uri'] ?? null)) {
						$views[$view['uri']] = ['$type' => 'app.bsky.feed.defs#postView'] + $view;
					}
				}
			} catch (Throwable $e) {
				$this->logger->info('Bookmarked posts not read from the AppView', ['exception' => $e]);
			}
		}

		$bookmarks = [];
		foreach ($refs as $ref) {
			$bookmarks[] = [
				'subject' => $ref,
				'item' => $views[$ref['uri']] ?? ['$type' => 'app.bsky.feed.defs#notFoundPost', 'uri' => $ref['uri'], 'notFound' => true],
			];
		}
		$answer = ['bookmarks' => $bookmarks];
		if (count($posts) >= $limit) {
			$answer['cursor'] = (string)min(array_map(static fn ($post): int => (int)$post->getNid(), $posts));
		}

		return $answer;
	}
}
