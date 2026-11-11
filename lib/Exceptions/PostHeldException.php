<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use OCA\Social\Model\HeldPost;

/**
 * A review rule (`PostReviewService::assess()`) holds the post: nothing was
 * published. With the request as the queue stores it, or without one where
 * the caller asked to be refused rather than held (`Post::REVIEW_REFUSE`).
 */
class PostHeldException extends InvalidActionException {
	public function __construct(
		private string $reason,
		private ?HeldPost $held = null,
	) {
		parent::__construct('This post is waiting for a moderator to look at it.');
	}

	/** The rule that holds it, one of `HeldPost::REASON_*`. */
	public function getReason(): string {
		return $this->reason;
	}

	/** The request in the queue; null when the post was refused instead. */
	public function getHeld(): ?HeldPost {
		return $this->held;
	}
}
