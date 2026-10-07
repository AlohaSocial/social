<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Events;

use OCA\Social\Model\ActivityPub\Stream;
use OCP\EventDispatcher\Event;

/**
 * A post written on this instance was edited by its author.
 *
 * The third of `PostPublishedEvent` and `PostDeletedEvent`: anything that
 * copied a post somewhere else has to show the new text, not the old one.
 *
 * Dispatched after the edit is stored and its revision recorded, before the
 * `Update` is delivered.
 */
class PostEditedEvent extends Event {
	public function __construct(
		private Stream $post,
	) {
		parent::__construct();
	}

	public function getPost(): Stream {
		return $this->post;
	}

	public function getAuthorId(): string {
		return $this->post->getAttributedTo();
	}
}
