<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Files;

/**
 * A Nextcloud file and the post it was attached to.
 *
 * `postId` is empty while the attachment has not been posted yet.
 */
class FilePost {
	public function __construct(
		public readonly int $id,
		public readonly int $fileId,
		public readonly string $userId,
		public readonly string $docNid,
		public readonly string $postId,
	) {
	}
}
