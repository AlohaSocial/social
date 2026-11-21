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

/**
 * Mastodon's Conversation entity: one thread of direct messages.
 *
 * `id` is the nid of the thread's root post, the one message every message in
 * it agrees on, so it stays the same between requests (no conversation row
 * exists; see ConversationService). `accounts` are the other participants,
 * never the viewer, so a note to self has none, as on Mastodon. `last_status`
 * is null only when the newest message could not be read back.
 */
class Conversation implements JsonSerializable {
	private int|string $id = 0;
	private string $rootId = '';
	private bool $unread = false;
	/** @var Person[] */
	private array $accounts = [];
	private ?Stream $lastStatus = null;

	public function setId(int|string $id): self {
		$this->id = \OCA\Social\Tools\Nid::fromStorage($id);

		return $this;
	}

	public function getId(): int|string {
		return $this->id;
	}

	/** The ActivityPub id of the thread root, which `id` is the nid of. */
	public function setRootId(string $rootId): self {
		$this->rootId = $rootId;

		return $this;
	}

	public function getRootId(): string {
		return $this->rootId;
	}

	public function setUnread(bool $unread): self {
		$this->unread = $unread;

		return $this;
	}

	public function isUnread(): bool {
		return $this->unread;
	}

	/**
	 * @param Person[] $accounts
	 */
	public function setAccounts(array $accounts): self {
		$this->accounts = array_values($accounts);

		return $this;
	}

	/**
	 * @return Person[]
	 */
	public function getAccounts(): array {
		return $this->accounts;
	}

	public function setLastStatus(?Stream $lastStatus): self {
		$this->lastStatus = $lastStatus;

		return $this;
	}

	public function getLastStatus(): ?Stream {
		return $this->lastStatus;
	}

	/**
	 * The nid the page cursor moves on: the thread's newest message, which
	 * conversations are ordered by.
	 */
	public function getLastStatusNid(): int|string {
		return ($this->lastStatus === null) ? 0 : $this->lastStatus->getNid();
	}

	/**
	 * Exactly Mastodon's four keys; `id` is a string, as everywhere on this wire.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		foreach ($this->accounts as $account) {
			$account->setExportFormat(ACore::FORMAT_LOCAL);
		}

		$lastStatus = $this->lastStatus;
		if ($lastStatus !== null) {
			$lastStatus->setExportFormat(ACore::FORMAT_LOCAL);
		}

		return [
			'id' => (string)$this->getId(),
			'unread' => $this->isUnread(),
			'accounts' => $this->accounts,
			'last_status' => $lastStatus,
		];
	}
}
