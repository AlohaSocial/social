/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { signalNow, VIEW_CAP } from './interestTracker.js'

/**
 * What watching the Shorts stack teaches For you.
 *
 * The stack shows one video at a time and the reader moves on with a swipe,
 * so the measure is the player's, not the screen's: how far into the video
 * they got, whether they watched it through, and whether they swiped it away
 * at once. Each slide the reader leaves says one of two things, under the
 * context `shorts`:
 *
 *  - a **skip**, when it was left within `SKIP_WITHIN` of arriving and had not
 *    played through;
 *  - otherwise a **dwell** whose `ms` is how much of the video was watched —
 *    all of it when it ended or looped. The server compares that with how
 *    long the video runs, not with how long its text takes to read.
 *
 * Kept apart from the view, which only tells it where the reader is and what
 * the player says: the stack's player can change without the rules changing.
 */

/** Left sooner than this, and the reader swiped the video away. */
export const SKIP_WITHIN = 2000

/** Played this close to the end, and a jump back to the start is a loop. */
const LOOP_TAIL = 0.9

/** Watched less than this and nothing was, to say either way. */
const MIN_WATCH = 250

/**
 * @param {object} [options] what it works with
 * @param {string} [options.context] what the signals say they came from
 * @param {(status: object) => boolean} [options.isOwn] whether the reader wrote a post
 * @param {() => number} [options.now] the clock
 * @param {(events: object[]) => Promise<void>} [options.send] how an event goes out
 * @param {() => boolean} [options.enabled] whether anything may be learned right now
 * @return {object} the signals of one stack
 */
export function createShortSignals({
	context = 'shorts',
	isOwn = () => false,
	now = () => Date.now(),
	send = undefined,
	enabled = () => true,
} = {}) {
	/** @type {{status: object, enteredAt: number, watched: number, position: number, duration: number, completed: boolean}|null} */
	let current = null
	/** posts whose one signal this page view has already sent */
	const said = new Set()

	/**
	 * @param {string} kind 'skip' or 'dwell'
	 * @param {object} status the post
	 * @param {object} [extra] the dwell's `ms`
	 */
	function report(kind, status, extra = {}) {
		if (!enabled() || said.has(status.id)) {
			return
		}
		said.add(status.id)
		signalNow(status, kind, context, isOwn, extra, send)
	}

	const signals = {
		/**
		 * The reader arrived at a slide: whatever they were on is left.
		 *
		 * @param {object|null|undefined} status the post of the slide now on screen
		 */
		enter(status) {
			if (current !== null && current.status.id === status?.id) {
				return
			}
			signals.leave()
			if (status?.id) {
				current = { status, enteredAt: now(), watched: 0, position: 0, duration: 0, completed: false }
			}
		},

		/**
		 * What the player says as it plays (`timeupdate`). A position far
		 * behind the last one, after that one was near the end, is the video
		 * starting over — `loop` plays on without an `ended`.
		 *
		 * @param {object} status the post the player is showing
		 * @param {{currentTime?: number, duration?: number}} video the player
		 */
		progress(status, video) {
			if (current === null || current.status.id !== status?.id) {
				return
			}
			const position = Number(video?.currentTime) || 0
			const duration = Number(video?.duration)
			if (Number.isFinite(duration) && duration > 0) {
				current.duration = duration * 1000
			}
			const at = position * 1000
			if (current.duration > 0 && at < current.position && current.position >= current.duration * LOOP_TAIL) {
				current.completed = true
			}
			current.position = at
			current.watched = Math.max(current.watched, at)
		},

		/**
		 * The player reached the end (`ended`).
		 *
		 * @param {object} status the post the player is showing
		 */
		ended(status) {
			if (current !== null && current.status.id === status?.id) {
				current.completed = true
			}
		},

		/** The reader left the slide they were on: the stack scrolled, or went away. */
		leave() {
			if (current === null) {
				return
			}
			const view = current
			current = null

			if (!view.completed && now() - view.enteredAt < SKIP_WITHIN) {
				report('skip', view.status)
				return
			}

			const ms = view.completed && view.duration > 0 ? view.duration : view.watched
			if (ms >= MIN_WATCH) {
				report('dwell', view.status, { ms: Math.round(Math.min(ms, VIEW_CAP)) })
			}
		},
	}

	return signals
}
