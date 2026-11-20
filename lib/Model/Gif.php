<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * One picture in the instance's GIF library.
 */
class Gif implements JsonSerializable {
	private string $url = '';

	public function __construct(
		private string $slug,
		private string $filename,
		private string $mediaType,
		private string $title = '',
		private int $id = 0,
	) {
	}

	/**
	 * Whether a string may name a picture. The slug is a public URL segment and
	 * a filename stem in appdata, so it is limited to lowercase letters, digits,
	 * `-` and `_`, which rules out traversal.
	 */
	public static function isSlug(string $slug): bool {
		return preg_match('/^[a-z0-9_-]{2,64}$/', $slug) === 1;
	}

	public function getId(): int {
		return $this->id;
	}

	public function getSlug(): string {
		return $this->slug;
	}

	public function getFilename(): string {
		return $this->filename;
	}

	public function getMediaType(): string {
		return $this->mediaType;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setUrl(string $url): self {
		$this->url = $url;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'slug' => $this->getSlug(),
			'title' => $this->getTitle(),
			'url' => $this->getUrl(),
			'media_type' => $this->getMediaType(),
		];
	}
}
