/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The element that scrolls. The window does not: Nextcloud gives an app a
 * fixed viewport and the content column scrolls inside it, so `window.scrollY`
 * is 0 wherever the reader is and `window.scrollTo()` moves nothing.
 *
 * @return {Element|null} the column, or null before the app has rendered
 */
export function scroller() {
	return document.querySelector('#app-content-vue')
}

/**
 * How far down the reader is.
 *
 * @return {number} the offset, in pixels
 */
export function scrollOffset() {
	const column = scroller()

	return column === null ? window.scrollY : column.scrollTop
}

/**
 * Takes the reader back to the top of the page: smoothly, or at once for a
 * reader who asked for less motion.
 */
export function scrollToTop() {
	const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true
	const target = scroller() ?? window
	if (typeof target.scrollTo === 'function') {
		target.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' })
	}
}
