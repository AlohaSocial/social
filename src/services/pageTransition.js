/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Moving from one page to another, done by the browser where it can be.
 *
 * Two ways of animating the same thing. Where the View Transitions API exists,
 * the browser takes a picture of the page before and after and animates
 * between them: nothing in the app has to be laid out twice, a tall timeline
 * costs nothing to slide because it is an image by then, and the animation is
 * described entirely in CSS (`::view-transition-old/new`). Where it does not,
 * the same move is a Vue `<transition>` over the live DOM.
 *
 * Which one is in use is not a detail the rest of the app should carry, so it
 * is decided here and the caller is told whether to do its own animation.
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
 * Runs a navigation inside a view transition.
 *
 * `update` is called to let the navigation proceed and resolves once the page
 * has been drawn. The wait is bounded: a page whose data is slow would
 * otherwise hold the browser on the old picture, which is a freeze rather than
 * an animation, and a freeze is worse than a cut.
 *
 * An element carried across the change (see `sharedElements.js`) lands once
 * the new page is drawn, and gives its name back when the animation is over.
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
 * Names the direction on the document, for the CSS of both ways to read.
 *
 * On the root rather than passed down, because `::view-transition-*` are
 * pseudo-elements of the document and cannot see anything else.
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
