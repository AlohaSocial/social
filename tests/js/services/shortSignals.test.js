/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SKIP_WITHIN, createShortSignals } from '../../../src/services/shortSignals.js'
import { fetchRecommendationPreferences } from '../../../src/services/interests.js'

vi.mock('../../../src/services/interests.js', () => ({
	fetchRecommendationPreferences: vi.fn(() => Promise.resolve({ learning_mode: 'on', generation: 7 })),
	isRecommendationLearningActive: (preferences) => preferences?.learning_active ?? preferences?.learning_mode === 'on',
	sendSignals: vi.fn(() => Promise.resolve()),
}))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const clip = (id, extra = {}) => ({ id, tags: [{ name: 'skate' }], account: { acct: 'bob@remote.example' }, ...extra })

describe('what watching the Shorts teaches', () => {
	let clock
	let send
	let signals

	beforeEach(() => {
		clock = 1000
		fetchRecommendationPreferences.mockReset().mockResolvedValue({ learning_mode: 'on', generation: 7 })
		send = vi.fn(() => Promise.resolve())
		signals = createShortSignals({ now: () => clock, send, generation: 7 })
	})

	/** @return {object[]} every event sent, in order */
	const sent = () => send.mock.calls.flatMap(([events]) => events)
	const expectSignal = (event, expected) => expect(event).toMatchObject({
		event_id: expect.stringMatching(/^[a-f0-9-]{36}$/i),
		generation: 7,
		context: 'shorts',
		...expected,
	})

	it('calls a swipe away within two seconds a skip', () => {
		signals.enter(clip('1'))
		clock += SKIP_WITHIN - 1
		signals.enter(clip('2'))

		expect(sent()).toHaveLength(1)
		expectSignal(sent()[0], { status_id: '1', kind: 'skip' })
	})

	it('reports the measured playback fraction when the reader moves on', () => {
		signals.enter(clip('1'))
		signals.progress(clip('1'), { currentTime: 0.5, duration: 10 })
		signals.progress(clip('1'), { currentTime: 2.25, duration: 10 })
		signals.progress(clip('1'), { currentTime: 4.25, duration: 10 })
		clock += 5000
		signals.leave()

		expect(sent()).toHaveLength(1)
		expectSignal(sent()[0], { status_id: '1', kind: 'video_watch_ratio', ratio: 0.425 })
	})

	it('uses the player played ranges when timeupdate events arrive late', () => {
		signals.enter(clip('1'))
		signals.progress(clip('1'), {
			currentTime: 6,
			duration: 10,
			played: {
				length: 2,
				start: (index) => [0, 4][index],
				end: (index) => [3, 6][index],
			},
		})
		clock += 7000
		signals.leave()

		expect(sent()).toHaveLength(1)
		expectSignal(sent()[0], { status_id: '1', kind: 'video_watch_ratio', ratio: 0.5 })
	})

	it('counts playback through to the end', () => {
		signals.enter(clip('1'))
		signals.progress(clip('1'), { currentTime: 0.9, duration: 1.8 })
		signals.progress(clip('1'), { currentTime: 1.8, duration: 1.8 })
		signals.ended(clip('1'))
		clock += 1900
		signals.leave()

		expect(sent()).toHaveLength(1)
		expectSignal(sent()[0], { status_id: '1', kind: 'video_watch_ratio', ratio: 1 })
	})

	it('reads a jump from the end back to the start as a loop, which is watched through', () => {
		signals.enter(clip('1'))
		for (const currentTime of [1, 3, 5, 7, 9, 9.6]) {
			signals.progress(clip('1'), { currentTime, duration: 10 })
		}
		signals.progress(clip('1'), { currentTime: 0.2, duration: 10 })
		clock += 12000
		signals.leave()

		expect(sent()).toHaveLength(1)
		expectSignal(sent()[0], { status_id: '1', kind: 'video_watch_ratio', ratio: 1 })
	})

	it('aggregates playback across video slides in one post', () => {
		const status = clip('multi-video-post')
		const aggregatedSignals = createShortSignals({
			now: () => clock,
			send,
			generation: 7,
			legacyLearning: () => true,
		})
		aggregatedSignals.enter(status, 'multi-video-post:first')
		aggregatedSignals.progress(status, {
			currentTime: 4,
			duration: 10,
			played: { length: 1, start: () => 0, end: () => 4 },
		}, 'multi-video-post:first')
		clock += 5000

		aggregatedSignals.enter(status, 'multi-video-post:second')
		aggregatedSignals.progress(status, {
			currentTime: 0.5,
			duration: 10,
			played: { length: 1, start: () => 0, end: () => 0.5 },
		}, 'multi-video-post:second')
		clock += 5000
		aggregatedSignals.leave()

		expect(sent()).toHaveLength(2)
		expectSignal(sent()[0], { status_id: 'multi-video-post', kind: 'video_watch_ratio', ratio: 0.225 })
		expect(sent()[1]).toMatchObject({
			status_id: 'multi-video-post',
			kind: 'dwell',
			ms: 4500,
			context: 'shorts',
		})
		expect(send).toHaveBeenCalledWith([expect.objectContaining({ kind: 'dwell', ms: 4500 })], { legacy: true })
	})

	it('does not mistake seeking to the end for watched content', () => {
		signals.enter(clip('1'))
		signals.progress(clip('1'), { currentTime: 90, duration: 120, seeking: true })
		signals.ended(clip('1'))
		clock += 120000
		signals.leave()

		expect(sent()).toEqual([])
	})

	it('ignores what a player says about a slide that is not the current one', () => {
		signals.enter(clip('1'))
		signals.progress(clip('2'), { currentTime: 8, duration: 10 })
		signals.ended(clip('2'))
		clock += 5000
		signals.leave()

		expect(sent()).toEqual([])
	})

	it('says one thing per post, and nothing when the reader stays on it', () => {
		signals.enter(clip('1'))
		signals.enter(clip('1'))
		clock += 100
		signals.enter(clip('2'))
		signals.enter(clip('1'))
		clock += 100
		signals.leave()

		expect(sent()).toHaveLength(2)
		expectSignal(sent()[0], { status_id: '1', kind: 'skip' })
		expectSignal(sent()[1], { status_id: '2', kind: 'skip' })
	})

	it('allows an untagged ranked-feed video after consent', () => {
		const ranked = createShortSignals({ now: () => clock, send, generation: 3, allowUntagged: true })
		ranked.enter(clip('untagged', { tags: [] }))
		ranked.progress(clip('untagged', { tags: [] }), { currentTime: 1, duration: 2 })
		clock += 2500
		ranked.leave()

		expect(sent()).toHaveLength(1)
		expect(sent()[0]).toMatchObject({ generation: 3, status_id: 'untagged', kind: 'video_watch_ratio', ratio: 0.5 })
	})

	it('keeps legacy Shorts learning separate from recommendation consent', () => {
		fetchRecommendationPreferences.mockResolvedValue({ learning_active: false, generation: 7 })
		const legacy = createShortSignals({ now: () => clock, send, legacyLearning: () => true, enabled: () => true })
		legacy.enter(clip('legacy'))
		legacy.progress(clip('legacy'), { currentTime: 1, duration: 2 })
		clock += 2500
		legacy.leave()

		expect(sent()).toHaveLength(1)
		expect(sent()[0]).toMatchObject({ status_id: 'legacy', kind: 'dwell', ms: 1000 })
		expect(sent()[0]).not.toHaveProperty('generation')
		expect(send).toHaveBeenCalledWith([expect.any(Object)], { legacy: true })
	})

	it('teaches nothing from an untagged post, one of the reader\'s own, or while learning is off', () => {
		const off = createShortSignals({ now: () => clock, send, enabled: () => false })
		off.enter(clip('1'))
		clock += 100
		off.leave()

		const own = createShortSignals({ now: () => clock, send, isOwn: (status) => status.account.acct === 'me' })
		own.enter(clip('2', { account: { acct: 'me' } }))
		clock += 100
		own.enter(clip('3', { tags: [] }))
		clock += 100
		own.leave()

		expect(send).not.toHaveBeenCalled()
	})
})
