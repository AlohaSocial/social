/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	BATCH_SIZE,
	FLUSH_EVERY,
	IDLE_AFTER,
	VIEW_CAP,
	contextFor,
	createInterestTracker,
	isReadable,
	signalNow,
} from '../../../src/services/interestTracker.js'
import { sendSignals } from '../../../src/services/interests.js'

vi.mock('../../../src/services/interests.js', () => ({ sendSignals: vi.fn(() => Promise.resolve()) }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/** The observer the tracker makes, driven by hand. */
class FakeIntersectionObserver {
	static last = null

	constructor(callback, options) {
		this.callback = callback
		this.options = options
		this.observe = vi.fn()
		this.unobserve = vi.fn()
		this.disconnect = vi.fn()
		FakeIntersectionObserver.last = this
	}
}

const ROOT = { top: 0, height: 800 }

/**
 * A post coming into view.
 *
 * @param {Element} target the entry
 * @param {object} [options] how
 * @param {string} [options.from] which side it comes in from
 * @param {number} [options.ratio] how much of it is visible
 */
function show(target, { from = 'below', ratio = 1 } = {}) {
	FakeIntersectionObserver.last.callback([{
		target,
		isIntersecting: true,
		intersectionRatio: ratio,
		intersectionRect: { height: 100 * ratio },
		boundingClientRect: { top: from === 'below' ? 500 : 100, height: 100 },
		rootBounds: ROOT,
	}])
}

/**
 * A post leaving the view.
 *
 * @param {Element} target the entry
 * @param {string} [to] which side it leaves by
 */
function hide(target, to = 'above') {
	FakeIntersectionObserver.last.callback([{
		target,
		isIntersecting: false,
		intersectionRatio: 0,
		intersectionRect: { height: 0 },
		boundingClientRect: { top: to === 'above' ? -200 : 900, height: 100 },
		rootBounds: ROOT,
	}])
}

function post(id, extra = {}) {
	return { id, account: { acct: 'bob@remote.example' }, tags: [{ name: 'photography' }], ...extra }
}

function entry(html = '<div class="post-message">text</div>') {
	const element = document.createElement('li')
	element.innerHTML = html

	return element
}

let doc
let win
let send
let tracker

function track(options = {}) {
	tracker = createInterestTracker({ context: 'home', send, document: doc, window: win, ...options })

	return tracker
}

/** @param {object} target where to dispatch @param {string} type what */
const fire = (target, type) => target.dispatchEvent(new Event(type))

describe('the interest tracker', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		doc = new EventTarget()
		doc.visibilityState = 'visible'
		doc.hasFocus = () => true
		win = new EventTarget()
		win.innerHeight = 800
		win.IntersectionObserver = FakeIntersectionObserver
		send = vi.fn(() => Promise.resolve())
	})

	afterEach(() => {
		tracker?.destroy()
		tracker = null
		vi.useRealTimers()
	})

	it('maps the timelines it tracks to their contexts, and no others', () => {
		expect(contextFor('home')).toBe('home')
		expect(contextFor('timeline')).toBe('local')
		expect(contextFor('federated')).toBe('federated')
		expect(contextFor('tags')).toBe('tag')
		expect(contextFor('interests')).toBe('interests')
		expect(contextFor('photos')).toBe('photos')
		expect(contextFor('videos')).toBe('videos')
		expect(contextFor('single-post')).toBe('detail')
		expect(contextFor('notifications')).toBeNull()
		expect(contextFor('bookmarks')).toBeNull()
	})

	it('reports how long a post was in view, once it leaves', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(5000)
		hide(element)

		expect(tracker.pending()).toEqual([{ status_id: '1', kind: 'dwell', ms: 5000, context: 'home' }])
	})

	it('does not count a post that is less than half visible', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element, { ratio: 0.3 })
		vi.advanceTimersByTime(5000)
		hide(element)

		expect(tracker.pending()).toEqual([])
	})

	it('does not count while the tab is hidden', () => {
		doc.visibilityState = 'hidden'
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(5000)
		hide(element)

		expect(tracker.pending()).toEqual([])
	})

	it('says what was read when the tab is hidden, and sends it', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(3000)
		doc.visibilityState = 'hidden'
		fire(doc, 'visibilitychange')

		expect(send).toHaveBeenCalledWith([{ status_id: '1', kind: 'dwell', ms: 3000, context: 'home' }], { beacon: false })
	})

	it('pauses while the window has no focus', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(1000)
		fire(win, 'blur')
		vi.advanceTimersByTime(5000)
		fire(win, 'focus')
		vi.advanceTimersByTime(1000)
		hide(element)

		expect(tracker.pending()[0].ms).toBe(2000)
	})

	it('stops counting after twenty seconds without input', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(IDLE_AFTER + 5000)
		hide(element)

		expect(tracker.pending()[0].ms).toBe(IDLE_AFTER)
	})

	it('resumes on input', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(IDLE_AFTER + 5000)
		fire(doc, 'pointerdown')
		vi.advanceTimersByTime(3000)
		hide(element)

		expect(tracker.pending()[0].ms).toBe(IDLE_AFTER + 3000)
	})

	it('caps one view at thirty seconds', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		for (let i = 0; i < 6; i++) {
			vi.advanceTimersByTime(10000)
			fire(doc, 'keydown')
		}
		hide(element)

		expect(tracker.pending()[0].ms).toBe(VIEW_CAP)
	})

	it('does not count a post collapsed behind a content warning until it is opened', () => {
		const element = entry('<div class="post-warning"><p>cw</p></div>')
		expect(isReadable(element)).toBe(false)

		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(5000)

		element.querySelector('.post-warning').insertAdjacentHTML('beforeend', '<div class="post-message post-message--behind-warning">text</div>')
		fire(doc, 'click')
		vi.advanceTimersByTime(3000)
		hide(element)

		expect(tracker.pending()[0].ms).toBe(3000)
	})

	it('does not count a post hidden by a filter', () => {
		const element = entry('<div class="post-filtered"><p>Filtered</p></div>')
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(5000)
		hide(element)

		expect(tracker.pending()).toEqual([])
	})

	it('reports a skip for a post scrolled straight through', () => {
		const element = entry()
		track().observe(element, post('1'))
		fire(doc, 'scroll')
		show(element, { from: 'below' })
		vi.advanceTimersByTime(300)
		fire(doc, 'scroll')
		hide(element, 'above')

		expect(tracker.pending()).toEqual([{ status_id: '1', kind: 'skip', context: 'home' }])
	})

	it('does not call it a skip when the post went back where it came from', () => {
		const element = entry()
		track().observe(element, post('1'))
		fire(doc, 'scroll')
		show(element, { from: 'below' })
		vi.advanceTimersByTime(300)
		fire(doc, 'scroll')
		hide(element, 'below')

		expect(tracker.pending().some((event) => event.kind === 'skip')).toBe(false)
	})

	it('does not call it a skip when nobody was scrolling', () => {
		const element = entry()
		track().observe(element, post('1'))
		vi.advanceTimersByTime(5000)
		show(element, { from: 'below' })
		vi.advanceTimersByTime(400)
		hide(element, 'above')

		expect(tracker.pending()).toEqual([{ status_id: '1', kind: 'dwell', ms: 400, context: 'home' }])
	})

	it('reports one dwell per post per page view', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(2000)
		hide(element)
		show(element)
		vi.advanceTimersByTime(2000)
		hide(element)

		expect(tracker.pending()).toHaveLength(1)
	})

	it('never watches the reader\'s own posts or posts without a hashtag', () => {
		const mine = entry()
		const untagged = entry()
		track({ isOwn: (status) => status.account.acct === 'alice' })
		tracker.observe(mine, post('1', { account: { acct: 'alice' } }))
		tracker.observe(untagged, post('2', { tags: [] }))
		tracker.record(post('1', { account: { acct: 'alice' } }), 'media')
		tracker.record(post('2', { tags: [] }), 'link')

		expect(FakeIntersectionObserver.last.observe).not.toHaveBeenCalled()
		expect(tracker.pending()).toEqual([])
	})

	it('records explicit events once per post and kind', () => {
		track()
		tracker.record(post('1'), 'media')
		tracker.record(post('1'), 'media')
		tracker.record(post('1'), 'link')

		expect(tracker.pending()).toEqual([
			{ status_id: '1', kind: 'media', context: 'home' },
			{ status_id: '1', kind: 'link', context: 'home' },
		])
	})

	it('flushes every thirty seconds', () => {
		track().record(post('1'), 'open')
		expect(send).not.toHaveBeenCalled()

		vi.advanceTimersByTime(FLUSH_EVERY)

		expect(send).toHaveBeenCalledWith([{ status_id: '1', kind: 'open', context: 'home' }], { beacon: false })
		expect(tracker.pending()).toEqual([])
	})

	it('sends at most a hundred events per request', () => {
		track()
		for (let i = 0; i < 250; i++) {
			tracker.record(post(String(i)), 'media')
		}
		tracker.flush()

		expect(send.mock.calls.map(([events]) => events.length)).toEqual([BATCH_SIZE, BATCH_SIZE, 50])
	})

	it('sends by beacon on pagehide, what is on screen included', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(4000)
		fire(win, 'pagehide')

		expect(send).toHaveBeenCalledWith([{ status_id: '1', kind: 'dwell', ms: 4000, context: 'home' }], { beacon: true })
	})

	it('lets go of entries that left the page and finishes their view', () => {
		const element = entry()
		track().sync([[element, post('1')]])
		show(element)
		vi.advanceTimersByTime(2000)
		tracker.sync([])

		expect(FakeIntersectionObserver.last.unobserve).toHaveBeenCalledWith(element)
		expect(tracker.pending()).toEqual([{ status_id: '1', kind: 'dwell', ms: 2000, context: 'home' }])
	})

	it('sends what is left and stops listening when destroyed', () => {
		track().record(post('1'), 'media')
		tracker.destroy()

		expect(send).toHaveBeenCalledTimes(1)
		expect(FakeIntersectionObserver.last.disconnect).toHaveBeenCalled()

		tracker.record(post('2'), 'media')
		fire(win, 'pagehide')
		expect(send).toHaveBeenCalledTimes(1)
	})

	it('survives a send that fails', async () => {
		send.mockRejectedValue(new Error('down'))
		track().record(post('1'), 'media')

		expect(() => tracker.flush()).not.toThrow()
		await Promise.resolve()
	})

	describe('one signal outside a list', () => {
		it('is sent at once', async () => {
			await signalNow(post('1'), 'open', 'detail')

			expect(sendSignals).toHaveBeenCalledWith([{ status_id: '1', kind: 'open', context: 'detail' }])
		})

		it('is not sent for the reader\'s own post or one without a hashtag', async () => {
			sendSignals.mockClear()
			await signalNow(post('1'), 'open', 'detail', () => true)
			await signalNow(post('2', { tags: [] }), 'open', 'detail')

			expect(sendSignals).not.toHaveBeenCalled()
		})
	})
})
