<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamViewsRequest;
use OCA\Social\Model\ActivityPub\Activity\View;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * How many accounts have opened a post.
 *
 * Only a post's own page counts, not a timeline impression; only signed-in
 * accounts are counted; and only the author sees the number. Remote views
 * count only for PeerTube-style `View`s of our own videos (`receiveView()`).
 */
class ViewCountService {
	public function __construct(
		private StreamViewsRequest $streamViewsRequest,
		private StreamRequest $streamRequest,
		private CacheActorService $cacheActorService,
		private SignatureService $signatureService,
		private ActivityService $activityService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Remembers that this viewer opened this post, and puts the count on it when
	 * the viewer is its author. Never fails the read.
	 */
	public function seen(Stream $post, ?Person $viewer): void {
		if ($viewer === null || $post->getId() === '') {
			return;
		}

		try {
			if ($viewer->getId() !== $post->getAttributedTo()) {
				// Idempotent on (post, viewer), so the receipt goes out once and a second
				// play cannot inflate somebody else's counter.
				if ($this->streamViewsRequest->seen($post->getId(), $viewer->getId())) {
					$this->sendView($post, $viewer);
				}
			}

			$this->attach($post, $viewer);
		} catch (Throwable $e) {
			$this->logger->debug('could not count a view', [
				'post' => $post->getId(), 'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Tells the server that holds a video that somebody here watched it, one
	 * `View` per (post, person), as PeerTube counts views. A failure to send
	 * does not undo the local view.
	 */
	private function sendView(Stream $post, Person $viewer): void {
		if ($post->isLocal() || $post->getAttributedTo() === $viewer->getId()) {
			return;
		}

		// Only videos are counted elsewhere.
		if ($post->getType() !== 'Video' && $post->getSubType() !== 'Video') {
			return;
		}

		try {
			$owner = $this->cacheActorService->getFromId($post->getAttributedTo());

			$activity = new View();
			// Under the watching account: Pixelfed and PeerTube refuse an activity
			// whose id is on another host than its actor.
			$activity->setId($viewer->getId() . '#views/' . md5($post->getId()));
			$activity->setActor($viewer);
			$activity->setStoryId($post->getId());
			$activity->setTo($owner->getId());
			$activity->addInstancePath(new InstancePath(
				$owner->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW
			));

			$this->signatureService->signObject($viewer, $activity);
			$this->activityService->request($activity);
		} catch (Throwable $e) {
			$this->logger->debug('could not send a view receipt', [
				'post' => $post->getId(), 'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Somebody on another server watched one of our videos (a PeerTube `View`).
	 * Counted like a local view, one row per (post, signed actor), and only for
	 * our own posts.
	 */
	public function receiveView(string $postId, string $viewerId): void {
		if ($postId === '' || $viewerId === '') {
			return;
		}

		try {
			$post = $this->streamRequest->getStreamById($postId);
			if (!$post->isLocal() || $post->getAttributedTo() === $viewerId) {
				return;
			}

			$this->streamViewsRequest->seen($post->getId(), $viewerId);
		} catch (Throwable $e) {
			$this->logger->debug('could not count a view from another server', [
				'post' => $postId, 'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Puts the count on a post the viewer wrote, and on nobody else's.
	 */
	public function attach(Stream $post, ?Person $viewer): void {
		if ($viewer === null || $viewer->getId() !== $post->getAttributedTo()) {
			return;
		}

		$post->setViewCount($this->streamViewsRequest->countFor($post->getId()));
	}

	/**
	 * The same, for a page of the author's own posts, in one query.
	 *
	 * @param Stream[] $posts
	 */
	public function attachAll(array $posts, ?Person $viewer): void {
		if ($viewer === null || $posts === []) {
			return;
		}

		$own = [];
		foreach ($posts as $post) {
			if ($viewer->getId() === $post->getAttributedTo() && $post->getId() !== '') {
				$own[] = $post->getId();
			}
		}

		if ($own === []) {
			return;
		}

		try {
			$counts = $this->streamViewsRequest->countForMany($own);
		} catch (Throwable $e) {
			$this->logger->debug('could not read the view counts', ['exception' => $e->getMessage()]);

			return;
		}

		foreach ($posts as $post) {
			if ($viewer->getId() === $post->getAttributedTo()) {
				$post->setViewCount($counts[md5($post->getId())] ?? 0);
			}
		}
	}
}
