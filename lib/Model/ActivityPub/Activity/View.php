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
 * "I watched your story": Pixelfed's receipt to a story's author. The object
 * is a wrapper, `{"type": "Story", "object": "<address>"}`, as Pixelfed's
 * `StoryViewDeliver` builds it and its inbox insists. Addressed to the author
 * alone.
 */
class View extends ACore implements JsonSerializable {
	public const TYPE = 'View';

	/** The address of the story that was watched. */
	private string $storyId = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function getStoryId(): string {
		return $this->storyId;
	}

	public function setStoryId(string $storyId): self {
		$this->storyId = $storyId;

		return $this;
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);

		// `object` is a wrapper, not an id: read out of the array it arrived
		// as rather than out of `objectId`, which a nested object leaves empty
		$object = $this->getArray('object', $data, []);
		if ($this->get('type', $object, '') === 'Story') {
			$this->setStoryId($this->validate(ACore::AS_ID, 'object', $object, ''));
		}
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'object' => [
					'type' => 'Story',
					'object' => $this->getStoryId(),
				],
			]
		);
	}
}
