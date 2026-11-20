<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Tools\Nid;

/**
 * Mastodon's FilterStatus entity: one post a filter covers by name, what a
 * client's "filter this post" offers. Its `id` is its own, removed with
 * `DELETE /api/v2/filters/statuses/{id}`; the post's id is its nid as a
 * decimal string (see `Nid`).
 */
class FilterStatus implements JsonSerializable {
	private int $id = 0;
	private int $filterId = 0;
	private string $statusId = '0';

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setFilterId(int $filterId): self {
		$this->filterId = $filterId;

		return $this;
	}

	public function getFilterId(): int {
		return $this->filterId;
	}

	public function setStatusId(int|string $statusId): self {
		$this->statusId = Nid::normalize($statusId);

		return $this;
	}

	public function getStatusId(): string {
		return $this->statusId;
	}

	/** Both ids are strings on the wire, as every id in this API is. */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->id,
			'status_id' => $this->statusId,
		];
	}
}
