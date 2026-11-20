<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\VideoViewsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\ISession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * How many times a video posted here was watched: the public number under
 * it. Not `ViewCountService`, which counts page opens for the author. A view
 * counts once somebody played `MIN_SECONDS`, at most once per viewer, video
 * and day; the viewer is the account or the browser's session.
 *
 * Only a keyed hash of (day, video, viewer) is kept, for that day, so a row
 * cannot be tied to a person or to another row; `Cron\Cache` drops older ones.
 * Videos from elsewhere are counted there and arrive with `video.views`.
 */
class VideoViewService {
	/** How many seconds of a video the player waits for before it reports a view. */
	public const MIN_SECONDS = 5;

	private const DAY = 86400;

	public function __construct(
		private VideoViewsRequest $videoViewsRequest,
		private IConfig $config,
		private ISession $session,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Counts one view, unless this viewer was already counted for this video
	 * today. Never fails the request.
	 *
	 * @return bool whether the count moved
	 */
	public function count(Stream $post, ?Person $viewer): bool {
		if (!$post->isLocal() || !$post->isVideo() || $post->getId() === '') {
			return false;
		}

		// the author watching their own video is checking it, not viewing it
		if ($viewer !== null && $viewer->getId() === $post->getAttributedTo()) {
			return false;
		}

		$who = $this->viewerKey($viewer);
		if ($who === '') {
			return false;
		}

		try {
			$day = $this->today();
			if (!$this->videoViewsRequest->seen($post->getId(), $this->hash($day, $post->getId(), $who), $day)) {
				return false;
			}

			$this->videoViewsRequest->increment($post->getId());

			return true;
		} catch (Throwable $e) {
			$this->logger->debug('could not count a video view', [
				'post' => $post->getId(), 'exception' => $e->getMessage(),
			]);

			return false;
		}
	}

	/**
	 * Puts the count on every video posted here among a page of posts, the
	 * boosted ones included, in one query. A video nobody has watched yet gets
	 * 0, which is a count; everything else is left without one.
	 *
	 * @param Stream[] $posts
	 */
	public function attach(array $posts): void {
		$videos = [];
		foreach ($posts as $post) {
			foreach ([$post, $post->hasObject() ? $post->getObject() : null] as $candidate) {
				if ($candidate instanceof Stream && $candidate->isLocal() && $candidate->getId() !== '' && $candidate->isVideo()) {
					$videos[] = $candidate;
				}
			}
		}

		if ($videos === []) {
			return;
		}

		try {
			$counts = $this->videoViewsRequest->countsFor(array_map(static fn (Stream $video): string => $video->getId(), $videos));
		} catch (Throwable $e) {
			$this->logger->debug('could not read the video view counts', ['exception' => $e->getMessage()]);

			return;
		}

		foreach ($videos as $video) {
			$video->setLocalVideoViews($counts[md5($video->getId())] ?? 0);
		}
	}

	/**
	 * Forgets who was counted before today.
	 *
	 * @return int how many rows went
	 */
	public function forgetEarlierDays(): int {
		return $this->videoViewsRequest->forgetBefore($this->today());
	}

	/** @return int the day it is, in UTC, counted from 1970 */
	public function today(): int {
		return intdiv($this->time->getTime(), self::DAY);
	}

	/**
	 * Who is watching, as the hash takes it: the account, else the session;
	 * '' when there is neither, and then nothing is counted.
	 */
	private function viewerKey(?Person $viewer): string {
		if ($viewer !== null && $viewer->getId() !== '') {
			return 'account:' . $viewer->getId();
		}

		try {
			$session = $this->session->getId();
		} catch (Throwable $e) {
			return '';
		}

		return ($session === '') ? '' : 'session:' . $session;
	}

	private function hash(int $day, string $postId, string $who): string {
		return hash_hmac(
			'sha256',
			$day . "\n" . $postId . "\n" . $who,
			'social-video-views:' . $this->config->getSystemValueString('secret', '')
		);
	}
}
