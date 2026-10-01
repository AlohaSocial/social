<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Cron\TranscodeBeforeDelivery;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\BackgroundJob\IJobList;
use Throwable;

/**
 * Whether a new post waits for its video to be converted before it goes out.
 *
 * A post is delivered once. **Pixelfed checks an attachment's type at the
 * moment the `Create` arrives and drops a `video/quicktime` there and then**,
 * and nothing re-sends it after the conversion: converting the file on the
 * quarter-hourly sweep made it play here and changed nothing for the servers
 * that had already been told about it. So a post carrying a local video that
 * may need converting has its deliveries queued but held back, the conversion
 * of exactly that video is queued at once rather than left to the sweep, and
 * the job releases the deliveries — rebuilt from the post, so they name the
 * converted file — when it has finished or given up.
 *
 * The hold has a deadline. A post whose conversion has not finished after
 * `HOLD_SECONDS` goes out as it is, because a post that plays in fewer places
 * is better than one that has not arrived anywhere: the queue delivers a held
 * row by itself once that time has passed, whether or not the job ever ran.
 *
 * Nothing is held on a server that cannot convert, or that was told not to,
 * or for a post whose video is already an H.264 MP4 — which the upload
 * records while the file is still on disk.
 */
class VideoDeliveryHold {
	/** The longest a post waits for its video, in seconds. */
	public const HOLD_SECONDS = 600;

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private VideoTranscodeService $videoTranscodeService,
		private IJobList $jobList,
	) {
	}

	/**
	 * How long a post's delivery waits, if it carries a video still to be
	 * converted.
	 *
	 * @return int when the delivery may go out regardless, or 0 when it need
	 *             not wait at all
	 */
	public function holdUntil(Stream $post): int {
		return ($this->awaited($post) === []) ? 0 : time() + self::HOLD_SECONDS;
	}

	/**
	 * Queues the conversion a held post is waiting for.
	 *
	 * Called once the post and its held deliveries are stored, which is what
	 * the job reads. The argument is the post alone, so a second call for the
	 * same post — an edit made while the first is still waiting — finds the
	 * job already queued rather than adding another.
	 */
	public function convertSoon(Stream $post): void {
		$this->jobList->add(TranscodeBeforeDelivery::class, ['post' => $post->getId()]);
	}

	/**
	 * The stored videos on a post that have not been through the transcoder
	 * and may need it.
	 *
	 * @return list<\OCA\Social\Model\ActivityPub\Object\Document>
	 */
	public function awaited(Stream $post): array {
		$nids = [];
		foreach ($post->getAttachments() as $attachment) {
			if ($attachment->getType() === 'video' && $attachment->getId() !== '') {
				$nids[] = $attachment->getId();
			}
		}

		// asked only now: most posts carry no video, and none of them should
		// cost a look for ffmpeg
		if ($nids === [] || !$this->videoTranscodeService->isEnabled()) {
			return [];
		}

		$awaited = [];
		foreach ($nids as $nid) {
			try {
				$document = $this->cacheDocumentsRequest->getByNid($nid);
			} catch (Throwable $e) {
				continue;
			}

			if ($document->getTranscoded() === VideoTranscodingWorker::NOT_LOOKED
				&& $document->getLocalCopy() !== ''
				&& !$document->isStreamed()
				&& $this->videoTranscodeService->mayNeedConversion($document->getMediaType())) {
				$awaited[] = $document;
			}
		}

		return $awaited;
	}
}
