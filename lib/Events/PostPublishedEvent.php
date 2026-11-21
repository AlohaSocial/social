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
 * A post was written on this instance and sent, so other apps (Activity,
 * Talk, Flow) can react to it.
 *
 * Dispatched after the post is stored and addressed, so listeners see what
 * was published; delivery is queued and may not have happened yet. Local
 * posts only: inbound federated posts would be a firehose.
 */
class PostPublishedEvent extends Event {
	public function __construct(
		private Stream $post,
	) {
		parent::__construct();
	}

	public function getPost(): Stream {
		return $this->post;
	}

	/** The account that wrote it, as an ActivityPub actor id. */
	public function getAuthorId(): string {
		return $this->post->getAttributedTo();
	}
}
