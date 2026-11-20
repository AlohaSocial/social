/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What waits for the timeline. Secondary requests (trending hashtags, lists,
 * the unread badge) start once the first timeline request has settled, so on
 * a small server they do not slow the one the reader is waiting for.
 */

/** the timeline request seen first on this page, if one has been */
let first = null
/** whether it has finished, either way */
let settled = false
/** what is waiting for it */
const waiting = []

/**
 * Runs `callback` in an idle slot, or soon regardless: `requestIdleCallback`
 * is missing from Safari, and the timeout covers a main thread that is never
 * idle.
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
 * on a page that sends none, once the browser is idle. The check waits for
 * the idle slot because the sidebar mounts before the timeline has asked.
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
