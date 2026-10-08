<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

/**
 * A post's video on its way through Bluesky's video service: queued, sent
 * and being processed, done with the blob the service stored here, or
 * failed, when the post goes to Bluesky as a link instead.
 */
final class VideoUpload {
	public const QUEUED = 'queued';
	public const PROCESSING = 'processing';
	public const DONE = 'done';
	public const FAILED = 'failed';

	public function __construct(
		public readonly int $id,
		public readonly string $postId,
		public readonly string $did,
		public readonly string $documentId,
		public string $state,
		public string $jobId = '',
		public string $blobCid = '',
		public int $attempts = 0,
		public string $error = '',
		public readonly int $creation = 0,
		public readonly int $updated = 0,
	) {
	}

	public function isWaiting(): bool {
		return $this->state === self::QUEUED || $this->state === self::PROCESSING;
	}
}
