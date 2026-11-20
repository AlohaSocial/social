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
 * A post written on this instance was edited by its author, so anything that
 * copied it elsewhere can show the new text. Dispatched after the edit and its
 * revision are stored, before the `Update` is delivered.
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
