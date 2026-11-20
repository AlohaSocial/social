/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Row ids (status and notification ids, `min_id`/`max_id` cursors) are
 * snowflakes well above `Number.MAX_SAFE_INTEGER`, so they are carried as
 * strings and compared as BigInts, never parsed into a Number.
 */

const ID_PATTERN = /^\d+$/

/**
 * One row id as the number it is, or null for anything that is not one.
 *
 * @param {string|number|undefined|null} id the id
 * @return {bigint|null}
 */
function parseId(id) {
	const text = String(id ?? '').trim()

	return ID_PATTERN.test(text) ? BigInt(text) : null
}

/**
 * One row id as the number it is, for comparing against another.
 *
 * @param {string|number|undefined|null} id the id
 * @return {bigint} it as a number; 0 for anything that is not one
 */
export function asId(id) {
	return parseId(id) ?? 0n
}

/**
 * Whether one row id is newer than another.
 *
 * @param {string|number|undefined|null} id the id in question
 * @param {string|number|undefined|null} than what to hold it against
 * @return {boolean}
 */
export function isNewerId(id, than) {
	return asId(id) > asId(than)
}

/**
 * The newer of two row ids.
 *
 * @param {string|number|undefined|null} a one id
 * @param {string|number|undefined|null} b the other
 * @return {string} whichever is newer
 */
export function newerId(a, b) {
	return isNewerId(a, b) ? String(a) : String(b ?? '0')
}

/**
 * The id at one end of a list, as the string it was given as.
 *
 * @param {Array<string|number|undefined|null>} ids the ids
 * @param {(candidate: bigint, best: bigint) => boolean} better whether to take the candidate
 * @return {string|undefined} the id, or undefined when the list holds none
 */
function endOf(ids, better) {
	let found
	let foundValue

	for (const id of ids) {
		const value = parseId(id)
		if (value === null) {
			continue
		}

		if (foundValue === undefined || better(value, foundValue)) {
			found = String(id)
			foundValue = value
		}
	}

	return found
}

/**
 * The newest of a list of row ids — a `min_id` cursor for catching up.
 *
 * @param {Array<string|number|undefined|null>} ids the ids
 * @return {string|undefined} the id, or undefined when the list holds none
 */
export function newestId(ids) {
	return endOf(ids, (candidate, best) => candidate > best)
}

/**
 * The oldest of a list of row ids — a `max_id` cursor for the next page.
 *
 * @param {Array<string|number|undefined|null>} ids the ids
 * @return {string|undefined} the id, or undefined when the list holds none
 */
export function oldestId(ids) {
	return endOf(ids, (candidate, best) => candidate < best)
}
