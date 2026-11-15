/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { nameMorph, uniqueTransitionName } from '../utils/viewTransition.js'

/**
 * The thing a page change was started from, carried into the page it opens.
 *
 * A post card pressed in a feed becomes the post at the head of its thread,
 * and a face pressed beside a post becomes the face at the top of the
 * profile. The page transition (see `pageTransition.js`) does the moving; this
 * only says which element on the old page and which on the new one are the
 * same thing.
 *
 * Which element was pressed is remembered from the click itself, because the
 * router is only told where to go, not what was pressed to get there — and a
 * post can be on screen twice, as itself and as a boost.
 */

/** How long a press is taken to be the cause of the navigation that follows. */
const FRESH_MS = 1000

/** @type {Element|null} */
let pressed = null
let pressedAt = 0

/**
 * Remembers what was pressed. Keyboard activation of a link is a click too.
 *
 * @param {Event} event the click
 */
export function rememberPress(event) {
	pressed = event.target instanceof Element ? event.target : null
	pressedAt = Date.now()
}

/**
 * Listens for presses on the whole page, ahead of anything that handles them.
 *
 * @param {Document|HTMLElement} [root] where to listen
 * @return {() => void} stops listening
 */
export function listenForPresses(root = document) {
	root.addEventListener('click', rememberPress, true)

	return () => root.removeEventListener('click', rememberPress, true)
}

/** @return {Element|null} what was pressed a moment ago, if it is still on the page */
function freshPress() {
	if (pressed === null || !pressed.isConnected || Date.now() - pressedAt > FRESH_MS) {
		return null
	}

	return pressed
}

/**
 * @param {string} value an attribute value
 * @return {string} the value, safe inside a quoted attribute selector
 */
function quoted(value) {
	return String(value).replace(/["\\]/g, '\\$&')
}

/**
 * What a route opens from, and what it lands on.
 *
 * @param {{name?: string|symbol|null, params?: Record<string, unknown>}} to the route being opened
 * @return {{from: string, to: string}|null} selectors for both ends, or null where nothing is carried
 */
export function sharedSelectors(to) {
	if (to?.name === 'single-post' && to.params?.id !== undefined) {
		const status = `[data-social-status="${quoted(String(to.params.id))}"]`

		return { from: status, to: `.social__pages ${status}` }
	}

	if (to?.name === 'profile') {
		return { from: '.avatardiv', to: '.social__pages .user-profile .avatardiv' }
	}

	return null
}

/**
 * Names the element a navigation starts from, before the old page is
 * pictured.
 *
 * @param {{name?: string|symbol|null, params?: Record<string, unknown>}} to the route being opened
 * @return {{land: () => void, release: () => void}|null} what to call once the new page is drawn and once
 *         the animation is over; null where there is nothing to carry
 */
export function departFor(to) {
	const selectors = sharedSelectors(to)
	const from = /** @type {HTMLElement|null} */ (selectors === null ? null : freshPress()?.closest(selectors.from) ?? null)
	pressed = null
	if (from === null) {
		return null
	}

	// the page being left, which may still be in the document while the new
	// one is drawn: nothing on it is where the navigation lands
	const leaving = from.closest('.social__pages > *') ?? from
	const name = uniqueTransitionName()
	/** @type {HTMLElement|null} */
	let target = null
	nameMorph(from, name)

	return {
		land() {
			// one name, one element: two of a name end the transition
			nameMorph(from, '')
			target = /** @type {HTMLElement[]} */ (Array.from(document.querySelectorAll(selectors.to)))
				.find((element) => !leaving.contains(element)) ?? null
			nameMorph(target, name)
		},

		release() {
			nameMorph(from, '')
			nameMorph(target, '')
		},
	}
}
