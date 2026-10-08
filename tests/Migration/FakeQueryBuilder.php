<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;

/**
 * A query builder that records what it was asked to build and answers
 * executeQuery() from the row sets its connection was given.
 *
 * It implements no interface on purpose — nothing in a migration or a repair
 * step type-hints the builder, and `IQueryBuilder` cannot be loaded in this
 * suite (see FakeConnection). Placeholders are the values themselves, so a
 * recorded predicate reads as the comparison it stands for.
 */
class FakeQueryBuilder {
	public ?string $table = null;
	/** @var string[] */
	public array $selects = [];
	/** @var array<string, mixed> column => value, as set() received them */
	public array $sets = [];
	/** @var array<int, string> one entry per where()/andWhere() predicate */
	public array $wheres = [];
	public ?string $orderBy = null;
	public ?int $maxResults = null;
	public int $statements = 0;
	/** Whether this builder is a DELETE rather than a select or an update. */
	public bool $deletes = false;
	/** @var array<array<string, mixed>> what executeQuery() handed back */
	public array $rows = [];

	public function __construct(
		private FakeConnection $connection,
		private ?Closure $onStatement = null,
	) {
	}

	public function select(...$selects): self {
		foreach ($selects as $select) {
			$this->selects[] = (string)$select;
		}

		return $this;
	}

	public function addSelect(...$selects): self {
		return $this->select(...$selects);
	}

	public function from($table, $alias = null): self {
		$this->table ??= (string)$table;

		return $this;
	}

	public function update($table = null, $alias = null): self {
		$this->table = (string)$table;

		return $this;
	}

	public function delete($table = null, $alias = null): self {
		$this->table = (string)$table;
		$this->deletes = true;

		return $this;
	}

	public function set($column, $value): self {
		$this->sets[(string)$column] = $value;

		return $this;
	}

	public function where(...$predicates): self {
		foreach ($predicates as $predicate) {
			$this->wheres[] = (string)$predicate;
		}

		return $this;
	}

	public function andWhere(...$predicates): self {
		return $this->where(...$predicates);
	}

	public function orderBy($sort, $order = null): self {
		$this->orderBy = trim((string)$sort . ' ' . (string)$order);

		return $this;
	}

	public function setMaxResults($max): self {
		$this->maxResults = (int)$max;

		return $this;
	}

	public function createNamedParameter($value, $type = null, $placeHolder = null) {
		return $value;
	}

	public function expr(): FakeExpressionBuilder {
		return new FakeExpressionBuilder();
	}

	public function func(): FakeFunctionBuilder {
		return new FakeFunctionBuilder();
	}

	public function executeQuery(): FakeResult {
		$this->rows = $this->connection->nextRowSet();

		return new FakeResult($this->rows);
	}

	public function executeStatement(): int {
		$this->statements++;
		if ($this->onStatement !== null) {
			($this->onStatement)($this);
		}

		return 1;
	}
}
