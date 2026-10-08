<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\SignatureService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts what the AppView shows into the stream, through the same door a
 * Fediverse server's activities come in: a post as a `Create`, a repost as
 * an `Announce`, a post that is gone as a `Delete` — each from the Bluesky
 * account it is by, which is made a cached actor first when it is not one
 * yet. Nothing downstream knows a Bluesky post from any other.
 */
class PostStore {
	public function __construct(
		private PostMapper $mapper,
		private AppViewClient $appView,
		private InteractionPublisher $interactions,
		private ActorMapper $actorMapper,
		private BlueskyActorService $actors,
		private ImportService $import,
		private StreamRequest $streams,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A feed item: its post, and the repost that put it in the feed.
	 *
	 * @param array $item an `app.bsky.feed.defs#feedViewPost`
	 * @return int how many new rows that made
	 */
	public function storeFeedItem(array $item): int {
		$post = is_array($item['post'] ?? null) ? $item['post'] : [];
		$stored = $this->storePost($post) ? 1 : 0;
		$announce = $this->mapper->announce($item);
		if ($announce === null) {
			return $stored;
		}
		if (!$this->isKnown($announce['object'])) {
			return $stored;
		}
		try {
			$this->streams->getAnnounceBy($announce['object'], $announce['actor']);

			return $stored;
		} catch (StreamNotFoundException) {
		}
		$by = is_array($item['reason']['by'] ?? null) ? $item['reason']['by'] : [];
		if (!$this->ensureActor($by)) {
			return $stored;
		}

		return $stored + ($this->process($announce) ? 1 : 0);
	}

	/**
	 * A post view, stored unless it is here already or a label hides it.
	 *
	 * @param array $post an `app.bsky.feed.defs#postView`
	 */
	public function storePost(array $post, bool $fetchParent = true): bool {
		$create = $this->mapper->create($post);
		if ($create === null || $this->isKnown($create['object']['id'])) {
			return false;
		}
		if (!$this->ensureActor(is_array($post['author'] ?? null) ? $post['author'] : [])) {
			return false;
		}
		$parent = (string)($create['object']['inReplyTo'] ?? '');
		if ($fetchParent && BlueskyIds::isPostId($parent) && !$this->isKnown($parent)) {
			// one hop up, so a reply is not stored without what it answers;
			// the parent's own parent is not fetched
			$this->storeByUri((string)($post['record']['reply']['parent']['uri'] ?? ''));
		}

		return $this->process($create);
	}

	/**
	 * A post the AppView has by its `at://` URI, stored like one read off a
	 * feed; its parent is not fetched.
	 */
	public function storeByUri(string $uri): bool {
		if ($uri === '') {
			return false;
		}
		try {
			$answer = $this->appView->query('app.bsky.feed.getPosts', ['uris' => [$uri]]);
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky post not fetched', ['uri' => $uri, 'exception' => $e]);

			return false;
		}
		$post = is_array($answer['posts'][0] ?? null) ? $answer['posts'][0] : null;

		return $post !== null && $this->storePost($post, false);
	}

	/**
	 * Which of these stored Bluesky posts the AppView still has. A post it
	 * no longer answers for is gone — deleted, or its author is — and is
	 * deleted here the way a remote `Delete` would.
	 *
	 * @param string[] $postIds stored ids, at most 25 (one `getPosts`)
	 * @return int how many were deleted
	 */
	public function deleteGone(array $postIds): int {
		$uris = [];
		foreach (array_slice($postIds, 0, 25) as $id) {
			$parsed = BlueskyIds::parsePostId($id);
			if ($parsed !== null) {
				$uris[$id] = BlueskyIds::atUri($parsed['did'], BlueskyIds::POST, $parsed['rkey']);
			}
		}
		if ($uris === []) {
			return 0;
		}
		try {
			$answer = $this->appView->query('app.bsky.feed.getPosts', ['uris' => array_values($uris)]);
		} catch (Throwable $e) {
			// nothing is concluded from an AppView that did not answer
			$this->logger->notice('Bluesky posts not checked', ['exception' => $e]);

			return 0;
		}
		if (!is_array($answer['posts'] ?? null)) {
			return 0;
		}
		$present = [];
		foreach ($answer['posts'] as $post) {
			if (is_array($post) && is_string($post['uri'] ?? null)) {
				$present[$post['uri']] = true;
			}
		}
		$deleted = 0;
		foreach ($uris as $id => $uri) {
			if (!isset($present[$uri]) && $this->delete($id)) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * A post the AppView no longer has, removed the way a remote `Delete` is.
	 */
	public function delete(string $postId): bool {
		if (!$this->isKnown($postId)) {
			return false;
		}
		$did = BlueskyIds::didOf($postId);
		$deleted = $this->process([
			'id' => $postId . '/delete',
			'type' => 'Delete',
			'actor' => BlueskyIds::actorId($did),
			'object' => $postId,
		]);
		if ($deleted) {
			$this->interactions->removeAllOf($postId);
		}

		return $deleted;
	}

	public function isKnown(string $id): bool {
		try {
			$this->streams->getStreamById($id);

			return true;
		} catch (StreamNotFoundException) {
			return false;
		}
	}

	/**
	 * The author as a cached actor; from the cache, or from the basic
	 * profile the feed carries when the account was never seen here.
	 */
	private function ensureActor(array $profile): bool {
		$did = (string)($profile['did'] ?? '');
		if ($did === '') {
			return false;
		}
		$actor = $this->actors->cached($did);
		if ($actor !== null) {
			return !BlueskyActorService::isLimited($actor);
		}
		try {
			$this->actors->store($this->actorMapper->person($profile));
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky account not stored', ['did' => $did, 'exception' => $e]);

			return false;
		}

		return true;
	}

	private function process(array $data): bool {
		$details = $data['object']['_atproto'] ?? null;
		if (is_array($details)) {
			unset($data['object']['_atproto']);
		}
		try {
			$activity = AP::instance()->getItemFromData($data);
			$activity->setOrigin(BlueskyIds::HOST, SignatureService::ORIGIN_REQUEST, $this->time->getTime());
			if (is_array($details) && $activity->hasObject()) {
				$object = $activity->getObject();
				if ($object instanceof Stream) {
					$object->setDetailArray(PostMapper::DETAIL, $details);
					$object->setDetailInt(Details::LIKES, $details['likes']);
					$object->setDetailInt(Details::REMOTE_LIKES, $details['likes']);
					$object->setDetailInt(Details::BOOSTS, $details['reposts']);
					$object->setDetailInt(Details::REMOTE_BOOSTS, $details['reposts']);
					$object->setDetailInt(Details::REPLIES, $details['replies']);
					$object->setDetailInt(Details::REMOTE_REPLIES, $details['replies']);
				}
			}
			$this->import->parseIncomingRequest($activity);
		} catch (Throwable $e) {
			$this->logger->warning('Bluesky ' . ($data['type'] ?? '') . ' not stored', ['id' => $data['id'] ?? '', 'exception' => $e]);

			return false;
		}

		return true;
	}
}
