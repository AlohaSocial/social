<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

/**
 * Predicates as readable strings.
 *
 * It implements no interface on purpose: `IExpressionBuilder` carries
 * Doctrine's parameter-type constants in its signature and cannot be loaded —
 * let alone doubled — in a suite that runs without a database. The query
 * builder's own `expr()` has no declared return type, so a stand-in is what a
 * test can put there.
 */
class FakeExpressions {
	public function eq($x, $y, $type = null): string {
		return $x . ' = ' . $y;
	}

	public function neq($x, $y, $type = null): string {
		return $x . ' <> ' . $y;
	}

	public function in($x, $y, $type = null): string {
		return $x . ' IN (' . $y . ')';
	}

	public function notIn($x, $y, $type = null): string {
		return $x . ' NOT IN ' . $y;
	}

	public function gt($x, $y, $type = null): string {
		return $x . ' > ' . $y;
	}

	public function lt($x, $y, $type = null): string {
		return $x . ' < ' . $y;
	}

	public function gte($x, $y, $type = null): string {
		return $x . ' >= ' . $y;
	}

	public function lte($x, $y, $type = null): string {
		return $x . ' <= ' . $y;
	}

	public function like($x, $y, $type = null): string {
		return $x . ' LIKE ' . $y;
	}

	public function isNull($x): string {
		return $x . ' IS NULL';
	}

	public function andX(...$parts): string {
		return '(' . implode(' AND ', $this->parts($parts, 'andX')) . ')';
	}

	public function orX(...$parts): string {
		return '(' . implode(' OR ', $this->parts($parts, 'orX')) . ')';
	}

	/**
	 * The server logs `Calling IQueryBuilder::orX without parameters is
	 * deprecated and will throw soon` and hands back a composite that renders
	 * as nothing, so the condition silently stops restricting anything. This
	 * refuses it here instead, where a test can say which query built it.
	 *
	 * @param array<int, mixed> $parts
	 * @return array<int, mixed>
	 */
	private function parts(array $parts, string $method): array {
		if ($parts === []) {
			throw new \InvalidArgumentException($method . '() was called with no parts');
		}

		return $parts;
	}
}
