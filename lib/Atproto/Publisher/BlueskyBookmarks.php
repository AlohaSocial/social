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
use OCA\Social\Atproto\Reader\BlueskyCountSource;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\IRecommendationSignalRecorder;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Nid;
use OCP\BackgroundJob\IJobList;
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
		private IRecommendationSignalRecorder $recommendationSignals,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A bookmark just set or taken away here: queue its private AppView sync.
	 * The queued worker reads the current local flag, so rapid changes coalesce
	 * to the final state and transient AppView failures are retried.
	 */
	public function bookmarked(Person $viewer, string $postId, bool $on): void {
		if ($this->fromApp) {
			return;
		}
		try {
			// The queued job reads the stored flag, rather than this possibly
			// stale event value, so a later change supersedes an earlier one.
			$ref = $this->refs->strongRef($postId);
			$identity = $ref === null ? null : $this->identities->activeForActor($viewer);
			if ($ref === null || $identity === null) {
				return;
			}
			$this->jobList->add(AtprotoPublish::class, ['action' => 'bookmark', 'id' => $postId, 'actor' => $viewer->getId()]);
		} catch (Throwable $e) {
			$this->logger->warning('Bookmark sync to Bluesky was not queued', ['post' => $postId, 'exception' => $e]);
		}
	}

	/** Retryable worker path: read the latest local state before synchronizing. */
	public function syncCurrent(Person $viewer, string $postId): void {
		$ref = $this->refs->strongRef($postId);
		$identity = $ref === null ? null : $this->identities->activeForActor($viewer);
		if ($ref === null || $identity === null) {
			return;
		}
		$on = $this->streamActions->getActionBool($viewer->getId(), $postId, StreamAction::BOOKMARKED);
		$method = $on ? self::CREATE : self::DELETE;
		$input = $on ? $ref : ['uri' => $ref['uri']];
		$this->appView->procedureAs($identity->did, $this->identities->signingKey($identity), $method, $input);
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
			$on = $method === self::CREATE;
			$wasBookmarked = $this->streamActions->getActionBool($viewer->getId(), $postId, StreamAction::BOOKMARKED);
			$this->fromApp = true;
			$this->streamActions->setActionBool($viewer->getId(), $postId, StreamAction::BOOKMARKED, $on);
			if ($wasBookmarked !== $on) {
				try {
					$post = $this->streams->getStreamById($postId);
					$this->recommendationSignals->recordAction($viewer, $post, $on ? 'save' : 'unsave');
				} catch (Throwable $e) {
					$this->logger->debug('A Bluesky bookmark was not projected into For You learning', ['exception' => $e]);
				}
			}
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
		foreach (array_chunk($refs, BlueskyCountSource::BATCH) as $chunk) {
			try {
				$identity = $this->identities->activeForActor($viewer);
				$params = ['uris' => array_column($chunk, 'uri')];
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
			$cursor = null;
			foreach ($posts as $post) {
				$nid = Nid::normalize($post->getNid());
				if ($cursor === null || Nid::compare($nid, $cursor) < 0) {
					$cursor = $nid;
				}
			}
			$answer['cursor'] = $cursor ?? '0';
		}

		return $answer;
	}
}
