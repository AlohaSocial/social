<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\WatchRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where somebody stopped watching a video (PeerTube's `WatchAction`): private
 * to the reader, never federated or counted, one row per (post, viewer).
 * Videos barely started or finished are not offered back.
 */
class WatchService {
	public function __construct(
		private WatchRequest $watchRequest,
		private StreamRequest $streamRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Remembers where somebody got to. Never fails the request, but says whether
	 * the server holds the reported position, so a player does not mistake its
	 * own copy for a stored one.
	 *
	 * @return bool whether the position is where the server holds it
	 */
	public function remember(Stream $post, ?Person $viewer, int $position, int $duration): bool {
		if ($viewer === null || $post->getId() === '' || $position < 0) {
			return true;
		}

		try {
			// Clamp; an unstated duration measures nothing.
			$duration = max(0, $duration);
			if ($duration > 0) {
				$position = min($position, $duration);

				// Finished: the bookmark is dropped.
				if ($position >= (int)floor((float)$duration * WatchRequest::FINISHED_RATIO)) {
					$this->watchRequest->forget($post->getId(), $viewer->getId());

					return true;
				}
			}

			$this->watchRequest->remember($post->getId(), $viewer->getId(), $position, $duration);

			return true;
		} catch (Throwable $e) {
			$this->logger->warning('could not remember a watch position', [
				'post' => $post->getId(), 'exception' => $e->getMessage(),
			]);

			return false;
		}
	}

	/** Where this person got to in this video, in whole seconds. */
	public function positionOf(Stream $post, ?Person $viewer): int {
		if ($viewer === null || $post->getId() === '') {
			return 0;
		}

		try {
			return $this->watchRequest->positionOf($post->getId(), $viewer->getId());
		} catch (Throwable $e) {
			return 0;
		}
	}

	/**
	 * Puts where the reader got to on a video they are opening, so its player
	 * starts there (`watch_progress`). Nothing for a post that is not a video,
	 * an anonymous reader, or a video they have not begun.
	 */
	public function attachProgress(Stream $post, ?Person $viewer): void {
		if ($viewer === null || $post->getId() === '' || !$post->isVideo()) {
			return;
		}

		try {
			$progress = $this->watchRequest->progressOf($post->getId(), $viewer->getId());
		} catch (Throwable $e) {
			return;
		}

		if ($progress !== null && $progress['position'] > 0) {
			$post->setWatchProgress($progress['position'], $progress['duration'], $progress['updated']);
		}
	}

	/**
	 * The videos this person was in the middle of, newest first, each
	 * carrying where they got to (`watch_progress`).
	 *
	 * @return Stream[]
	 */
	public function unfinished(Person $viewer, int $limit = 20): array {
		$posts = [];

		foreach ($this->watchRequest->unfinishedProgress($viewer->getId(), $limit) as $watch) {
			try {
				$post = $this->streamRequest->getStreamByIdPrim($watch['prim']);
				$post->setWatchProgress($watch['position'], $watch['duration'], $watch['updated']);
				$posts[] = $post;
			} catch (Throwable $e) {
				// Deleted since: not offered.
			}
		}

		return $posts;
	}

	/** Takes one off the list, for somebody who does not want it offered. */
	public function forget(Stream $post, Person $viewer): bool {
		return $this->watchRequest->forget($post->getId(), $viewer->getId());
	}
}
