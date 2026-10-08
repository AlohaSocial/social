/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The search over a page of settings sections: a section is found when its
 * text says every word typed, in any order.
 */

/**
 * Lower case and without accents, so "benachrichtigung" finds
 * "Benachrichtigungen" and "e" finds "é".
 *
 * @param {string} text what to compare
 * @return {string}
 */
export function folded(text) {
	return String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

/**
 * Whether a text says every word of a query.
 *
 * @param {string} query what the reader typed
 * @param {string} text what a section says about itself
 * @return {boolean} true for a query with no words in it
 */
export function matchesQuery(query, text) {
	const haystack = folded(text)

	return folded(query).split(/\s+/).filter(Boolean).every((word) => haystack.includes(word))
}
