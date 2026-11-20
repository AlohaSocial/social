/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A long page of sections with a rail beside it (settings, administration):
 * which section the reader is looking at, and going to one.
 */

/**
 * How far down the window a section's top has to be to count as the one being
 * read. Just past a section's `scroll-margin`, so a section jumped to is marked.
 */
export const RAIL_BAND = 96

/**
 * The section the reader is looking at: the last one whose heading has passed
 * the top of the window. The topmost visible section would be wrong while a
 * tall section straddles the top, and a band lower down would mark the section
 * after a short one a rail link scrolled to.
 *
 * @param {string[]} ids the section ids, in the order they are drawn
 * @param {string} current what is marked now, kept when nothing has passed yet
 * @return {string} the id to mark
 */
export function currentSection(ids, current = '') {
	let found = current

	for (const id of ids) {
		const el = document.getElementById(id)
		if (el && el.getBoundingClientRect().top <= RAIL_BAND) {
			found = id
		}
	}

	return found
}

/**
 * Calls back whenever a section comes into or goes out of view. The observer
 * is only the trigger: its entries are only the sections that changed, so
 * `currentSection` works out the current one from all of them.
 *
 * @param {string[]} ids the section ids to watch
 * @param {() => void} onChange called with no arguments
 * @return {IntersectionObserver|null} to disconnect, or null where the browser has none
 */
export function watchSections(ids, onChange) {
	if (typeof IntersectionObserver !== 'function') {
		return null
	}

	const observer = new IntersectionObserver(() => onChange(), { threshold: 0 })
	for (const id of ids) {
		const el = document.getElementById(id)
		if (el) {
			observer.observe(el)
		}
	}

	return observer
}

/**
 * Puts a section in view, the way the rest of the page scrolls.
 *
 * @param {string} id the section to go to
 * @return {boolean} whether there was a section to go to
 */
export function scrollToSection(id) {
	const section = document.getElementById(id)
	if (!section || typeof section.scrollIntoView !== 'function') {
		return false
	}

	const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false
	section.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' })

	return true
}
