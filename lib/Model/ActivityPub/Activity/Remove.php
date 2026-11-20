<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Activity;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

class Remove extends ACore implements JsonSerializable {
	public const TYPE = 'Remove';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);
		// the collection the object is added to / removed from: without it a
		// pin cannot be told from any other collection membership
		$this->setTarget($this->validate(ACore::AS_ID, 'target', $data, ''));
	}

	/**
	 * `target` is the collection, and for a pin it is what a peer checks: an
	 * `Add` or `Remove` that names no collection is dropped by Mastodon,
	 * which acts on one only when its `target` is the actor's `featured`.
	 */
	#[\Override]
	public function exportAsActivityPub(): array {
		if ($this->getTarget() !== '') {
			$this->addEntry('target', $this->getTarget());
		}

		return parent::exportAsActivityPub();
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
			]
		);
	}
}
