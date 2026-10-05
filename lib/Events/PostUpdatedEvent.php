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
 * A local post was durably edited.
 *
 * This is separate from `PostPublishedEvent`: mirrors must replace the
 * protocol-native record at their existing id, never create a second post.
 */
class PostUpdatedEvent extends Event {
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
