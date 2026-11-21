<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * The link preview of a post, exported as Mastodon's PreviewCard in a
 * status's `card`. Never federated: every instance reads the page itself, so
 * this is a local cache keyed by the post that links it.
 */
class StreamCard implements JsonSerializable {
	public const MAX_TITLE = 255;
	public const MAX_DESCRIPTION = 500;

	private string $streamId = '';
	private string $url = '';
	private string $title = '';
	private string $description = '';
	private string $image = '';
	private string $providerName = '';
	private int $creation = 0;

	public function __construct(string $streamId = '', string $url = '') {
		$this->streamId = $streamId;
		$this->url = $url;
	}

	public function getStreamId(): string {
		return $this->streamId;
	}

	public function setStreamId(string $streamId): self {
		$this->streamId = $streamId;

		return $this;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setUrl(string $url): self {
		$this->url = $url;

		return $this;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function setTitle(string $title): self {
		$this->title = mb_substr(trim($title), 0, self::MAX_TITLE);

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setDescription(string $description): self {
		$this->description = mb_substr(trim($description), 0, self::MAX_DESCRIPTION);

		return $this;
	}

	public function getImage(): string {
		return $this->image;
	}

	public function setImage(string $image): self {
		$this->image = $image;

		return $this;
	}

	public function getProviderName(): string {
		return $this->providerName;
	}

	public function setProviderName(string $providerName): self {
		$this->providerName = mb_substr(trim($providerName), 0, 255);

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	/**
	 * A card with a url but no title says nothing the post itself does not
	 * already say, so it is not worth rendering.
	 */
	public function isEmpty(): bool {
		return $this->title === '';
	}

	/**
	 * Mastodon's PreviewCard. The fields this app cannot fill are still
	 * present, so clients that read them blindly keep working.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'url' => $this->getUrl(),
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
			'type' => 'link',
			'author_name' => '',
			'author_url' => '',
			'provider_name' => $this->getProviderName(),
			'provider_url' => '',
			'html' => '',
			'width' => 0,
			'height' => 0,
			'image' => ($this->image === '') ? null : $this->image,
			'embed_url' => '',
			'blurhash' => null
		];
	}
}
