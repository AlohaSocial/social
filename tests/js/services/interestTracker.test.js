/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	BATCH_SIZE,
	FLUSH_EVERY,
	IMPRESSION_COOLDOWN,
	IDLE_AFTER,
	VIEW_CAP,
	contextFor,
	createInterestTracker,
	isReadable,
	signalNow,
} from '../../../src/services/interestTracker.js'
import { fetchRecommendationPreferences, sendSignals } from '../../../src/services/interests.js'

vi.mock('../../../src/services/interests.js', () => ({
	fetchRecommendationPreferences: vi.fn(() => Promise.resolve({ learning_mode: 'on', generation: 7 })),
	isRecommendationLearningActive: (preferences) => preferences?.learning_active ?? preferences?.learning_mode === 'on',
	sendSignals: vi.fn(() => Promise.resolve()),
}))
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
		expect(contextFor('shorts')).toBe('shorts')
		expect(contextFor('reels')).toBe('reels')
		expect(contextFor('single-post')).toBe('detail')
		expect(contextFor('notifications')).toBeNull()
		expect(contextFor('bookmarks')).toBeNull()
	})

	it('does not send passive reading time as an interest signal', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(5000)
		hide(element)

		expect(tracker.pending()).toEqual([])
	})

	it('adds stable event IDs and the consent generation to v4 impressions', () => {
		const element = entry()
		track({ generation: 4 })
		tracker.observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(900)
		hide(element)

		const events = tracker.pending()
		expect(events).toHaveLength(1)
		for (const event of events) {
			expect(event.event_id).toMatch(/^[0-9a-f-]{36}$/i)
			expect(event.generation).toBe(4)
		}
		expect(events[0]).toMatchObject({ kind: 'impression', session_id: expect.stringMatching(/^[0-9a-f-]{36}$/i), visible_ms: 900 })
	})

	it('counts a genuinely repeated view after a short observer-jitter cooldown', () => {
		const element = entry()
		track({ generation: 4 }).observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(900)
		hide(element)

		vi.advanceTimersByTime(IMPRESSION_COOLDOWN - 1800)
		show(element)
		vi.advanceTimersByTime(900)
		hide(element)
		expect(tracker.pending().filter((event) => event.kind === 'impression')).toHaveLength(1)

		vi.advanceTimersByTime(1)
		show(element)
		vi.advanceTimersByTime(900)
		hide(element)
		const impressions = tracker.pending().filter((event) => event.kind === 'impression')
		expect(impressions).toHaveLength(2)
		expect(impressions[0].session_id).toBe(impressions[1].session_id)
	})

	it('can watch hashtagless posts in a v4 context after consent is loaded', () => {
		const element = entry()
		track({ generation: 4, allowUntagged: true })
		tracker.observe(element, post('1', { tags: [] }))
		show(element)
		vi.advanceTimersByTime(1500)
		hide(element)

		expect(tracker.pending().some((event) => event.kind === 'impression')).toBe(true)
		expect(tracker.pending().some((event) => event.kind === 'dwell')).toBe(false)
	})

	it('records media actions on hashtagless posts in a v4 context', () => {
		track({ generation: 4, allowUntagged: true })
		tracker.record(post('1', { tags: [] }), 'media')

		expect(tracker.pending()).toHaveLength(1)
		expect(tracker.pending()[0]).toMatchObject({ kind: 'media', generation: 4 })
	})

	it('does not collect hashtagless posts through legacy learning before recommendation consent is confirmed', () => {
		const element = entry()
		track({ legacyLearning: true, allowUntagged: true })
		tracker.observe(element, post('1', { tags: [] }))
		show(element)
		vi.advanceTimersByTime(1500)
		hide(element)
		tracker.record(post('1', { tags: [] }), 'media')

		expect(tracker.pending()).toEqual([])
	})

	it('keeps hashtagless recommendation events out of the legacy queue on revocation', () => {
		track({ generation: 4, legacyLearning: true, allowUntagged: true })
		tracker.record(post('1', { tags: [] }), 'media')
		tracker.setLearningGeneration(null)

		expect(tracker.pending()).toEqual([])
	})

	it('does not turn background time into an impression', () => {
		const element = entry()
		track({ generation: 4 })
		tracker.observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(300)
		doc.visibilityState = 'hidden'
		fire(doc, 'visibilitychange')
		vi.advanceTimersByTime(5000)

		expect(tracker.pending().some((event) => event.kind === 'impression')).toBe(false)
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

	it('does not send passive reading time when the tab is hidden', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(3000)
		doc.visibilityState = 'hidden'
		fire(doc, 'visibilitychange')

		expect(send).not.toHaveBeenCalled()
	})

	it('pauses while the window has no focus', () => {
		const element = entry()
		track({ generation: 4 }).observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(1000)
		fire(win, 'blur')
		vi.advanceTimersByTime(5000)
		fire(win, 'focus')
		vi.advanceTimersByTime(1000)
		hide(element)

		expect(tracker.pending()[0]).toMatchObject({ kind: 'impression', visible_ms: 2000 })
	})

	it('stops counting after twenty seconds without input', () => {
		const element = entry()
		track({ generation: 4 }).observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(IDLE_AFTER + 5000)
		hide(element)

		expect(tracker.pending()[0]).toMatchObject({ kind: 'impression', visible_ms: IDLE_AFTER })
	})

	it('resumes on input', () => {
		const element = entry()
		track({ generation: 4 }).observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(IDLE_AFTER + 5000)
		fire(doc, 'pointerdown')
		vi.advanceTimersByTime(3000)
		hide(element)

		expect(tracker.pending()[0]).toMatchObject({ kind: 'impression', visible_ms: IDLE_AFTER + 3000 })
	})

	it('caps one view at thirty seconds', () => {
		const element = entry()
		track({ generation: 4 }).observe(element, post('1'))
		show(element)
		for (let i = 0; i < 6; i++) {
			vi.advanceTimersByTime(10000)
			fire(doc, 'keydown')
		}
		hide(element)

		expect(tracker.pending()[0]).toMatchObject({ kind: 'impression', visible_ms: VIEW_CAP })
	})

	it('does not count a post collapsed behind a content warning until it is opened', () => {
		const element = entry('<div class="post-warning"><p>cw</p></div>')
		expect(isReadable(element)).toBe(false)

		track({ generation: 4 }).observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(5000)

		element.querySelector('.post-warning').insertAdjacentHTML('beforeend', '<div class="post-message post-message--behind-warning">text</div>')
		fire(doc, 'click')
		vi.advanceTimersByTime(3000)
		hide(element)

		expect(tracker.pending()[0]).toMatchObject({ kind: 'impression', visible_ms: 3000 })
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
		track({ generation: 4 }).observe(element, post('1'))
		fire(doc, 'scroll')
		show(element, { from: 'below' })
		vi.advanceTimersByTime(300)
		fire(doc, 'scroll')
		hide(element, 'above')

		expect(tracker.pending()).toHaveLength(1)
		expect(tracker.pending()[0]).toMatchObject({ status_id: '1', kind: 'skip', context: 'home', generation: 4 })
	})

	it('records one consented video watch ratio, rounded and capped at one', () => {
		track({ generation: 4 })
		tracker.recordVideoWatchRatio(post('1'), 1.25)
		tracker.recordVideoWatchRatio(post('1'), 0.5)
		tracker.recordVideoWatchRatio(post('2'), 0.7546)

		const events = tracker.pending()
		expect(events).toHaveLength(2)
		expect(events[0]).toMatchObject({ status_id: '1', kind: 'video_watch_ratio', ratio: 1, context: 'home', generation: 4 })
		expect(events[1]).toMatchObject({ status_id: '2', kind: 'video_watch_ratio', ratio: 0.755, generation: 4 })
		expect(events[0].event_id).toMatch(/^[a-f0-9-]{36}$/i)
	})

	it('does not collect video ratios without active learning consent', () => {
		track()
		tracker.recordVideoWatchRatio(post('1'), 0.9)
		tracker.setLearningGeneration(4)
		tracker.setLearningGeneration(null)
		tracker.recordVideoWatchRatio(post('2'), 0.9)

		expect(tracker.pending()).toEqual([])
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

		expect(tracker.pending()).toEqual([])
	})

	it('keeps repeated passive views out of the signal buffer', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(2000)
		hide(element)
		show(element)
		vi.advanceTimersByTime(2000)
		hide(element)

		expect(tracker.pending()).toHaveLength(0)
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
		track({ generation: 4 })
		tracker.record(post('1'), 'media')
		tracker.record(post('1'), 'media')
		tracker.record(post('1'), 'link')

		expect(tracker.pending()).toHaveLength(2)
		expect(tracker.pending()).toEqual(expect.arrayContaining([
			expect.objectContaining({ status_id: '1', kind: 'media', context: 'home', generation: 4 }),
			expect.objectContaining({ status_id: '1', kind: 'link', context: 'home', generation: 4 }),
		]))
	})

	it('does not queue reading events until the consent generation is known', () => {
		track()
		tracker.record(post('1'), 'media')
		tracker.flush()

		expect(tracker.pending()).toEqual([])
		expect(send).not.toHaveBeenCalled()
	})

	it('keeps the separately consented legacy hashtag path active without a recommendation generation', async () => {
		track({ legacyLearning: true }).record(post('1'), 'open')

		await tracker.flush()

		expect(send).toHaveBeenCalledWith([expect.objectContaining({ status_id: '1', kind: 'open', context: 'home' })], { beacon: false, legacy: true })
		expect(tracker.pending()[0]).toBeUndefined()
		expect(send.mock.calls[0][0][0]).not.toHaveProperty('generation')
	})

	it('keeps legacy events when recommendation consent becomes active and strips stale recommendation metadata on revocation', () => {
		track({ legacyLearning: true }).record(post('legacy'), 'open')
		tracker.setLearningGeneration(5)
		tracker.record(post('ranked'), 'open')
		tracker.setLearningGeneration(null)

		expect(tracker.pending()).toEqual([
			expect.objectContaining({ status_id: 'legacy', kind: 'open' }),
			expect.objectContaining({ status_id: 'ranked', kind: 'open' }),
		])
		expect(tracker.pending()[1]).not.toHaveProperty('generation')
		expect(tracker.pending()[1]).not.toHaveProperty('event_id')
	})

	it('drops queued events when consent is disabled or its generation changes', () => {
		track({ generation: 4 }).record(post('1'), 'media')
		tracker.setLearningGeneration(null)

		expect(tracker.pending()).toEqual([])
		tracker.record(post('2'), 'media')
		expect(tracker.pending()).toEqual([])

		tracker.setLearningGeneration(5)
		tracker.record(post('3'), 'media')
		expect(tracker.pending()).toHaveLength(1)
		expect(tracker.pending()[0]).toMatchObject({ status_id: '3', generation: 5 })
	})

	it('measures a visible post only after the current consent generation is active', () => {
		const element = entry()
		track().observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(5000)
		tracker.setLearningGeneration(4)
		vi.advanceTimersByTime(900)
		hide(element)

		const impression = tracker.pending().find((event) => event.kind === 'impression')
		expect(impression).toMatchObject({ status_id: '1', visible_ms: 900, generation: 4 })
	})

	it('resets in-progress exposure when consent is revoked and enabled again', () => {
		const element = entry()
		track({ generation: 4 }).observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(900)
		hide(element)

		tracker.setLearningGeneration(null)
		show(element)
		vi.advanceTimersByTime(5000)
		tracker.setLearningGeneration(5)
		vi.advanceTimersByTime(900)
		hide(element)

		expect(tracker.pending().filter((event) => event.kind === 'impression')).toEqual([
			expect.objectContaining({ status_id: '1', visible_ms: 900, generation: 5 }),
		])
	})

	it('flushes every thirty seconds', () => {
		track({ generation: 4 }).record(post('1'), 'open')
		expect(send).not.toHaveBeenCalled()

		vi.advanceTimersByTime(FLUSH_EVERY)

		expect(send).toHaveBeenCalledWith([expect.objectContaining({ status_id: '1', kind: 'open', context: 'home', generation: 4 })], { beacon: false, legacy: false })
		expect(tracker.pending()).toEqual([])
	})

	it('sends at most a hundred events per request', () => {
		track({ generation: 4 })
		for (let i = 0; i < 250; i++) {
			tracker.record(post(String(i)), 'media')
		}
		tracker.flush()

		expect(send.mock.calls.map(([events]) => events.length)).toEqual([BATCH_SIZE, BATCH_SIZE, 50])
	})

	it('sends by beacon on pagehide, what is on screen included', () => {
		const element = entry()
		track({ generation: 4 }).observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(4000)
		fire(win, 'pagehide')

		expect(send).toHaveBeenCalledWith([
			expect.objectContaining({ status_id: '1', kind: 'impression', visible_ms: 4000, generation: 4 }),
		], { beacon: true, legacy: false })
	})

	it('beacons newly buffered events when pagehide overlaps an earlier flush', async () => {
		let finishFirstSend
		send.mockImplementationOnce(() => new Promise((resolve) => {
			finishFirstSend = resolve
		}))
		track({ generation: 4 }).record(post('1'), 'media')
		const firstFlush = tracker.flush()
		tracker.record(post('2'), 'media')

		fire(win, 'pagehide')

		expect(send).toHaveBeenNthCalledWith(
			2,
			[expect.objectContaining({ status_id: '2', kind: 'media', generation: 4 })],
			{ beacon: true, legacy: false },
		)
		finishFirstSend()
		await firstFlush
		await Promise.resolve()
		expect(tracker.pending()).toEqual([])
	})

	it('does not count time away while a page is in the back-forward cache', () => {
		const element = entry()
		track({ generation: 4 }).observe(element, post('1'))
		show(element)
		vi.advanceTimersByTime(900)
		fire(win, 'pagehide')

		vi.advanceTimersByTime(30000)
		fire(win, 'pageshow')
		vi.advanceTimersByTime(300)
		hide(element)

		const impressions = send.mock.calls.flatMap(([events]) => events).filter((event) => event.kind === 'impression')
		expect(impressions).toHaveLength(1)
		expect(impressions[0]).toMatchObject({ status_id: '1', visible_ms: 900 })
	})

	it('lets go of entries that left the page and finishes their view', () => {
		const element = entry()
		track().sync([[element, post('1')]])
		show(element)
		vi.advanceTimersByTime(2000)
		tracker.sync([])

		expect(FakeIntersectionObserver.last.unobserve).toHaveBeenCalledWith(element)
		expect(tracker.pending()).toEqual([])
	})

	it('sends what is left and stops listening when destroyed', () => {
		track({ generation: 4 }).record(post('1'), 'media')
		tracker.destroy()

		expect(send).toHaveBeenCalledTimes(1)
		expect(FakeIntersectionObserver.last.disconnect).toHaveBeenCalled()

		tracker.record(post('2'), 'media')
		fire(win, 'pagehide')
		expect(send).toHaveBeenCalledTimes(1)
	})

	it('retries a failed learning batch unchanged with the same event ID', async () => {
		send.mockRejectedValueOnce(new Error('down'))
		track({ generation: 7 }).record(post('1'), 'media')
		const original = tracker.pending()[0]

		await tracker.flush()

		expect(tracker.pending()).toEqual([original])
		expect(send).toHaveBeenCalledTimes(1)

		send.mockResolvedValueOnce()
		await tracker.flush()

		expect(send).toHaveBeenNthCalledWith(2, [original], { beacon: false, legacy: false })
		expect(tracker.pending()).toEqual([])
	})

	describe('one signal outside a list', () => {
		beforeEach(() => {
			vi.clearAllMocks()
		})

		it('is sent at once', async () => {
			await signalNow(post('1'), 'open', 'detail')

			expect(fetchRecommendationPreferences).toHaveBeenCalledOnce()
			expect(sendSignals).toHaveBeenCalledWith([expect.objectContaining({
				event_id: expect.stringMatching(/^[a-f0-9-]{36}$/i),
				generation: 7,
				status_id: '1',
				kind: 'open',
				context: 'detail',
			})], { legacy: false })
		})

		it('is not sent for the reader\'s own post or one without a hashtag', async () => {
			sendSignals.mockClear()
			await signalNow(post('1'), 'open', 'detail', () => true)
			await signalNow(post('2', { tags: [] }), 'open', 'detail')

			expect(sendSignals).not.toHaveBeenCalled()
			expect(fetchRecommendationPreferences).not.toHaveBeenCalled()
		})

		it('does not send a signal the server does not accept', async () => {
			await signalNow(post('1'), 'mute', 'detail')

			expect(fetchRecommendationPreferences).not.toHaveBeenCalled()
			expect(sendSignals).not.toHaveBeenCalled()
		})

		it('does not buffer actions the signal endpoint rejects', () => {
			const one = track({ generation: 7 })
			one.record(post('1'), 'mute')

			expect(one.pending()).toEqual([])
		})

		it('can report a ranked-feed open for an untagged post with a known generation', async () => {
			await signalNow(post('1', { tags: [] }), 'open', 'detail', () => false, {}, sendSignals, 9, true)

			expect(fetchRecommendationPreferences).not.toHaveBeenCalled()
			expect(sendSignals).toHaveBeenCalledWith([expect.objectContaining({ generation: 9, status_id: '1', kind: 'open' })], { legacy: false })
		})

		it('checks recommendation consent before reporting an untagged post even when legacy learning is enabled', async () => {
			fetchRecommendationPreferences.mockResolvedValueOnce({ learning_mode: 'off', generation: 7 })

			await signalNow(post('1', { tags: [] }), 'open', 'interests', () => false, {}, sendSignals, null, true, true)

			expect(fetchRecommendationPreferences).toHaveBeenCalledOnce()
			expect(sendSignals).not.toHaveBeenCalled()
		})

		it('routes an untagged ranked-feed action only through recommendation learning', async () => {
			await signalNow(post('1', { tags: [] }), 'open', 'interests', () => false, {}, sendSignals, null, true, true)

			expect(sendSignals).toHaveBeenCalledWith([expect.objectContaining({ generation: 7, status_id: '1', kind: 'open' })], { legacy: false })
		})
	})
})
