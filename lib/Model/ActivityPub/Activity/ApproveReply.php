<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Activity;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * FEP-5624's `ApproveReply`: the author of a post saying that a reply to it
 * may be shown, as PeerTube ≥ 6.2 sends when a video's `commentsPolicy`
 * requires approval and a moderator approved the reply.
 */
class ApproveReply extends ACore implements JsonSerializable {
	public const TYPE = 'ApproveReply';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(parent::jsonSerialize(), ['object' => $this->getObjectId()]);
	}
}
