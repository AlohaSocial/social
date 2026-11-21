/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Moving from one page to another, done by the browser where it can be: with
 * the View Transitions API the browser animates pictures of the two pages
 * (`::view-transition-old/new`), elsewhere a Vue `<transition>` makes the same
 * move. Which one is in use is decided here.
 */

/** How long to wait for the page to draw before giving up on the picture. */
const PATIENCE_MS = 400

/** @return {boolean} whether the reader asked their system for less movement */
export function reducedMotion() {
	return window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false
}

/**
 * @return {boolean} whether the browser can animate the change itself
 */
export function canViewTransition() {
	return typeof document !== 'undefined'
		&& typeof document.startViewTransition === 'function'
		&& !reducedMotion()
}

/**
 * Runs a navigation inside a view transition. `update` lets the navigation
 * proceed and resolves once the page is drawn; the wait is bounded, since a
 * frozen old picture is worse than a cut. An element carried across
 * (`sharedElements.js`) lands once the new page is drawn and gives its name
 * back when the animation is over.
 *
 * @param {() => Promise<void>} update lets the router through and settles when the DOM has
 * @param {{land: () => void, release: () => void}|null} [shared] what is carried across, if anything
 * @return {Promise<void>} when the picture has been taken and the navigation may continue
 */
export function startPageTransition(update, shared = null) {
	if (!canViewTransition()) {
		shared?.release()
		return update()
	}

	return new Promise((proceed) => {
		let done = false
		const settle = () => {
			if (!done) {
				done = true
				proceed()
			}
		}

		const transition = document.startViewTransition(async () => {
			settle()
			await update()
			shared?.land()
		})

		if (shared) {
			Promise.resolve(transition?.finished).then(shared.release, shared.release)
		}

		// the browser holds the old picture until the callback settles; this is
		// the longest that may take before the change is simply made
		window.setTimeout(settle, PATIENCE_MS)
	})
}

/**
 * Names the direction on the root, where the CSS of both ways can read it:
 * `::view-transition-*` pseudo-elements see nothing else.
 *
 * @param {string} direction forward, back, or '' for neither
 */
export function markDirection(direction) {
	const root = document?.documentElement
	if (!root) {
		return
	}

	if (direction === '') {
		root.removeAttribute('data-page-direction')
	} else {
		root.setAttribute('data-page-direction', direction)
	}
}
