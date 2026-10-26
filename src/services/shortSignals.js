/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { signalNow } from './interestTracker.js'
import { accumulateVideoPlayback } from './videoPlayback.js'

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
 *  - otherwise a measured **video_watch_ratio**, based on the player's
 *    played ranges rather than the furthest position reached by seeking.
 *
 * When a post contains several video slides, their played and total durations
 * are combined into one duration-weighted ratio before the post is reported.
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
 * @param {(events: object[], options?: {legacy: boolean}) => Promise<void>} [options.send] how an event goes out
 * @param {() => boolean} [options.enabled] whether anything may be learned right now
 * @param {number|null} [options.generation] consent/reset generation
 * @param {boolean} [options.allowUntagged] whether the ranked feed may learn from posts without hashtags
 * @param {() => boolean} [options.legacyLearning] whether legacy hashtag learning is separately enabled
 * @return {object} the signals of one stack
 */
export function createShortSignals({
	context = 'shorts',
	isOwn = () => false,
	now = () => Date.now(),
	send = undefined,
	enabled = () => true,
	generation = null,
	allowUntagged = false,
	legacyLearning = () => false,
} = {}) {
	/** @type {{status: object, slideId: string|number, enteredAt: number, watchedSeconds: number, lastTime: number, position: number, duration: number, completed: boolean}|null} */
	let current = null
	let learningGeneration = Number.isInteger(generation) && generation >= 0 ? generation : null
	/** posts whose one signal this page view has already sent */
	const said = new Set()
	/** @type {Map<string, {status: object, watchedSeconds: number, durationSeconds: number, skipped: boolean}>} */
	const postPlayback = new Map()

	/**
	 * @param {'skip'|'video_watch_ratio'} kind accepted server signal
	 * @param {object} status the post
	 * @param {{ratio?: number, ms?: number}} [extra] event details
	 */
	function report(kind, status, extra = {}) {
		const mayLearnLegacy = legacyLearning()
		if (!enabled() || (!Number.isInteger(learningGeneration) && !mayLearnLegacy) || said.has(String(status.id))) {
			return
		}
		said.add(String(status.id))
		if (kind === 'video_watch_ratio') {
			const ratio = extra.ratio
			const ms = extra.ms
			// The ratio is a V4-only signal. Keep the legacy channel on its
			// established dwell contract, using player time rather than screen time.
			signalNow(status, kind, context, isOwn, { ratio }, send, learningGeneration, allowUntagged, false)
			if (mayLearnLegacy && Array.isArray(status.tags) && status.tags.length > 0 && Number.isFinite(ms)) {
				signalNow(status, 'dwell', context, isOwn, { ms }, send, null, false, true)
			}
			return
		}

		signalNow(status, kind, context, isOwn, extra, send, learningGeneration, allowUntagged, mayLearnLegacy)
	}

	/** @return {string|null} post key whose aggregate was updated */
	function finishCurrent() {
		if (current === null) {
			return null
		}

		const view = current
		current = null
		const key = String(view.status.id)
		const aggregate = postPlayback.get(key) ?? {
			status: view.status,
			watchedSeconds: 0,
			durationSeconds: 0,
			skipped: false,
		}
		aggregate.status = view.status
		const watchedSeconds = view.completed
			&& view.duration > 0
			&& view.watchedSeconds / view.duration >= LOOP_TAIL
			? view.duration
			: view.watchedSeconds
		aggregate.watchedSeconds += watchedSeconds
		aggregate.durationSeconds += view.duration
		aggregate.skipped ||= !view.completed && now() - view.enteredAt < SKIP_WITHIN
		postPlayback.set(key, aggregate)

		return key
	}

	/** @param {string} key post identifier to report */
	function flushPost(key) {
		const aggregate = postPlayback.get(key)
		if (!aggregate) {
			return
		}
		postPlayback.delete(key)

		const watchedMs = Math.round(aggregate.watchedSeconds * 1000)
		if (watchedMs >= MIN_WATCH && aggregate.durationSeconds > 0) {
			const ratio = Math.min(1, aggregate.watchedSeconds / aggregate.durationSeconds)
			if (ratio > 0) {
				report('video_watch_ratio', aggregate.status, { ratio: Math.round(ratio * 1000) / 1000, ms: watchedMs })
				return
			}
		}

		if (aggregate.skipped) {
			report('skip', aggregate.status)
		}
	}

	const signals = {
		/**
		 * Set the generation returned by the consent endpoint before reporting events.
		 *
		 * @param {number|null} value consent generation, or null when unavailable
		 */
		setLearningGeneration(value) {
			learningGeneration = Number.isInteger(value) && value >= 0 ? value : null
		},

		/**
		 * The reader arrived at a slide: whatever they were on is left.
		 *
		 * @param {object|null|undefined} status the post of the slide now on screen
		 * @param {string|number} [slideId] the identity of this video within the post
		 */
		enter(status, slideId = status?.id) {
			if (current !== null && current.status.id === status?.id && current.slideId === slideId) {
				return
			}
			const previousKey = finishCurrent()
			if (previousKey !== null && previousKey !== String(status?.id ?? '')) {
				flushPost(previousKey)
			}
			if (status?.id) {
				current = { status, slideId, enteredAt: now(), watchedSeconds: 0, lastTime: 0, position: 0, duration: 0, completed: false }
			}
		},

		/**
		 * What the player says as it plays (`timeupdate`). Its played ranges
		 * measure distinct content; currentTime deltas are a fallback until
		 * played ranges are available. A position far behind the
		 * last one, after that one was near the end, is a loop without `ended`.
		 *
		 * @param {object} status the post the player is showing
		 * @param {HTMLVideoElement} video the player
		 * @param {string|number} [slideId] the identity of this video within the post
		 */
		progress(status, video, slideId = status?.id) {
			if (current === null || current.status.id !== status?.id || current.slideId !== slideId) {
				return
			}
			const position = Number(video?.currentTime) || 0
			const duration = Number(video?.duration)
			if (Number.isFinite(duration) && duration > 0) {
				current.duration = duration
			}
			if (current.duration > 0 && position < current.position && current.position >= current.duration * LOOP_TAIL) {
				current.completed = true
			}
			current.watchedSeconds = accumulateVideoPlayback(current, video)
			current.position = position
		},

		/**
		 * The player reached the end (`ended`).
		 *
		 * @param {object} status the post the player is showing
		 * @param {string|number} [slideId] the identity of this video within the post
		 */
		ended(status, slideId = status?.id) {
			if (current !== null && current.status.id === status?.id && current.slideId === slideId) {
				current.completed = true
			}
		},

		/** The reader left the slide they were on: the stack scrolled, or went away. */
		leave() {
			finishCurrent()
			for (const key of postPlayback.keys()) {
				flushPost(key)
			}
		},
	}

	return signals
}
