/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * View transitions, where the browser has them.
 *
 * Everything here is an enhancement: if `startViewTransition` is missing, or
 * the viewer asked for less motion, the change is applied exactly as it was
 * before — immediately, with no animation and no waiting.
 */

/** @return {boolean} whether an animated transition is welcome here */
export function transitionsWanted() {
	return typeof document !== 'undefined'
		&& typeof document.startViewTransition === 'function'
		&& !window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * Applies a change inside a view transition.
 *
 * @param {() => unknown} change mutates the DOM (or the router); may be async
 * @return {Promise<void>} resolves once the change has been applied
 */
export async function withViewTransition(change) {
	if (!transitionsWanted()) {
		await change()
		return
	}

	// `updateCallbackDone` is the change itself; the animation that follows is
	// deliberately not awaited, so callers never block on a decoration
	const transition = document.startViewTransition(() => change())
	try {
		await transition.updateCallbackDone
	} catch {
		// a transition that cannot run must not swallow the change
	}
}

/**
 * Names an element for the duration of one transition, so the browser can
 * carry it from where it was to where it lands instead of crossfading the
 * whole page.
 *
 * @param {HTMLElement|null} element the element to follow
 * @param {string} name a name unique within the document
 * @return {() => void} call to release the name again
 */
export function nameForTransition(element, name) {
	if (!element || !transitionsWanted()) {
		return () => {}
	}

	element.style.viewTransitionName = name

	return () => {
		element.style.viewTransitionName = ''
	}
}
