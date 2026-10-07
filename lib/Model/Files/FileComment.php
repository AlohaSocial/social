<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Files;

/**
 * A reply and the comment that stands for it on one file.
 *
 * `outbound` is a comment the post's author wrote in Files that went out as
 * the reply; otherwise the reply came first and the comment is its copy.
 * `userId` is the post's author, whose switch and blocks decide what is
 * copied.
 */
class FileComment {
	public function __construct(
		public readonly int $fileId,
		public readonly string $userId,
		public readonly int $commentId,
		public readonly string $postIdPrim,
		public readonly string $replyId,
		public readonly bool $outbound,
	) {
	}
}
