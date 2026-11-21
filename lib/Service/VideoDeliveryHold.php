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
 * Pixelfed drops a `video/quicktime` when the `Create` arrives, and nothing
 * is re-sent later. So a post with a local video that may need converting has
 * its deliveries queued but held, the conversion is queued at once, and the
 * job releases the deliveries, rebuilt to name the converted file, when it
 * finishes or gives up. After `HOLD_SECONDS` the queue delivers a held row
 * anyway. Nothing is held where conversion is off or unavailable, or for a
 * video the upload recorded as H.264 MP4.
 */
class VideoDeliveryHold {
	/** The longest a post waits for its video, in seconds. */
	public const HOLD_SECONDS = 600;

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private VideoTranscodeService $videoTranscodeService,
		private IJobList $jobList,
		private RequestQueueService $requestQueueService,
	) {
	}

	/**
	 * Whether a post's deliveries are still waiting for its video. The
	 * attachments are checked first, so a post without a video costs nothing.
	 */
	public function isHeld(Stream $post): bool {
		return $this->awaited($post) !== [] && $this->requestQueueService->getHeld($post->getId()) !== [];
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
	 * Queues the conversion a held post is waiting for, once the post and its
	 * held deliveries are stored. Keyed by the post, so a second call (an edit
	 * while waiting) finds the job already queued.
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

		// checked only now, so posts without video never look for ffmpeg
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
