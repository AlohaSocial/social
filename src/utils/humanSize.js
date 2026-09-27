/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A number of bytes in the units a person reads.
 *
 * @param {number} bytes how many
 * @return {string} e.g. "4.2 MiB"
 */
export function humanSize(bytes) {
	const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
	let value = Math.max(0, Number(bytes) || 0)
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}

	return `${value < 10 && unit > 0 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`
}
