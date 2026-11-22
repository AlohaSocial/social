<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * One relay subscription: the `Follow` this instance sent, whether the relay
 * answered it, and where to deliver.
 */
class Relay implements JsonSerializable {
	/** The Follow has gone out and the relay has not answered. */
	public const STATUS_PENDING = 'pending';
	/** The relay accepted: its posts are taken in and ours are sent to it. */
	public const STATUS_ACCEPTED = 'accepted';
	/** The relay said no, or the Follow could not be delivered. */
	public const STATUS_REJECTED = 'rejected';

	private int $id = 0;
	private string $actorId = '';
	private string $inbox = '';
	private string $status = self::STATUS_PENDING;
	private string $followId = '';
	private string $error = '';
	private int $creation = 0;
	private int $lastUpdate = 0;

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

	public function getInbox(): string {
		return $this->inbox;
	}

	public function setInbox(string $inbox): self {
		$this->inbox = $inbox;

		return $this;
	}

	public function getStatus(): string {
		return $this->status;
	}

	public function setStatus(string $status): self {
		$this->status = $status;

		return $this;
	}

	public function isAccepted(): bool {
		return $this->status === self::STATUS_ACCEPTED;
	}

	public function getFollowId(): string {
		return $this->followId;
	}

	public function setFollowId(string $followId): self {
		$this->followId = $followId;

		return $this;
	}

	public function getError(): string {
		return $this->error;
	}

	public function setError(string $error): self {
		$this->error = $error;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getLastUpdate(): int {
		return $this->lastUpdate;
	}

	public function setLastUpdate(int $lastUpdate): self {
		$this->lastUpdate = $lastUpdate;

		return $this;
	}

	/** The host the relay lives on, which is what a panel shows. */
	public function getHost(): string {
		return (string)parse_url($this->actorId, PHP_URL_HOST);
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'actor_id' => $this->getActorId(),
			'host' => $this->getHost(),
			'inbox' => $this->getInbox(),
			'status' => $this->getStatus(),
			'error' => $this->getError(),
			'created_at' => $this->getCreation(),
			'last_update' => $this->getLastUpdate(),
		];
	}
}
