<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

/**
 * A cursor over a prepared list of rows. See FakeQueryBuilder for why this
 * implements no interface.
 */
class FakeResult {
	public bool $closed = false;

	/** @param array<array<string, mixed>> $rows */
	public function __construct(
		private array $rows,
	) {
	}

	public function fetch() {
		return array_shift($this->rows) ?? false;
	}

	public function fetchOne() {
		$row = array_shift($this->rows);

		return ($row === null) ? false : reset($row);
	}

	/** @return array<array<string, mixed>> */
	public function fetchAll(): array {
		$rows = $this->rows;
		$this->rows = [];

		return $rows;
	}

	public function closeCursor(): bool {
		$this->closed = true;

		return true;
	}
}
