<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * One channel: the binding between an account and the `Group` actor it
 * publishes videos under (an ordinary `social_actor` row): whose it is, its
 * name, and whether it is the default for videos.
 */
class Channel implements JsonSerializable {
	private int $id = 0;
	private string $actorId = '';
	private string $ownerId = '';
	private string $name = '';
	private string $description = '';
	private bool $default = false;
	/** filled in by the service where a client wants the handle, not the id */
	private string $handle = '';

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function getOwnerId(): string {
		return $this->ownerId;
	}

	public function setOwnerId(string $ownerId): self {
		$this->ownerId = $ownerId;

		return $this;
	}

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $name): self {
		$this->name = $name;

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	public function isDefault(): bool {
		return $this->default;
	}

	public function setDefault(bool $default): self {
		$this->default = $default;

		return $this;
	}

	public function getHandle(): string {
		return $this->handle;
	}

	public function setHandle(string $handle): self {
		$this->handle = $handle;

		return $this;
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'actor_id' => $this->getActorId(),
			'handle' => $this->getHandle(),
			'name' => $this->getName(),
			'description' => $this->getDescription(),
			'default' => $this->isDefault(),
		];
	}
}
