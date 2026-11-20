<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\DbTime;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * A short (Pixelfed's story): one photo that stops existing after a day, with
 * a caption, a hold time and who has seen it.
 *
 * Not a post: no `social_stream` row, no ActivityPub identity, no recipients,
 * not federated, like Pixelfed's, so no peer keeps a copy past the day.
 */
class Story implements IQueryRow, JsonSerializable {
	use TArrayTools;

	/** How long a story lives. */
	public const LIFETIME = 24 * 3600;

	/** How long a client holds on one picture, in seconds. */
	public const DEFAULT_DURATION = 5;
	public const MIN_DURATION = 3;
	public const MAX_DURATION = 30;

	/** How many an account may have live at once. */
	public const MAX_PER_ACTOR = 40;

	public const CAPTION_MAX = 500;

	private int $id = 0;
	private string $ownerId = '';
	private string $documentId = '';
	private string $caption = '';
	private int $duration = self::DEFAULT_DURATION;
	private int $creation = 0;
	private int $expiresAt = 0;
	private bool $seen = false;
	private string $sourceId = '';
	private bool $local = true;
	private int $viewCount = 0;

	/** Filled in when the story is read for a client. */
	private ?MediaAttachment $media = null;
	private ?Person $author = null;

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getOwnerId(): string {
		return $this->ownerId;
	}

	public function setOwnerId(string $ownerId): self {
		$this->ownerId = $ownerId;

		return $this;
	}

	public function getDocumentId(): string {
		return $this->documentId;
	}

	public function setDocumentId(string $documentId): self {
		$this->documentId = $documentId;

		return $this;
	}

	public function getCaption(): string {
		return $this->caption;
	}

	public function setCaption(string $caption): self {
		$this->caption = mb_substr($caption, 0, self::CAPTION_MAX);

		return $this;
	}

	public function getDuration(): int {
		return $this->duration;
	}

	/** Clamped to a short hold time. */
	public function setDuration(int $duration): self {
		$this->duration = max(self::MIN_DURATION, min(self::MAX_DURATION, $duration));

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getExpiresAt(): int {
		return $this->expiresAt;
	}

	public function setExpiresAt(int $expiresAt): self {
		$this->expiresAt = $expiresAt;

		return $this;
	}

	public function hasExpired(?int $now = null): bool {
		return $this->expiresAt <= ($now ?? time());
	}

	/** The story's ActivityPub id: ours for a local story, theirs for one that arrived. */
	public function getSourceId(): string {
		return $this->sourceId;
	}

	public function setSourceId(string $sourceId): self {
		$this->sourceId = $sourceId;

		return $this;
	}

	public function isLocal(): bool {
		return $this->local;
	}

	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}

	public function isSeen(): bool {
		return $this->seen;
	}

	public function setSeen(bool $seen): self {
		$this->seen = $seen;

		return $this;
	}

	public function getViewCount(): int {
		return $this->viewCount;
	}

	public function setViewCount(int $viewCount): self {
		$this->viewCount = $viewCount;

		return $this;
	}

	public function getMedia(): ?MediaAttachment {
		return $this->media;
	}

	public function setMedia(?MediaAttachment $media): self {
		$this->media = $media;

		return $this;
	}

	public function getAuthor(): ?Person {
		return $this->author;
	}

	public function setAuthor(?Person $author): self {
		$this->author = $author;

		return $this;
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		$this->setId($this->getInt('id', $data));
		$this->setOwnerId($this->get('actor_id', $data));
		$this->setDocumentId($this->get('document_id', $data));
		$this->setCaption($this->get('caption', $data));
		$this->setDuration($this->getInt('duration', $data, self::DEFAULT_DURATION));

		$creation = $this->get('creation', $data);
		$this->setCreation(DbTime::toTimestamp($creation));
		$expires = $this->get('expires_at', $data);
		$this->setExpiresAt(DbTime::toTimestamp($expires));

		$this->setSourceId($this->get('source_id', $data));
		// rows from before the `local` column are this instance's own
		$this->setLocal($this->getBool('local', $data, true));
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'account_id' => $this->getOwnerId(),
			'caption' => $this->getCaption(),
			'duration' => $this->getDuration(),
			'created_at' => date('c', $this->getCreation()),
			'expires_at' => date('c', $this->getExpiresAt()),
			'seen' => $this->isSeen(),
			'view_count' => $this->getViewCount(),
			'media' => $this->getMedia(),
			'account' => $this->getAuthor(),
		];
	}
}
