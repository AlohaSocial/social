<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * One block/mute relation owned by a local actor.
 */
class ActorRelation implements JsonSerializable {
	public const TYPE_BLOCK = 'block';
	public const TYPE_MUTE = 'mute';
	public const TYPE_BLOCKED_BY = 'blocked_by';

	/**
	 * "Do not show me this account's boosts": Mastodon's `reblogs` on the
	 * follow, reported back as `showing_reblogs`. Here rather than in
	 * `AccountRelationService` because the home timeline query reads it. A row
	 * only ever means no; showing boosts is the default.
	 */
	public const TYPE_HIDE_REBLOGS = 'hide_boosts';

	private int $id = 0;
	private string $actorIdPrim = '';
	private string $objectId = '';
	private string $objectIdPrim = '';
	private string $type = '';
	private bool $notifications = true;
	private int $creation = 0;

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setActorIdPrim(string $actorIdPrim): self {
		$this->actorIdPrim = $actorIdPrim;

		return $this;
	}

	public function getActorIdPrim(): string {
		return $this->actorIdPrim;
	}

	public function setObjectId(string $objectId): self {
		$this->objectId = $objectId;

		return $this;
	}

	public function getObjectId(): string {
		return $this->objectId;
	}

	public function setObjectIdPrim(string $objectIdPrim): self {
		$this->objectIdPrim = $objectIdPrim;

		return $this;
	}

	public function getObjectIdPrim(): string {
		return $this->objectIdPrim;
	}

	public function setType(string $type): self {
		$this->type = $type;

		return $this;
	}

	public function getType(): string {
		return $this->type;
	}

	public function setNotifications(bool $notifications): self {
		$this->notifications = $notifications;

		return $this;
	}

	public function isNotifications(): bool {
		return $this->notifications;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'objectId' => $this->getObjectId(),
			'type' => $this->getType(),
			'notifications' => $this->isNotifications(),
			'creation' => $this->getCreation(),
		];
	}
}
