<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Mastodon's FeaturedTag entity: a hashtag an account has pinned to its own
 * profile.
 *
 * `statuses_count` and `last_status_at` are counted from `social_stream_tag`
 * when the entity is built. `url` is set by the service, as in
 * `HashtagService::tagEntity()`, since a model has no URL generator. `owner`
 * is not shown to clients; FeaturedTagsRequest checks it in SQL.
 */
class FeaturedTag implements JsonSerializable {
	use TArrayTools;

	private int $id = 0;
	private string $ownerId = '';
	private string $hashtag = '';
	private string $url = '';
	private int $statusesCount = 0;
	private string $lastStatusAt = '';

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setOwnerId(string $ownerId): self {
		$this->ownerId = $ownerId;

		return $this;
	}

	public function getOwnerId(): string {
		return $this->ownerId;
	}

	public function setHashtag(string $hashtag): self {
		$this->hashtag = $hashtag;

		return $this;
	}

	public function getHashtag(): string {
		return $this->hashtag;
	}

	public function setUrl(string $url): self {
		$this->url = $url;

		return $this;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setStatusesCount(int $statusesCount): self {
		$this->statusesCount = $statusesCount;

		return $this;
	}

	public function getStatusesCount(): int {
		return $this->statusesCount;
	}

	public function setLastStatusAt(string $lastStatusAt): self {
		$this->lastStatusAt = $lastStatusAt;

		return $this;
	}

	public function getLastStatusAt(): string {
		return $this->lastStatusAt;
	}

	/** @param array<string, mixed> $data a row of `social_featured_tag` */
	public function importFromDatabase(array $data): self {
		$this->setId($this->getInt('id', $data))
			->setOwnerId($this->get('actor_id', $data))
			->setHashtag($this->get('hashtag', $data));

		return $this;
	}

	/**
	 * `last_status_at` is a date, not a timestamp (Mastodon sends
	 * `2022-08-29`), and null rather than '' when the tag was never used.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'name' => $this->getHashtag(),
			'url' => $this->getUrl(),
			'statuses_count' => $this->getStatusesCount(),
			'last_status_at' => ($this->lastStatusAt === '') ? null : $this->lastStatusAt,
		];
	}
}
