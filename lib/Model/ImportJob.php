<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * One import an account asked for: a file it uploaded, or a server it named,
 * worked through in the background and counted as it goes.
 *
 * What the Migration page shows while it runs and after: the kind, where it
 * got to, and what it came to. The file itself is kept in appdata under
 * `file` until the run has read it, and never after.
 */
class ImportJob implements JsonSerializable {
	public const STATUS_QUEUED = 'queued';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE = 'done';
	public const STATUS_FAILED = 'failed';

	public const KIND_FOLLOWS = 'follows';
	public const KIND_BLOCKS = 'blocks';
	public const KIND_MUTES = 'mutes';
	public const KIND_LISTS = 'lists';
	public const KIND_POSTS = 'posts';
	public const KIND_MOVE_IN = 'move_in';

	public const KINDS = [
		self::KIND_FOLLOWS, self::KIND_BLOCKS, self::KIND_MUTES, self::KIND_LISTS,
		self::KIND_POSTS, self::KIND_MOVE_IN,
	];

	private int $id = 0;
	private string $userId = '';
	private string $kind = '';
	private string $status = self::STATUS_QUEUED;
	private int $total = 0;
	private int $done = 0;
	private int $skipped = 0;
	private int $failed = 0;
	/** @var array<string, mixed> */
	private array $report = [];
	/** @var array<string, mixed> */
	private array $options = [];
	private string $file = '';
	private int $creation = 0;
	private int $updated = 0;

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getUserId(): string {
		return $this->userId;
	}

	public function setUserId(string $userId): self {
		$this->userId = $userId;

		return $this;
	}

	public function getKind(): string {
		return $this->kind;
	}

	public function setKind(string $kind): self {
		$this->kind = $kind;

		return $this;
	}

	public function getStatus(): string {
		return $this->status;
	}

	public function setStatus(string $status): self {
		$this->status = $status;

		return $this;
	}

	/** Whether the run is still ahead or under way. */
	public function isActive(): bool {
		return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
	}

	public function getTotal(): int {
		return $this->total;
	}

	public function setTotal(int $total): self {
		$this->total = $total;

		return $this;
	}

	public function getDone(): int {
		return $this->done;
	}

	public function setDone(int $done): self {
		$this->done = $done;

		return $this;
	}

	public function getSkipped(): int {
		return $this->skipped;
	}

	public function setSkipped(int $skipped): self {
		$this->skipped = $skipped;

		return $this;
	}

	public function getFailed(): int {
		return $this->failed;
	}

	public function setFailed(int $failed): self {
		$this->failed = $failed;

		return $this;
	}

	/** @return array<string, mixed> */
	public function getReport(): array {
		return $this->report;
	}

	/** @param array<string, mixed> $report */
	public function setReport(array $report): self {
		$this->report = $report;

		return $this;
	}

	/** @return array<string, mixed> */
	public function getOptions(): array {
		return $this->options;
	}

	/** @param array<string, mixed> $options */
	public function setOptions(array $options): self {
		$this->options = $options;

		return $this;
	}

	public function getFile(): string {
		return $this->file;
	}

	public function setFile(string $file): self {
		$this->file = $file;

		return $this;
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

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'kind' => $this->kind,
			'status' => $this->status,
			'total' => $this->total,
			'done' => $this->done,
			'skipped' => $this->skipped,
			'failed' => $this->failed,
			'report' => (object)$this->report,
			'options' => (object)$this->options,
			'created_at' => gmdate('Y-m-d\TH:i:s\Z', $this->creation),
			'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $this->updated),
		];
	}
}
