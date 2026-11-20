<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

/**
 * Cells of the CSV files people download and open in a spreadsheet. A cell
 * starting with `=`, `+`, `-`, `@`, a tab or a carriage return is read as a
 * formula, and much of the content comes from other servers, so such a cell
 * gets a leading `'`, which the importers here strip again.
 */
final class Csv {
	private const FORMULA_START = "=+-@\t\r";

	/** A value a spreadsheet will not run; a number is left as it is. */
	public static function neutralise(string $value): string {
		if ($value === '' || is_numeric($value) || !str_contains(self::FORMULA_START, $value[0])) {
			return $value;
		}

		return "'" . $value;
	}

	/** A cell as neutralise() wrote it, read back. */
	public static function restore(string $value): string {
		if (strlen($value) > 1 && $value[0] === "'" && str_contains(self::FORMULA_START, $value[1])) {
			return substr($value, 1);
		}

		return $value;
	}

	/** A value as one CSV cell: neutralised, and quoted only where it has to be. */
	public static function cell(string $value): string {
		$value = self::neutralise($value);
		if (strpbrk($value, ",\"\r\n") === false) {
			return $value;
		}

		return '"' . str_replace('"', '""', $value) . '"';
	}
}
