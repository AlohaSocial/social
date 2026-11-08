<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * One moderation report, filed locally over the client API or received from a
 * remote instance as a Flag activity. Serialises as the Mastodon Report entity.
 */
class Report implements JsonSerializable {
	public const CATEGORY_SPAM = 'spam';
	public const CATEGORY_LEGAL = 'legal';
	public const CATEGORY_VIOLATION = 'violation';
	public const CATEGORY_OTHER = 'other';

	public const CATEGORIES = [
		self::CATEGORY_SPAM,
		self::CATEGORY_LEGAL,
		self::CATEGORY_VIOLATION,
		self::CATEGORY_OTHER,
	];

	private int $id = 0;
	private string $actorId = '';
	private string $accountId = '';
	/** @var string[] */
	private array $statusIds = [];
	private string $comment = '';
	private string $category = self::CATEGORY_OTHER;
	/** @var list<array{id: string, text: string}> */
	private array $rules = [];
	private bool $local = true;
	private bool $resolved = false;
	private bool $forwarded = false;
	private int $creation = 0;
	private ?Person $targetAccount = null;

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setAccountId(string $accountId): self {
		$this->accountId = $accountId;

		return $this;
	}

	public function getAccountId(): string {
		return $this->accountId;
	}

	/**
	 * @param string[] $statusIds
	 */
	public function setStatusIds(array $statusIds): self {
		$this->statusIds = array_values(array_filter($statusIds, fn ($id): bool => is_string($id) && $id !== ''));

		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getStatusIds(): array {
		return $this->statusIds;
	}

	public function setComment(string $comment): self {
		$this->comment = $comment;

		return $this;
	}

	public function getComment(): string {
		return $this->comment;
	}

	public function setCategory(string $category): self {
		$this->category = in_array($category, self::CATEGORIES, true) ? $category : self::CATEGORY_OTHER;

		return $this;
	}

	public function getCategory(): string {
		return $this->category;
	}

	/**
	 * The instance rules the reporter says were broken, as they read when the
	 * report was filed: a rule's id is its line number, which an edit of the
	 * rules shifts, so the text is kept beside it.
	 *
	 * @param array<array-key, mixed> $rules
	 */
	public function setRules(array $rules): self {
		$this->rules = [];
		foreach ($rules as $rule) {
			if (!is_array($rule) || !isset($rule['id'], $rule['text'])) {
				continue;
			}
			$this->rules[] = ['id' => (string)$rule['id'], 'text' => (string)$rule['text']];
		}

		return $this;
	}

	/**
	 * The stored `rules` column: a JSON list, or nothing.
	 *
	 * @return array<array-key, mixed>
	 */
	public static function decodeRules(?string $stored): array {
		$rules = json_decode((string)$stored, true);

		return is_array($rules) ? $rules : [];
	}

	/** @return list<array{id: string, text: string}> */
	public function getRules(): array {
		return $this->rules;
	}

	/** @return list<string> */
	public function getRuleIds(): array {
		return array_column($this->rules, 'id');
	}

	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}

	public function isLocal(): bool {
		return $this->local;
	}

	/**
	 * Whether the Flag was delivered to the instance the reported account is
	 * on. Only ever true for a local report about a remote account: there is
	 * nowhere to forward a report about one of our own, and a report that
	 * arrived as a Flag is not ours to pass on.
	 */
	public function setForwarded(bool $forwarded): self {
		$this->forwarded = $forwarded;

		return $this;
	}

	public function isForwarded(): bool {
		return $this->forwarded;
	}

	public function setResolved(bool $resolved): self {
		$this->resolved = $resolved;

		return $this;
	}

	public function isResolved(): bool {
		return $this->resolved;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setTargetAccount(?Person $targetAccount): self {
		$this->targetAccount = $targetAccount;

		return $this;
	}

	public function getTargetAccount(): ?Person {
		return $this->targetAccount;
	}

	/**
	 * This report as the administration page's moderation table reads it.
	 *
	 * Not the Mastodon entity above: the table shows who was reported and
	 * what already stands against them, which the API's Report does not
	 * carry. It appends the pages `ModerationController::reports()` answers
	 * to the rows `AdminSettings` rendered the page with and cannot tell them
	 * apart, so both go through here and neither can drift from the other.
	 *
	 * @param array<string, string> $decisions the standing decision per account, keyed by actor id
	 *
	 * @return array<string, mixed>
	 */
	public function moderationRow(array $decisions): array {
		$target = $this->getTargetAccount();
		$targetId = $target !== null ? $target->getId() : $this->getAccountId();
		$handle = '';
		if ($target !== null) {
			$handle = $target->getAccount() !== '' ? $target->getAccount() : $target->getPreferredUsername();
		}

		return [
			'id' => $this->getId(),
			'account_id' => $targetId,
			'account' => $handle,
			'reporter' => $this->getActorId(),
			'local' => $this->isLocal(),
			'category' => $this->getCategory(),
			'comment' => $this->getComment(),
			'status_ids' => $this->getStatusIds(),
			'rules' => array_column($this->getRules(), 'text'),
			'creation' => $this->getCreation(),
			'resolved' => $this->isResolved(),
			'level' => $decisions[$targetId] ?? '',
		];
	}

	/**
	 * The Mastodon Report entity.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'action_taken' => $this->isResolved(),
			'action_taken_at' => null,
			'category' => $this->getCategory(),
			'comment' => $this->getComment(),
			'forwarded' => $this->isForwarded(),
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->getCreation()) . '.000Z',
			'status_ids' => $this->getStatusIds(),
			'rule_ids' => $this->getRules() === [] ? null : $this->getRuleIds(),
			'target_account' => $this->getTargetAccount(),
		];
	}
}
