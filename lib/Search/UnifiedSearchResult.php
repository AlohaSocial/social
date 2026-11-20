<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Search;

use OCP\Search\SearchResultEntry;

class UnifiedSearchResult extends SearchResultEntry {
	public function __construct(
		string $thumbnailUrl = '', string $title = '', string $subline = '', string $resourceUrl = '',
		string $icon = '',
		bool $rounded = false,
	) {
		parent::__construct($thumbnailUrl, $title, $subline, $resourceUrl, $icon, $rounded);
	}

	/**
	 * @return string
	 */
	public function getThumbnailUrl(): string {
		return $this->thumbnailUrl;
	}

	/**
	 * @param string $thumbnailUrl
	 *
	 * @return UnifiedSearchResult
	 */
	public function setThumbnailUrl(string $thumbnailUrl): self {
		$this->thumbnailUrl = $thumbnailUrl;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * @param string $title
	 *
	 * @return UnifiedSearchResult
	 */
	public function setTitle(string $title): self {
		$this->title = $title;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSubline(): string {
		return $this->subline;
	}

	/**
	 * @param string $subline
	 *
	 * @return UnifiedSearchResult
	 */
	public function setSubline(string $subline): self {
		$this->subline = $subline;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getResourceUrl(): string {
		return $this->resourceUrl;
	}

	/**
	 * @param string $resourceUrl
	 *
	 * @return UnifiedSearchResult
	 */
	public function setResourceUrl(string $resourceUrl): self {
		$this->resourceUrl = $resourceUrl;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getIcon(): string {
		return $this->icon;
	}

	/**
	 * @param string $icon
	 *
	 * @return UnifiedSearchResult
	 */
	public function setIcon(string $icon): self {
		$this->icon = $icon;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isRounded(): bool {
		return $this->rounded;
	}

	/**
	 * @param bool $rounded
	 *
	 * @return UnifiedSearchResult
	 */
	public function setRounded(bool $rounded): self {
		$this->rounded = $rounded;

		return $this;
	}
}
