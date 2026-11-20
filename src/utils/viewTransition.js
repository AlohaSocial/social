/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * View transitions, where the browser has them. Without `startViewTransition`,
 * or when the viewer asked for less motion, a change is applied immediately.
 */

/** @return {boolean} whether an animated transition is welcome here */
export function transitionsWanted() {
	return typeof document !== 'undefined'
		&& typeof document.startViewTransition === 'function'
		&& !window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/** Counts the names handed out, so no two elements ever share one. */
let named = 0

/**
 * A name no other element in the document is using.
 *
 * @return {string} the name
 */
export function uniqueTransitionName() {
	named++

	return `social-morph-${named}`
}

/**
 * Names an element for a morph: the name pairs it with its counterpart, and
 * the class styles every morph (see App.vue), since a stylesheet cannot list
 * names made up at run time.
 *
 * @param {HTMLElement|null} element the element, or nothing
 * @param {string} name the name, or '' to release it
 */
export function nameMorph(element, name) {
	if (!element) {
		return
	}

	element.style.viewTransitionName = name
	element.style.viewTransitionClass = name === '' ? '' : 'social-morph'
}

/**
 * Carries one element into another across a change of the page, such as a
 * photo growing into the viewer.
 *
 * The name is on one element at a time (`from`, then what `to` finds), since
 * a browser abandons a transition that finds two elements with one name.
 *
 * @param {HTMLElement|null} from the element the change starts from
 * @param {() => unknown} change mutates the DOM; may be async
 * @param {() => HTMLElement|null} to finds the element the change ends on
 * @return {Promise<void>} resolves once the change has been applied
 */
export async function morph(from, change, to) {
	if (!from || !transitionsWanted()) {
		await change()
		return
	}

	const name = uniqueTransitionName()
	let target = null
	nameMorph(from, name)

	const release = () => {
		nameMorph(from, '')
		nameMorph(target, '')
	}

	const transition = document.startViewTransition(async () => {
		await change()
		nameMorph(from, '')
		target = to()
		nameMorph(target, name)
	})

	// a skipped transition rejects `ready`; nobody waits on it here
	Promise.resolve(transition?.ready).catch(() => {})
	Promise.resolve(transition?.finished).then(release, release)

	try {
		await transition?.updateCallbackDone
	} catch {
		// a transition that cannot run must not swallow the change
	}
}
