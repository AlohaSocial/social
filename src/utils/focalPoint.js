/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Focal points in Mastodon's coordinates: x and y from -1 to 1, origin at the
 * centre and y pointing up. Converts to and from pointer offsets and CSS
 * `object-position`, which measure from the top left with y pointing down.
 */

/** how far one arrow key press moves the point */
export const NUDGE = 0.05

/**
 * @typedef {object} FocalPoint
 * @property {number} x -1 (left edge) to 1 (right edge)
 * @property {number} y -1 (bottom edge) to 1 (top edge)
 */

/**
 * @param {number} value a coordinate
 * @return {number} the same, held inside -1..1 and rounded to two decimals
 */
function clamp(value) {
	const bounded = Math.min(1, Math.max(-1, Number.isFinite(value) ? value : 0))

	// two decimals is finer than any crop can show and avoids float noise on the wire
	return Math.round(bounded * 100) / 100
}

/**
 * @param {FocalPoint} focus a point in any state
 * @return {FocalPoint} the same point, held inside the photo
 */
export function clampFocus(focus) {
	return { x: clamp(focus?.x), y: clamp(focus?.y) }
}

/**
 * Where a pointer landed, as a focal point.
 *
 * @param {number} offsetX pixels from the left edge of the photo
 * @param {number} offsetY pixels from the top edge of the photo
 * @param {number} width the photo's width on screen, in pixels
 * @param {number} height the photo's height on screen, in pixels
 * @return {FocalPoint} the point, or the centre when the photo has no size
 */
export function focusFromPoint(offsetX, offsetY, width, height) {
	if (!(width > 0) || !(height > 0)) {
		return { x: 0, y: 0 }
	}

	return clampFocus({
		x: (offsetX / width) * 2 - 1,
		y: 1 - (offsetY / height) * 2,
	})
}

/**
 * Where a focal point sits on the photo, for CSS: `left` and `top` as
 * percentages, and the same pair as an `object-position` value.
 *
 * @param {FocalPoint|null|undefined} focus the point; nothing means the centre
 * @return {{left: string, top: string, objectPosition: string}}
 */
export function positionOfFocus(focus) {
	const point = isFocalPoint(focus) ? focus : { x: 0, y: 0 }
	const left = ((point.x + 1) / 2 * 100).toFixed(2) + '%'
	const top = ((1 - point.y) / 2 * 100).toFixed(2) + '%'

	return { left, top, objectPosition: `${left} ${top}` }
}

/**
 * @param {FocalPoint} focus the point
 * @param {number} dx how far to move it to the right, in focus units
 * @param {number} dy how far to move it up, in focus units
 * @return {FocalPoint} the moved point, still inside the photo
 */
export function nudgeFocus(focus, dx, dy) {
	const from = isFocalPoint(focus) ? focus : { x: 0, y: 0 }

	return clampFocus({ x: from.x + dx, y: from.y + dy })
}

/**
 * The point as `POST /api/v1/media` and `PUT /api/v1/media/{id}` take it:
 * Mastodon's `focus`, two numbers with a comma between them.
 *
 * @param {FocalPoint} focus the point
 * @return {string} for example `0.25,-0.5`
 */
export function focusParam(focus) {
	const point = clampFocus(focus)

	return `${point.x},${point.y}`
}

/**
 * @param {unknown} focus anything an attachment's `meta.focus` may hold
 * @return {boolean} whether it is a usable pair of numbers
 */
export function isFocalPoint(focus) {
	if (focus === null || typeof focus !== 'object') {
		return false
	}

	const pair = /** @type {{x?: unknown, y?: unknown}} */ (focus)

	return Number.isFinite(pair.x) && Number.isFinite(pair.y)
}
