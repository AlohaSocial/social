<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\DbTime;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * An album an account curates out of its own posts, as on Pixelfed: the
 * owner's own posts, in a chosen order, under a title.
 *
 * `id` is an integer in the row and a string in the JSON. `ownerId` is not
 * shown to clients; it lets CollectionsRequest check the owner as a SQL
 * predicate.
 */
class Collection implements IQueryRow, JsonSerializable {
	use TArrayTools;

	/** Anyone, including a signed-out visitor. */
	public const VISIBILITY_PUBLIC = Stream::TYPE_PUBLIC;
	/** The owner's followers, and the owner. */
	public const VISIBILITY_FOLLOWERS = Stream::TYPE_FOLLOWERS;

	/**
	 * @var string[] the two that mean anything for an album. A direct
	 *               collection would have nobody to be direct to.
	 */
	public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_FOLLOWERS];

	public const DEFAULT_VISIBILITY = self::VISIBILITY_PUBLIC;

	/** What Mastodon's own title column admits. */
	public const TITLE_MAX = 255;

	private int $id = 0;
	private string $ownerId = '';
	private string $title = '';
	private string $description = '';
	private string $visibility = self::DEFAULT_VISIBILITY;
	private int $creation = 0;
	private int $updated = 0;
	private int $size = 0;

	/** @var Stream[] the first few, for a cover; not every item */
	private array $preview = [];

	/** the owner, as a client sees them; attached on the way out, never stored */
	private ?Person $account = null;

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

	public function getTitle(): string {
		return $this->title;
	}

	public function setTitle(string $title): self {
		$this->title = mb_substr(trim($title), 0, self::TITLE_MAX);

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	public function getVisibility(): string {
		return $this->visibility;
	}

	/** Anything that is not one of the two admitted values means public. */
	public function setVisibility(string $visibility): self {
		$this->visibility = in_array($visibility, self::VISIBILITIES, true)
			? $visibility
			: self::DEFAULT_VISIBILITY;

		return $this;
	}

	public function isPublic(): bool {
		return $this->visibility === self::VISIBILITY_PUBLIC;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getUpdated(): int {
		return $this->updated;
	}

	public function setUpdated(int $updated): self {
		$this->updated = $updated;

		return $this;
	}

	public function getSize(): int {
		return $this->size;
	}

	public function setSize(int $size): self {
		$this->size = $size;

		return $this;
	}

	/** @return Stream[] */
	public function getPreview(): array {
		return $this->preview;
	}

	/** @param Stream[] $preview */
	public function setPreview(array $preview): self {
		$this->preview = $preview;

		return $this;
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		$this->setId($this->getInt('id', $data));
		$this->setOwnerId($this->get('actor_id', $data));
		$this->setTitle($this->get('title', $data));
		$this->setDescription($this->get('description', $data));
		$this->setVisibility($this->get('visibility', $data, self::DEFAULT_VISIBILITY));
		$creation = $this->get('creation', $data);
		$this->setCreation(DbTime::toTimestamp($creation));
		$updated = $this->get('updated', $data);
		$this->setUpdated(DbTime::toTimestamp($updated));
		// present only when the read asked for it
		$this->setSize($this->getInt('size', $data));
	}

	public function getAccount(): ?Person {
		return $this->account;
	}

	public function setAccount(?Person $account): self {
		$this->account = $account;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		$this->account?->setExportFormat(ACore::FORMAT_LOCAL);

		return [
			'id' => (string)$this->getId(),
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
			'visibility' => $this->getVisibility(),
			'created_at' => date('c', $this->getCreation()),
			'updated_at' => date('c', ($this->getUpdated() === 0) ? $this->getCreation() : $this->getUpdated()),
			'size' => $this->getSize(),
			// whose it is, to head the page and decide whether the reader may manage it
			'account' => $this->account,
			// exported in the client format, as every other post a client is
			// handed by this API is
			'posts' => array_map(static fn (Stream $post): array => $post->jsonSerialize(), $this->getPreview()),
		];
	}
}
