/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * A count in the reader's own digits and grouping, using the locale Nextcloud
 * has for them so the digits match the words beside them.
 *
 * @param {number|string|null|undefined} value the count
 * @return {string} it, formatted
 */
export function formatCount(value) {
	const number = Number(value)

	return (Number.isFinite(number) ? number : 0).toLocaleString(getCanonicalLocale())
}

/**
 * A compact count for the narrow action row, where a full decimal count
 * would crowd its neighbours. The complete localized value remains available
 * to the reader through the counter's label and title.
 *
 * @param {number|string|null|undefined} value the count
 * @return {string} a locale-aware compact count
 */
export function formatCompactCount(value) {
	const number = Number(value)
	const finite = Number.isFinite(number) ? number : 0

	return new Intl.NumberFormat(getCanonicalLocale(), {
		notation: finite >= 1000 ? 'compact' : 'standard',
		maximumFractionDigits: finite >= 1000 ? 1 : 0,
	}).format(finite)
}
