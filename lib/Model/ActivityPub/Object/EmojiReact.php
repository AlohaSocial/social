<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * `EmojiReact`: a Like with a particular emoji, from Misskey and implemented
 * by Pleroma, Akkoma, Sharkey and others; Mastodon ignores it.
 *
 * The emoji is in `content`: a unicode emoji, or a `:shortcode:` for a custom
 * one, which is kept as a text label rather than loaded from the peer (see
 * ReactionService). A reaction without `content` is refused.
 */
class EmojiReact extends ACore implements JsonSerializable {
	public const TYPE = 'EmojiReact';

	/** the emoji itself, or `:shortcode:` for a custom one */
	private string $content = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function getContent(): string {
		return $this->content;
	}

	public function setContent(string $content): self {
		$this->content = $content;

		return $this;
	}

	#[\Override]
	public function import(array $data): void {
		parent::import($data);

		$this->setContent($this->get('content', $data, ''));
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		parent::importFromDatabase($data);

		$this->setContent($this->get('emoji', $data, ''));
	}

	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'content' => $this->getContent(),
			]
		);
	}
}
