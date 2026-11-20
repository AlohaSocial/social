<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Tools\DbTime;

/**
 * One emoji this instance publishes: Mastodon's `CustomEmoji`. The image is
 * served from one route, the same URL a peer dereferences from an `Emoji`
 * tag. `static_url` is the same URL, as no still rendering is made.
 */
class CustomEmoji implements JsonSerializable {
	/** What a shortcode may be, as Mastodon reads one out of `:…:`. */
	public const SHORTCODE = '/^[a-z0-9_]{2,64}$/';

	public function __construct(
		private string $shortcode = '',
		private string $filename = '',
		private string $mediaType = '',
		private string $category = '',
		private bool $visible = true,
		private int $creation = 0,
		private int $id = 0,
		private string $url = '',
	) {
	}

	public static function fromRow(array $row): self {
		return new self(
			(string)($row['shortcode'] ?? ''),
			(string)($row['filename'] ?? ''),
			(string)($row['media_type'] ?? ''),
			(string)($row['category'] ?? ''),
			(bool)($row['visible'] ?? true),
			DbTime::toTimestamp($row['creation'] ?? null),
			(int)($row['id'] ?? 0),
		);
	}

	/** Whether a string can be a shortcode at all. */
	public static function isShortcode(string $shortcode): bool {
		return preg_match(self::SHORTCODE, $shortcode) === 1;
	}

	public function getId(): int {
		return $this->id;
	}

	public function getShortcode(): string {
		return $this->shortcode;
	}

	public function getFilename(): string {
		return $this->filename;
	}

	public function getMediaType(): string {
		return $this->mediaType;
	}

	public function getCategory(): string {
		return $this->category;
	}

	public function isVisible(): bool {
		return $this->visible;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	/** Where the image is served from; set by the service that knows. */
	public function setUrl(string $url): self {
		$this->url = $url;

		return $this;
	}

	public function getUrl(): string {
		return $this->url;
	}

	/** The `Emoji` tag a post carries so a peer can render the shortcode. */
	public function asTag(): array {
		return [
			'type' => 'Emoji',
			'name' => ':' . $this->shortcode . ':',
			'icon' => [
				'type' => 'Image',
				'mediaType' => $this->mediaType,
				'url' => $this->url,
			],
		];
	}

	#[\Override]
	public function jsonSerialize(): array {
		$emoji = [
			'shortcode' => $this->shortcode,
			'url' => $this->url,
			'static_url' => $this->url,
			'visible_in_picker' => $this->visible,
		];

		// Mastodon omits the key for "no category", and clients group by its presence.
		if ($this->category !== '') {
			$emoji['category'] = $this->category;
		}

		return $emoji;
	}
}
