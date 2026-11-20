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
 * An answer to a story on the wire: Pixelfed's own `Story:Reaction` or
 * `Story:Reply`. Flat: the text in `content` and the story named by
 * `inReplyTo`. Pixelfed drops it silently unless the id and actor share a
 * host, the story and recipient are its own, and the sender follows the
 * recipient, so all four are met on the way out and checked on the way in.
 */
abstract class StoryInteraction extends ACore implements JsonSerializable {
	/**
	 * The wire type, which every subclass replaces; declared here because the
	 * constructor reads `static::TYPE`.
	 */
	public const TYPE = '';

	/** The story this answers, by its address. */
	private string $storyId = '';
	private string $content = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(static::TYPE);
	}

	public function getStoryId(): string {
		return $this->storyId;
	}

	public function setStoryId(string $storyId): self {
		$this->storyId = $storyId;

		return $this;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function setContent(string $content): self {
		$this->content = $content;

		return $this;
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);

		$this->setStoryId($this->validate(ACore::AS_ID, 'inReplyTo', $data, ''));
		$this->setContent($this->get('content', $data, ''));
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'inReplyTo' => $this->getStoryId(),
				'content' => $this->getContent(),
			]
		);
	}
}
