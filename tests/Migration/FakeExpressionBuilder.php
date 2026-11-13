<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

/**
 * Predicates as readable strings, for a test to assert on. See FakeQueryBuilder
 * for why none of this implements the interface it stands in for.
 */
class FakeExpressionBuilder {
	public function eq($x, $y, $type = null): string {
		return $x . ' = ' . $y;
	}

	public function neq($x, $y, $type = null): string {
		return $x . ' <> ' . $y;
	}

	public function gt($x, $y, $type = null): string {
		return $x . ' > ' . $y;
	}

	public function gte($x, $y, $type = null): string {
		return $x . ' >= ' . $y;
	}

	public function lt($x, $y, $type = null): string {
		return $x . ' < ' . $y;
	}

	public function isNull($x): string {
		return $x . ' IS NULL';
	}

	public function like($x, $y, $type = null): string {
		return $x . ' LIKE ' . $y;
	}

	public function notLike($x, $y, $type = null): string {
		return $x . ' NOT LIKE ' . $y;
	}

	public function emptyString($x): string {
		return $x . " = ''";
	}

	public function nonEmptyString($x): string {
		return $x . " <> ''";
	}

	public function andX(...$parts): string {
		return '(' . implode(' AND ', $parts) . ')';
	}

	public function orX(...$parts): string {
		return '(' . implode(' OR ', $parts) . ')';
	}
}
