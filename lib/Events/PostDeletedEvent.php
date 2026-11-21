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
 * A post written on this instance was deleted, for anything that copied it
 * elsewhere (an index, a mirror, an Activity entry). Dispatched after the row
 * is gone and the `Delete` is queued, with the post as it last was.
 */
class PostDeletedEvent extends Event {
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
