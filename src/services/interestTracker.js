/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { fetchRecommendationPreferences, isRecommendationLearningActive, sendSignals } from './interests.js'
import logger from './logger.js'
import { uuid } from '../utils/uuid.js'

/**
 * How reading is measured for the For you feed.
 *
 * One module, so the home feed, a hashtag page and a thread all count a post
 * the same way. It reports qualified exposures, quick skips, and the few
 * things a reader does to a post that the server does not see happen — opening
 * a picture or following a link. Passive reading time stays in the browser for
 * exposure and skip qualification; it is not sent as an interest score.
 *
 * A factory with everything it touches passed in, so the rules can be tested
 * with a fake clock and a fake observer rather than a browser.
 */

/** Reading has stopped when nothing moved for this long. */
export const IDLE_AFTER = 20000

/** One view of one post counts for at most this long. */
export const VIEW_CAP = 30000

/** Avoid counting observer jitter as another qualified view of the same post. */
export const IMPRESSION_COOLDOWN = 5000

/**
 * A post in view for less than this while the reader was scrolling was
 * probably passed over rather than opened or read.
 */
export const SKIP_BELOW = 600

/** How recent a scroll has to be for the reader to count as scrolling. */
export const SCROLLING_WITHIN = 1000

/** How often the buffer is sent while the page stays open. */
export const FLUSH_EVERY = 30000

/** The most the server takes in one request. */
export const BATCH_SIZE = 100

/** Keep reading events bounded while a server is unavailable. */
const MAX_BUFFER_EVENTS = 1000

/** A transient network failure is retried on later flushes, then discarded. */
const MAX_SEND_ATTEMPTS = 5

/** Actions accepted by the recommendation signal endpoint. */
const ACTION_KINDS = new Set(['open', 'media', 'link'])

/** Shorter than this is a post going past, not one being looked at. */
const MIN_DWELL = 250

/**
 * Which context a timeline reports under, by the `type` the list is given.
 * Anything not here is not tracked: notifications, bookmarks, a profile.
 */
const CONTEXTS = {
	home: 'home',
	timeline: 'local',
	federated: 'federated',
	tags: 'tag',
	explore: 'explore',
	interests: 'interests',
	photos: 'photos',
	videos: 'videos',
	shorts: 'shorts',
	reels: 'reels',
	'single-post': 'detail',
}

/**
 * @param {string} type the timeline type, as TimelineList is given it
 * @return {string|null} what the signals say they came from, null for none
 */
export function contextFor(type) {
	return CONTEXTS[type] ?? null
}

/**
 * Whether reading this post may teach anything: it is not the reader's own,
 * and either carries a hashtag or comes from a consented ranked-feed context.
 *
 * @param {import('../types/Mastodon.js').Status|null|undefined} status the post the reader sees
 * @param {(status: object) => boolean} isOwn whether the reader wrote it
 * @param {boolean} allowUntagged whether an enabled ranked feed may track posts without hashtags
 * @return {boolean}
 */
export function isTrackable(status, isOwn = () => false, allowUntagged = false) {
	if (!status?.id || (!allowUntagged && (!Array.isArray(status.tags) || status.tags.length === 0))) {
		return false
	}

	return !isOwn(status)
}

/** @param {object|null|undefined} status the post */
function hasTags(status) {
	return Array.isArray(status?.tags) && status.tags.length > 0
}

/**
 * Whether a post's body is on screen at all.
 *
 * A post behind a content warning shows the warning until it is lifted — the
 * body is the `.post-message--behind-warning` inside it — and one hidden by a
 * filter shows the reason and nothing else. Looking at either is not reading
 * the post.
 *
 * @param {Element} element the entry
 * @return {boolean}
 */
export function isReadable(element) {
	if (element.querySelector('.post-filtered') !== null) {
		return false
	}
	if (element.querySelector('.post-warning') !== null && element.querySelector('.post-message--behind-warning') === null) {
		return false
	}

	return true
}

/**
 * Whether an observer entry counts as in view: at least half of the post
 * visible, or — for a post taller than twice the screen, which can never be
 * half visible — at least half the screen taken up by it.
 *
 * @param {IntersectionObserverEntry} entry what the observer said
 * @param {number} viewportHeight the fallback when the entry has no root bounds
 * @return {boolean}
 */
function inView(entry, viewportHeight) {
	if (!entry.isIntersecting) {
		return false
	}
	if (entry.intersectionRatio >= 0.5) {
		return true
	}

	const rootHeight = entry.rootBounds?.height ?? viewportHeight

	return rootHeight > 0 && (entry.intersectionRect?.height ?? 0) >= rootHeight / 2
}

/**
 * Which side of the screen a post is on, by its middle.
 *
 * @param {IntersectionObserverEntry} entry what the observer said
 * @param {number} viewportHeight the fallback when the entry has no root bounds
 * @return {'above'|'below'}
 */
function sideOf(entry, viewportHeight) {
	const rect = entry.boundingClientRect
	const top = entry.rootBounds?.top ?? 0
	const height = entry.rootBounds?.height ?? viewportHeight

	return (rect.top + rect.height / 2) < (top + height / 2) ? 'above' : 'below'
}

/**
 * One post being watched on screen: the element, the post, and how long it has
 * been in view. The same shape the `watched` Map is declared with below.
 *
 * @typedef {object} WatchedView
 * @property {Element} element the post's element
 * @property {import('../types/Mastodon.js').Status} status the post itself
 * @property {boolean} visible whether it is on screen now
 * @property {number|null} since when the current stretch began, null between them
 * @property {number} ms how long it has been watched in total
 * @property {number} enteredAt when it last came into view
 * @property {string} enteredFrom which edge it came from
 */

/**
 * @param {object} options what the tracker works with
 * @param {string} options.context what the signals say they came from
 * @param {number|null} [options.generation] current recommendation consent/reset generation
 * @param {boolean} [options.legacyLearning] whether the reader separately enabled legacy hashtag learning
 * @param {boolean} [options.allowUntagged] whether a consented recommendation context tracks without hashtags
 * @param {(events: object[], options: {beacon: boolean, legacy: boolean}) => Promise<void>} [options.send] how a batch goes out
 * @param {(status: import('../types/Mastodon.js').Status) => boolean} [options.isOwn] whether the reader wrote a post
 * @param {() => number} [options.now] the clock
 * @param {Document} [options.document] where visibility and input are read
 * @param {Window} [options.window] where focus, the observer and timers come from
 * @return {object} the tracker
 */
export function createInterestTracker({
	context,
	generation = null,
	legacyLearning = false,
	allowUntagged = false,
	send = sendSignals,
	isOwn = () => false,
	now = () => Date.now(),
	document: doc = globalThis.document,
	window: win = globalThis.window,
}) {
	/** @type {object[]} what is waiting to be sent */
	let buffer = []
	const sendAttempts = new WeakMap()
	let flushing = false
	let flushPromise = null

	/**
	 * Every post being watched, by its element.
	 *
	 * `visible` is the observer's answer; `since` is when the current stretch
	 * of counted time began, null while it is not counting; `ms` what earlier
	 * stretches added up to. `enteredAt` and `enteredFrom` describe the view as
	 * it began, which helps distinguish a quick scrolling pass from reading.
	 *
	 * @type {Map<Element, {element: Element, status: object, visible: boolean, since: number|null, ms: number, enteredAt: number, enteredFrom: string}>}
	 */
	const watched = new Map()

	/** posts with one long-enough view or one skip in this page view */
	const longViews = new Set()
	const skipped = new Set()
	/** explicit events already recorded, as `id|kind` */
	const recorded = new Set()
	const impressionAt = new Map()
	const sessionId = uuid()
	let learningGeneration = Number.isInteger(generation) && generation >= 0 ? generation : null

	/**
	 * @param {string} statusId the post whose event is being reported
	 * @param {string} kind the observed action
	 * @param {object} [extra] bounded event-specific details
	 * @param {boolean} [legacyEligible] whether a separately enabled hashtag learner may receive it
	 */
	function signal(statusId, kind, extra = {}, legacyEligible = true) {
		if (!legacyEligible && learningGeneration === null) {
			return
		}
		if (learningGeneration === null && !legacyLearning) {
			return
		}
		if (buffer.length >= MAX_BUFFER_EVENTS) {
			logger.debug('Reading signal buffer is full; dropping a new observation')
			return
		}
		const event = {
			status_id: statusId,
			kind,
			context,
			...extra,
		}
		if (!legacyEligible) {
			event.legacy_eligible = false
		}
		if (learningGeneration !== null) {
			event.event_id = uuid()
			event.generation = learningGeneration
		}
		buffer.push(event)
	}

	let pageVisible = doc.visibilityState !== 'hidden'
	let focused = typeof doc.hasFocus === 'function' ? doc.hasFocus() : true
	let lastInput = now()
	let lastScroll = 0
	let idle = false
	let idleTimer = null
	let refreshTimer = null
	let destroyed = false

	const viewportHeight = () => win.innerHeight ?? 0

	/** @return {boolean} whether the page as a whole lets anything count */
	const pageCounts = () => pageVisible && focused && !idle && (learningGeneration !== null || legacyLearning)
	const canTrack = (status) => allowUntagged
		? Boolean(status?.id) && !isOwn(status)
		: isTrackable(status, isOwn)
	const canCount = (status) => pageCounts() && (hasTags(status) || learningGeneration !== null)

	/**
	 * Closes the stretch a post is counting, adding it to the view's total.
	 *
	 * @param {WatchedView} view one of `watched`
	 * @param {number} at when the stretch ended
	 */
	function settle(view, at) {
		if (view.since !== null) {
			view.ms += Math.max(0, at - view.since)
			view.since = null
		}
	}

	/**
	 * Starts or stops each post's stretch to match what is true now: after a
	 * change of visibility, focus or idleness, and after a click that may
	 * have lifted a content warning.
	 *
	 * @param {number} at the moment the change happened
	 */
	function refresh(at = now()) {
		for (const [element, view] of watched) {
			const counts = view.visible && canCount(view.status) && isReadable(element)
			if (counts && view.since === null) {
				view.since = at
			} else if (!counts) {
				settle(view, at)
			}
		}
	}

	/**
	 * Ends one view of one post: a qualified exposure, a quick skip, or neither.
	 *
	 * @param {WatchedView} view one of `watched`
	 * @param {number} at when the view ended
	 * @param {string|null} leftTo where it went: 'above', 'below', or null when
	 *                             it did not leave the screen (the page went away)
	 */
	function endView(view, at, leftTo) {
		settle(view, at)
		const id = view.status.id
		const ms = Math.min(view.ms, VIEW_CAP)
		const shown = at - view.enteredAt
		const scrolling = at - lastScroll <= SCROLLING_WITHIN
		const lastImpressionAt = impressionAt.get(id)
		if (learningGeneration !== null && ms >= 750
			&& (lastImpressionAt === undefined || at - lastImpressionAt >= IMPRESSION_COOLDOWN)) {
			impressionAt.set(id, at)
			signal(id, 'impression', { session_id: sessionId, visible_ms: Math.min(ms, VIEW_CAP) }, hasTags(view.status))
		}

		if (leftTo !== null && leftTo !== view.enteredFrom && shown < SKIP_BELOW && scrolling) {
			if (!skipped.has(id) && !longViews.has(id)) {
				skipped.add(id)
				signal(id, 'skip', {}, hasTags(view.status))
			}
		} else if (ms >= MIN_DWELL && !longViews.has(id)) {
			longViews.add(id)
		}

		view.ms = 0
		// a view the page ended rather than the scroll stays open: the post is
		// still on screen when the tab comes back, and the observer has
		// nothing new to say about it
		if (leftTo !== null) {
			view.visible = false
		} else if (view.visible && canCount(view.status) && isReadable(view.element)) {
			view.since = at
		}
	}

	/** @param {IntersectionObserverEntry[]} entries what the observer saw change */
	function onIntersect(entries) {
		const at = now()
		for (const entry of entries) {
			const view = watched.get(entry.target)
			if (view === undefined) {
				continue
			}

			const visible = inView(entry, viewportHeight())
			if (visible && !view.visible) {
				view.visible = true
				view.enteredAt = at
				view.enteredFrom = sideOf(entry, viewportHeight())
				view.ms = 0
			} else if (!visible && view.visible) {
				endView(view, at, sideOf(entry, viewportHeight()))
			}
		}
		refresh(at)
	}

	const Observer = /** @type {{IntersectionObserver?: typeof IntersectionObserver}} */ (/** @type {unknown} */ (win)).IntersectionObserver
	const observer = typeof Observer === 'function'
		? new Observer(onIntersect, { threshold: [0, 0.25, 0.5, 0.75, 1] })
		: null

	/** Waits out the idle interval from the last input, then stops counting. */
	function armIdle() {
		clearTimeout(idleTimer)
		const wait = Math.max(0, lastInput + IDLE_AFTER - now())
		idleTimer = setTimeout(() => {
			if (now() - lastInput >= IDLE_AFTER) {
				idle = true
				refresh(lastInput + IDLE_AFTER)
			} else {
				armIdle()
			}
		}, wait)
	}

	/** Any sign of a reader: counting resumes if it had stopped. */
	function onInput() {
		lastInput = now()
		if (idle) {
			idle = false
			refresh(lastInput)
			armIdle()
		}
	}

	/** A scroll is input, and the only kind that makes a skip. */
	function onScroll() {
		lastScroll = now()
		onInput()
	}

	/**
	 * A click or a key may lift a content warning, which only shows in the
	 * DOM once the component has re-rendered: looked at on the next task.
	 */
	function onActivate() {
		onInput()
		clearTimeout(refreshTimer)
		refreshTimer = setTimeout(() => refresh(), 0)
	}

	/** The tab was hidden or shown again. */
	function onVisibility() {
		const at = now()
		pageVisible = doc.visibilityState !== 'hidden'
		refresh(at)
		if (!pageVisible) {
			// the tab may never come back: what was read is said now
			finishVisible(at)
			flush()
		}
	}

	/** The window came to the front. */
	function onFocus() {
		focused = true
		onInput()
		refresh()
	}

	/** Another window, or another app, took the focus. */
	function onBlur() {
		focused = false
		refresh()
	}

	/** The page is going away: what is left goes by beacon. */
	function onPageHide() {
		pageVisible = false
		finishVisible(now())
		flush({ beacon: true })
	}

	/** The page returned, including from the back-forward cache. */
	function onPageShow() {
		pageVisible = doc.visibilityState !== 'hidden'
		focused = typeof doc.hasFocus === 'function' ? doc.hasFocus() : true
		onInput()
		refresh()
		armIdle()
	}

	/**
	 * Says what everything on screen has been read for so far, without a
	 * skip: the page is going away or out of sight, not being scrolled.
	 *
	 * @param {number} at when
	 */
	function finishVisible(at) {
		for (const view of watched.values()) {
			if (view.visible) {
				endView(view, at, null)
			}
		}
	}

	/** @return {object[][]} the current buffer split into bounded requests */
	function takeBatches() {
		const batches = []
		while (buffer.length > 0) {
			batches.push(buffer.slice(0, BATCH_SIZE))
			buffer = buffer.slice(BATCH_SIZE)
		}
		return batches
	}

	/**
	 * @param {object[][]} batches events grouped by request size
	 * @param {boolean} beacon whether requests are queued during page exit
	 * @param {string} failureMessage debug message for failed requests
	 */
	function sendBatches(batches, beacon, failureMessage) {
		const sends = batches.map((batch, index) => {
			try {
				return Promise.resolve(send(batch, { beacon, legacy: legacyLearning })).then(
					() => ({ index, batch }),
					(error) => ({ index, batch, error }),
				)
			} catch (error) {
				return Promise.resolve({ index, batch, error })
			}
		})

		return Promise.all(sends).then((results) => {
			const retry = []
			for (const result of results.filter((item) => 'error' in item).sort((a, b) => a.index - b.index)) {
				logger.debug(failureMessage, { error: result.error })
				for (const event of result.batch) {
					const attempts = (sendAttempts.get(event) ?? 0) + 1
					if (attempts < MAX_SEND_ATTEMPTS) {
						sendAttempts.set(event, attempts)
						retry.push(event)
					} else {
						logger.debug('Discarding a reading signal after repeated send failures')
					}
				}
			}
			buffer = retry.concat(buffer).slice(0, MAX_BUFFER_EVENTS)
		})
	}

	/**
	 * Sends what is buffered, a request per hundred events. A failed batch is
	 * put back unchanged so its event IDs remain stable on retry. Both the
	 * buffer and retry count are bounded while the server is unavailable.
	 *
	 * @param {object} [options] how
	 * @param {boolean} [options.beacon] the page is going away
	 */
	function flush({ beacon = false } = {}) {
		if (flushing && beacon && buffer.length > 0) {
			return sendBatches(takeBatches(), true, 'Could not report reading signals during page exit')
		}
		if (flushing || buffer.length === 0) {
			return flushPromise ?? Promise.resolve()
		}

		flushing = true
		const batches = takeBatches()

		// Start each bounded batch immediately: a pagehide beacon must be
		// queued before its handler returns.
		flushPromise = sendBatches(batches, beacon, 'Could not report reading signals').finally(() => {
			flushing = false
			flushPromise = null
			if (destroyed && buffer.length === 0) {
				clearInterval(flushTimer)
			}
		})

		return flushPromise
	}

	const flushTimer = setInterval(() => flush(), FLUSH_EVERY)
	const passive = { capture: true, passive: true }
	doc.addEventListener('scroll', onScroll, passive)
	doc.addEventListener('pointerdown', onInput, passive)
	doc.addEventListener('pointermove', onInput, passive)
	doc.addEventListener('wheel', onScroll, passive)
	doc.addEventListener('keydown', onActivate, passive)
	doc.addEventListener('click', onActivate, passive)
	doc.addEventListener('visibilitychange', onVisibility)
	win.addEventListener('focus', onFocus)
	win.addEventListener('blur', onBlur)
	win.addEventListener('pagehide', onPageHide)
	win.addEventListener('pageshow', onPageShow)
	armIdle()

	// named rather than returned straight, so that `sync()` can call the two
	// beside it: `this` inside an object literal is still being built, and
	// TypeScript reads it as `{}`
	const tracker = {
		setLearningGeneration(value) {
			const nextGeneration = Number.isInteger(value) && value >= 0 ? value : null
			if (learningGeneration !== nextGeneration) {
				buffer = buffer.flatMap((event) => {
					if (!Number.isInteger(event.generation) || (nextGeneration !== null && event.generation === nextGeneration)) {
						return [event]
					}
					if (!legacyLearning || event.legacy_eligible === false) {
						return []
					}
					const legacyEvent = { ...event }
					delete legacyEvent.event_id
					delete legacyEvent.generation
					delete legacyEvent.legacy_eligible
					return [legacyEvent]
				})
				learningGeneration = nextGeneration
				const at = now()
				for (const view of watched.values()) {
					view.ms = 0
					view.since = null
					view.enteredAt = at
				}
				longViews.clear()
				skipped.clear()
				recorded.clear()
				impressionAt.clear()
				refresh(at)
			}
		},

		/**
		 * Starts watching a post. Own posts and posts without a hashtag outside
		 * a consented ranked-feed context are never watched.
		 *
		 * @param {Element} element the entry on the page
		 * @param {import('../types/Mastodon.js').Status} status the post the reader sees in it
		 */
		observe(element, status) {
			if (destroyed || watched.has(element) || !canTrack(status)) {
				return
			}
			watched.set(element, { element, status, visible: false, since: null, ms: 0, enteredAt: 0, enteredFrom: 'below' })
			observer?.observe(element)
		},

		/**
		 * Stops watching a post, ending its view as if it had gone off screen.
		 *
		 * @param {Element} element the entry
		 */
		unobserve(element) {
			const view = watched.get(element)
			if (view === undefined) {
				return
			}
			if (view.visible) {
				endView(view, now(), null)
			}
			watched.delete(element)
			observer?.unobserve(element)
		},

		/**
		 * Makes the watched set exactly these entries: new ones are watched,
		 * ones that left the page are let go.
		 *
		 * @param {Array<[Element, object]>} pairs each entry with its post
		 */
		sync(pairs) {
			const present = new Set(pairs.map(([element]) => element))
			for (const element of [...watched.keys()]) {
				if (!present.has(element)) {
					tracker.unobserve(element)
				}
			}
			for (const [element, status] of pairs) {
				tracker.observe(element, status)
			}
		},

		/**
		 * Something the reader did to a post that the endpoint accepts.
		 * Once per post and kind per page view.
		 *
		 * @param {import('../types/Mastodon.js').Status} status the post
		 * @param {'open'|'media'|'link'} kind what happened
		 */
		record(status, kind) {
			if (destroyed || !ACTION_KINDS.has(kind) || !canTrack(status)) {
				return
			}
			const key = status.id + '|' + kind
			if (recorded.has(key)) {
				return
			}
			recorded.add(key)
			signal(status.id, kind, {}, hasTags(status))
		},

		/**
		 * Records a bounded fraction of a video's duration actually played.
		 *
		 * @param {import('../types/Mastodon.js').Status} status the video post
		 * @param {number} ratio the fraction watched
		 */
		recordVideoWatchRatio(status, ratio) {
			if (destroyed || learningGeneration === null || !canTrack(status)
				|| !Number.isFinite(ratio) || ratio <= 0) {
				return
			}
			const key = status.id + '|video_watch_ratio'
			if (recorded.has(key)) {
				return
			}
			recorded.add(key)
			signal(status.id, 'video_watch_ratio', { ratio: Math.min(1, Math.round(ratio * 1000) / 1000) }, hasTags(status))
		},

		flush,

		/** @return {object[]} what is waiting to be sent, for the tests */
		pending() {
			return [...buffer]
		},

		/**
		 * Stops everything and sends what is left: the list is going away, or
		 * is about to show another timeline.
		 */
		destroy() {
			if (destroyed) {
				return
			}
			destroyed = true
			finishVisible(now())
			flush()
			if (buffer.length === 0 && !flushing) {
				clearInterval(flushTimer)
			}
			clearTimeout(idleTimer)
			clearTimeout(refreshTimer)
			observer?.disconnect()
			watched.clear()
			doc.removeEventListener('scroll', onScroll, passive)
			doc.removeEventListener('pointerdown', onInput, passive)
			doc.removeEventListener('pointermove', onInput, passive)
			doc.removeEventListener('wheel', onScroll, passive)
			doc.removeEventListener('keydown', onActivate, passive)
			doc.removeEventListener('click', onActivate, passive)
			doc.removeEventListener('visibilitychange', onVisibility)
			win.removeEventListener('focus', onFocus)
			win.removeEventListener('blur', onBlur)
			win.removeEventListener('pagehide', onPageHide)
			win.removeEventListener('pageshow', onPageShow)
		},
	}

	return tracker
}

/**
 * Reports one action outside any list. The same rules as the tracker apply:
 * nothing for the reader's own posts or for untagged posts outside a consented
 * ranked-feed context.
 *
 * @param {import('../types/Mastodon.js').Status} status the post
 * @param {'open'|'media'|'link'|'skip'|'video_watch_ratio'} kind what happened
 * @param {string} context where
 * @param {(status: object) => boolean} [isOwn] whether the reader wrote it
 * @param {{ratio?: number}} [extra] the watched video fraction
 * @param {(events: object[], options?: {legacy: boolean}) => Promise<void>} [send] how it goes out
 * @param {number|null} [generation] current consent/reset generation, when already known
 * @param {boolean} [allowUntagged] whether the ranked feed may learn from posts without hashtags
 * @param {boolean} [legacyLearning] whether legacy hashtag learning is separately enabled
 * @return {Promise<void>}
 */
export async function signalNow(status, kind, context, isOwn = () => false, extra = {}, send = sendSignals, generation = null, allowUntagged = false, legacyLearning = false) {
	if (!['open', 'media', 'link', 'skip', 'video_watch_ratio'].includes(kind)
		|| !isTrackable(status, isOwn, allowUntagged)) {
		return
	}

	try {
		const untaggedRankedPost = allowUntagged && !hasTags(status)
		if ((!Number.isInteger(generation) || generation < 0) && (!legacyLearning || untaggedRankedPost)) {
			const preferences = await fetchRecommendationPreferences()
			if (!isRecommendationLearningActive(preferences) || !Number.isInteger(preferences.generation) || preferences.generation < 0) {
				return
			}
			generation = preferences.generation
		}
		const event = /** @type {Record<string, unknown>} */ ({ status_id: status.id, kind, context, ...extra })
		if (Number.isInteger(generation) && generation >= 0) {
			event.event_id = uuid()
			event.generation = generation
		}
		if (legacyLearning && !untaggedRankedPost) {
			await send([event], { legacy: true })
		} else {
			await send([event], { legacy: false })
		}
	} catch (error) {
		logger.debug('Could not report a reading signal', { error })
	}
}
