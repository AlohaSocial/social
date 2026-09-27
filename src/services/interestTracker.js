/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { sendSignals } from './interests.js'
import logger from './logger.js'

/**
 * How reading is measured for My interests.
 *
 * One module, so the home feed, a hashtag page and a thread all count a post
 * the same way. It reports time spent on a post, posts scrolled past, and the
 * few things a reader does to a post that the server does not see happen —
 * opening a picture, following a link. It never reports hashtags: the server
 * reads those from the post it stored, so a client cannot teach it tags the
 * post does not carry.
 *
 * A factory with everything it touches passed in, so the rules can be tested
 * with a fake clock and a fake observer rather than a browser.
 */

/** Reading has stopped when nothing moved for this long. */
export const IDLE_AFTER = 20000

/** One view of one post counts for at most this long. */
export const VIEW_CAP = 30000

/**
 * A post in view for less than this while the reader was scrolling was
 * passed over rather than read. The server knows the expected dwell for the
 * post and applies the precise ratio; this only decides what is a candidate.
 */
export const SKIP_BELOW = 600

/** How recent a scroll has to be for the reader to count as scrolling. */
export const SCROLLING_WITHIN = 1000

/** How often the buffer is sent while the page stays open. */
export const FLUSH_EVERY = 30000

/** The most the server takes in one request. */
export const BATCH_SIZE = 100

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
	interests: 'interests',
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
 * Whether reading this post may teach anything: it carries a hashtag, and the
 * reader did not write it.
 *
 * @param {import('../types/Mastodon.js').Status|null|undefined} status the post the reader sees
 * @param {(status: object) => boolean} isOwn whether the reader wrote it
 * @return {boolean}
 */
export function isTrackable(status, isOwn = () => false) {
	if (!status?.id || !Array.isArray(status.tags) || status.tags.length === 0) {
		return false
	}

	return !isOwn(status)
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
 * @param {(events: object[], options: {beacon: boolean}) => Promise<void>} [options.send] how a batch goes out
 * @param {(status: import('../types/Mastodon.js').Status) => boolean} [options.isOwn] whether the reader wrote a post
 * @param {() => number} [options.now] the clock
 * @param {Document} [options.document] where visibility and input are read
 * @param {Window} [options.window] where focus, the observer and timers come from
 * @return {object} the tracker
 */
export function createInterestTracker({
	context,
	send = sendSignals,
	isOwn = () => false,
	now = () => Date.now(),
	document: doc = globalThis.document,
	window: win = globalThis.window,
}) {
	/** @type {object[]} what is waiting to be sent */
	let buffer = []

	/**
	 * Every post being watched, by its element.
	 *
	 * `visible` is the observer's answer; `since` is when the current stretch
	 * of counted time began, null while it is not counting; `ms` what earlier
	 * stretches added up to. `enteredAt` and `enteredFrom` describe the view as
	 * it began, which is what tells a skip from a read.
	 *
	 * @type {Map<Element, {element: Element, status: object, visible: boolean, since: number|null, ms: number, enteredAt: number, enteredFrom: string}>}
	 */
	const watched = new Map()

	/** posts whose one dwell, or one skip, this page view has already sent */
	const dwelled = new Set()
	const skipped = new Set()
	/** explicit events already recorded, as `id|kind` */
	const recorded = new Set()

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
	const pageCounts = () => pageVisible && focused && !idle

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
			const counts = view.visible && pageCounts() && isReadable(element)
			if (counts && view.since === null) {
				view.since = at
			} else if (!counts) {
				settle(view, at)
			}
		}
	}

	/**
	 * Ends one view of one post: a dwell if it was read, a skip if it went past.
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

		if (leftTo !== null && leftTo !== view.enteredFrom && shown < SKIP_BELOW && scrolling) {
			if (!skipped.has(id) && !dwelled.has(id)) {
				skipped.add(id)
				buffer.push({ status_id: id, kind: 'skip', context })
			}
		} else if (ms >= MIN_DWELL && !dwelled.has(id)) {
			dwelled.add(id)
			buffer.push({ status_id: id, kind: 'dwell', ms: Math.round(ms), context })
		}

		view.ms = 0
		// a view the page ended rather than the scroll stays open: the post is
		// still on screen when the tab comes back, and the observer has
		// nothing new to say about it
		if (leftTo !== null) {
			view.visible = false
		} else if (view.visible && pageCounts() && isReadable(view.element)) {
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
		finishVisible(now())
		flush({ beacon: true })
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

	/**
	 * Sends what is buffered, a request per hundred events.
	 *
	 * Best effort: a failed batch is dropped rather than retried. It is a
	 * signal about reading, and one lost batch is noise the scores absorb;
	 * holding it would only grow a buffer on a server that is down.
	 *
	 * @param {object} [options] how
	 * @param {boolean} [options.beacon] the page is going away
	 */
	function flush({ beacon = false } = {}) {
		while (buffer.length > 0) {
			const batch = buffer.slice(0, BATCH_SIZE)
			buffer = buffer.slice(BATCH_SIZE)
			// called at once rather than in a promise chain: on `pagehide` the
			// beacon has to be queued before the handler returns
			try {
				Promise.resolve(send(batch, { beacon }))
					.catch((error) => logger.debug('Could not report reading signals', { error }))
			} catch (error) {
				logger.debug('Could not report reading signals', { error })
			}
		}
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
	armIdle()

	// named rather than returned straight, so that `sync()` can call the two
	// beside it: `this` inside an object literal is still being built, and
	// TypeScript reads it as `{}`
	const tracker = {
		/**
		 * Starts watching a post. Posts that cannot teach anything — the
		 * reader's own, or ones without a hashtag — are never watched.
		 *
		 * @param {Element} element the entry on the page
		 * @param {import('../types/Mastodon.js').Status} status the post the reader sees in it
		 */
		observe(element, status) {
			if (destroyed || watched.has(element) || !isTrackable(status, isOwn)) {
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
		 * Something the reader did to a post: opened it, a picture or a video
		 * in it, a link from it, or muted its author. Once per post and kind
		 * per page view.
		 *
		 * @param {import('../types/Mastodon.js').Status} status the post
		 * @param {'open'|'media'|'link'|'mute'} kind what happened
		 */
		record(status, kind) {
			if (destroyed || !isTrackable(status, isOwn)) {
				return
			}
			const key = status.id + '|' + kind
			if (recorded.has(key)) {
				return
			}
			recorded.add(key)
			buffer.push({ status_id: status.id, kind, context })
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
			finishVisible(now())
			flush()
			destroyed = true
			clearInterval(flushTimer)
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
		},
	}

	return tracker
}

/**
 * Reports one thing at once, outside any list: opening a post, muting its
 * author from it. The same rules as the tracker — nothing for the reader's
 * own posts or for posts without a hashtag.
 *
 * @param {import('../types/Mastodon.js').Status} status the post
 * @param {string} kind what happened
 * @param {string} context where
 * @param {(status: object) => boolean} [isOwn] whether the reader wrote it
 * @return {Promise<void>}
 */
export async function signalNow(status, kind, context, isOwn = () => false) {
	if (!isTrackable(status, isOwn)) {
		return
	}

	try {
		await sendSignals([{ status_id: status.id, kind, context }])
	} catch (error) {
		logger.debug('Could not report a reading signal', { error })
	}
}
