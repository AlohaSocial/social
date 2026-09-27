/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What waits for the timeline.
 *
 * Every page used to fire four requests at once as it mounted: the timeline,
 * the trending hashtags, the reader's lists and the unread badge. On a small
 * server the four ran each other down -- 2.3 to 3.4 seconds each in parallel
 * where the timeline alone takes 0.4 -- and the one the reader was actually
 * waiting for was slowed by three they were not. The timeline request goes
 * first now; the rest start once it has settled, or once the browser is idle
 * on a page that has no timeline to wait for.
 */

/** the timeline request seen first on this page, if one has been */
let first = null
/** whether it has finished, either way */
let settled = false
/** what is waiting for it */
const waiting = []

/**
 * Runs `callback` when the browser has a moment: after the first paint, in
 * an idle slot where the browser offers one, and soon regardless where it
 * does not. `requestIdleCallback` is missing from Safari, and a page whose
 * main thread never goes idle would otherwise never boot.
 *
 * @param {() => void} callback what to run
 */
function whenIdle(callback) {
	if (typeof window.requestIdleCallback === 'function') {
		window.requestIdleCallback(() => callback(), { timeout: 1000 })
		return
	}

	window.setTimeout(callback, 50)
}

/**
 * Called by the timeline store with every request it sends. Only the first
 * one on the page matters here: it is the one everything else yields to.
 *
 * @param {Promise} request the request, as axios returned it
 */
export function noteTimelineRequest(request) {
	if (first !== null) {
		return
	}

	first = request
	const release = () => {
		settled = true
		for (const callback of waiting.splice(0)) {
			callback()
		}
	}
	request.then(release, release)
}

/**
 * Runs `callback` once the page's first timeline request has settled, or,
 * on a page that sends none, once the browser is idle after the first paint.
 *
 * The decision is taken in that idle slot rather than at once: the sidebar
 * mounts before the timeline below it, so at mount time no request has been
 * sent yet and there is nothing to yield to. By the time the browser is idle,
 * the timeline -- if this page has one -- has asked.
 *
 * @param {() => void} callback what to run
 */
export function afterFirstTimeline(callback) {
	whenIdle(() => {
		if (first === null || settled) {
			callback()
			return
		}

		waiting.push(callback)
	})
}

/** Forgets the page's first request, for tests that boot more than once. */
export function resetBootForTests() {
	first = null
	settled = false
	waiting.splice(0)
}
