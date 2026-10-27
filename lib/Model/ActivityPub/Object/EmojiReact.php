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
 * `EmojiReact`: a Like with a particular emoji on it.
 *
 * Not part of the ActivityStreams vocabulary. It comes from Misskey and is
 * implemented by Pleroma, Akkoma, Iceshrimp, Sharkey and others, and is the
 * only thing on the wire that anybody sends for a reaction, so it is what this
 * app sends and accepts. Mastodon ignores an activity type it does not know,
 * so a reaction sent to a Mastodon peer is dropped there and costs nothing.
 *
 * The emoji rides in `content`, which is the one part the implementations all
 * agree on: a single unicode emoji, or a shortcode like `:blobcat:` for a
 * custom one, with the picture in a `tag` entry. This app reads the shortcode
 * and ignores the picture — see ReactionService for why — so a custom emoji
 * from a peer is kept as a text label rather than loaded from their server.
 *
 * A reaction with no `content` is the receiving implementation's problem to
 * name; Pleroma treats it as a plain Like. Here it is refused, because a
 * reaction bar has nothing to draw for it.
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
