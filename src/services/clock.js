/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * One clock for the whole app.
 *
 * A relative timestamp ("5 minutes ago") is only true for a minute, and
 * `fromNow()` is pure: nothing re-read it, so every post in the timeline kept
 * whatever it said when it was rendered. Every entry subscribing its own
 * `setInterval` would be one timer per post, so there is a single interval
 * here and the subscribers are called from it.
 */

/** how often the wording is re-checked; a minute is the smallest unit shown */
export const TICK_MS = 30000

const listeners = new Set()
let timer = null

/**
 *
 */
function tick() {
	const now = Date.now()
	for (const listener of [...listeners]) {
		listener(now)
	}
}

/**
 * Calls back roughly every half minute for as long as the returned function
 * is not called.
 *
 * @param {(now: number) => void} listener called with the current epoch milliseconds
 * @return {() => void} unsubscribes, and stops the interval with the last listener
 */
export function onTick(listener) {
	listeners.add(listener)
	if (timer === null) {
		timer = setInterval(tick, TICK_MS)
	}

	return () => offTick(listener)
}

/**
 * @param {(now: number) => void} listener the callback passed to onTick()
 */
export function offTick(listener) {
	listeners.delete(listener)
	if (listeners.size === 0 && timer !== null) {
		clearInterval(timer)
		timer = null
	}
}
