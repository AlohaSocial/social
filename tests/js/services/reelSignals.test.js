/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SKIP_WITHIN, createReelSignals } from '../../../src/services/reelSignals.js'

vi.mock('../../../src/services/interests.js', () => ({ sendSignals: vi.fn(() => Promise.resolve()) }))
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
		send = vi.fn(() => Promise.resolve())
		signals = createReelSignals({ now: () => clock, send })
	})

	/** @return {object[]} every event sent, in order */
	const sent = () => send.mock.calls.flatMap(([events]) => events)

	it('calls a swipe away within two seconds a skip', () => {
		signals.enter(clip('1'))
		clock += SKIP_WITHIN - 1
		signals.enter(clip('2'))

		expect(sent()).toEqual([{ status_id: '1', kind: 'skip', context: 'reels' }])
	})

	it('reports how much of the video was watched when the reader moves on', () => {
		signals.enter(clip('1'))
		signals.progress(clip('1'), { currentTime: 1.5, duration: 10 })
		signals.progress(clip('1'), { currentTime: 4.25, duration: 10 })
		clock += 5000
		signals.leave()

		expect(sent()).toEqual([{ status_id: '1', kind: 'dwell', ms: 4250, context: 'reels' }])
	})

	it('counts a video that ended as watched to the end, however quickly', () => {
		signals.enter(clip('1'))
		signals.progress(clip('1'), { currentTime: 1, duration: 1.8 })
		signals.ended(clip('1'))
		clock += 1900
		signals.leave()

		expect(sent()).toEqual([{ status_id: '1', kind: 'dwell', ms: 1800, context: 'reels' }])
	})

	it('reads a jump from the end back to the start as a loop, which is watched through', () => {
		signals.enter(clip('1'))
		signals.progress(clip('1'), { currentTime: 9.6, duration: 10 })
		signals.progress(clip('1'), { currentTime: 0.2, duration: 10 })
		clock += 12000
		signals.leave()

		expect(sent()).toEqual([{ status_id: '1', kind: 'dwell', ms: 10000, context: 'reels' }])
	})

	it('caps what one look can be worth, as the server does', () => {
		signals.enter(clip('1'))
		signals.progress(clip('1'), { currentTime: 90, duration: 120 })
		signals.ended(clip('1'))
		clock += 120000
		signals.leave()

		expect(sent()[0].ms).toBe(30000)
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

		expect(sent()).toEqual([
			{ status_id: '1', kind: 'skip', context: 'reels' },
			{ status_id: '2', kind: 'skip', context: 'reels' },
		])
	})

	it('teaches nothing from an untagged post, one of the reader\'s own, or while learning is off', () => {
		const off = createReelSignals({ now: () => clock, send, enabled: () => false })
		off.enter(clip('1'))
		clock += 100
		off.leave()

		const own = createReelSignals({ now: () => clock, send, isOwn: (status) => status.account.acct === 'me' })
		own.enter(clip('2', { account: { acct: 'me' } }))
		clock += 100
		own.enter(clip('3', { tags: [] }))
		clock += 100
		own.leave()

		expect(send).not.toHaveBeenCalled()
	})
})
