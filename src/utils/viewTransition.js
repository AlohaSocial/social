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
 * Names an element for a morph: the name to pair it with its counterpart,
 * and the class every morph is styled through (see App.vue), because a
 * stylesheet cannot list names that are made up as the page runs.
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
 * Carries one element into another across a change of the page, so a picture
 * grows into the viewer showing it instead of the viewer appearing from
 * nowhere.
 *
 * The name is only ever on one element at a time: `from` holds it while the
 * old picture is taken, and gives it up to whatever `to` finds once the change
 * has been applied. A browser that finds two elements with one name abandons
 * the transition, so the order matters. Both are released when the animation
 * is over, or straight away where nothing is animated.
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
