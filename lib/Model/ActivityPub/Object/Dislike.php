<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Tools\IQueryRow;

/**
 * `Dislike`, which PeerTube sends for its videos' dislike counts. Stored like
 * a `Like`, a row in `social_action` keyed by (actor, object, type), and
 * counted onto the post as `dislikes`. It never notifies: that would let
 * anybody needle an author one activity at a time.
 */
class Dislike extends ACore implements JsonSerializable, IQueryRow {
	public const TYPE = 'Dislike';

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
