<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\Report;

/**
 * Mastodon's `Admin::Report`: the moderator's view of a report, with who
 * filed it, who is dealing with it and who acted on it, each an
 * `Admin::Account`; the last two are null until somebody takes it.
 *
 * `rules` is always `[]`, since reports here carry a category and rules have
 * no ids. `forwarded` is true when the reporter asked for it and the remote
 * instance accepted the `Flag`. `updated_at` is the last decision, or the
 * filing time while there is none.
 */
class AdminReport implements JsonSerializable {
	private ?AdminAccount $account = null;
	private ?AdminAccount $targetAccount = null;
	private ?AdminAccount $assignedAccount = null;
	private ?AdminAccount $actionTakenByAccount = null;
	private int $actionTakenAt = 0;
	/** @var array<int, mixed> the reported statuses, as Status entities */
	private array $statuses = [];

	public function __construct(
		private Report $report,
	) {
	}

	public function getReport(): Report {
		return $this->report;
	}

	public function getId(): int {
		return $this->report->getId();
	}

	public function setAccount(?AdminAccount $account): self {
		$this->account = $account;

		return $this;
	}

	public function getAccount(): ?AdminAccount {
		return $this->account;
	}

	public function setTargetAccount(?AdminAccount $targetAccount): self {
		$this->targetAccount = $targetAccount;

		return $this;
	}

	public function getTargetAccount(): ?AdminAccount {
		return $this->targetAccount;
	}

	public function setAssignedAccount(?AdminAccount $assignedAccount): self {
		$this->assignedAccount = $assignedAccount;

		return $this;
	}

	public function getAssignedAccount(): ?AdminAccount {
		return $this->assignedAccount;
	}

	public function setActionTakenByAccount(?AdminAccount $account): self {
		$this->actionTakenByAccount = $account;

		return $this;
	}

	public function getActionTakenByAccount(): ?AdminAccount {
		return $this->actionTakenByAccount;
	}

	public function setActionTakenAt(int $actionTakenAt): self {
		$this->actionTakenAt = $actionTakenAt;

		return $this;
	}

	public function getActionTakenAt(): int {
		return $this->actionTakenAt;
	}

	/**
	 * @param array<int, mixed> $statuses
	 */
	public function setStatuses(array $statuses): self {
		$this->statuses = array_values($statuses);

		return $this;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function getStatuses(): array {
		return $this->statuses;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->report->getId(),
			'action_taken' => $this->report->isResolved(),
			'action_taken_at' => ($this->actionTakenAt > 0) ? $this->date($this->actionTakenAt) : null,
			'category' => $this->report->getCategory(),
			'comment' => $this->report->getComment(),
			'forwarded' => $this->report->isForwarded(),
			'created_at' => $this->date($this->report->getCreation()),
			'updated_at' => $this->date(max($this->actionTakenAt, $this->report->getCreation())),
			'account' => $this->getAccount(),
			'target_account' => $this->getTargetAccount(),
			'assigned_account' => $this->getAssignedAccount(),
			'action_taken_by_account' => $this->getActionTakenByAccount(),
			'statuses' => $this->getStatuses(),
			'rules' => array_map(
				static fn (array $rule): array => ['id' => $rule['id'], 'text' => $rule['text'], 'hint' => ''],
				$this->report->getRules()
			),
		];
	}

	private function date(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z';
	}
}
