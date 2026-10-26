/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { accumulateVideoPlayback } from '../../../src/services/videoPlayback.js'

describe('video playback measurement', () => {
	it('counts the union of played ranges, including overlaps, only once', () => {
		const playback = { lastTime: 0, watchedSeconds: 0 }
		const video = {
			currentTime: 8,
			duration: 10,
			played: {
				length: 3,
				start: (index) => [4, 0, 2][index],
				end: (index) => [7, 3, 5][index],
			},
		}

		expect(accumulateVideoPlayback(playback, video)).toBe(7)
		expect(playback.lastTime).toBe(8)
	})

	it('uses short forward deltas while played ranges are unavailable', () => {
		const playback = { lastTime: 1, watchedSeconds: 0 }

		expect(accumulateVideoPlayback(playback, { currentTime: 3, duration: 10 })).toBe(2)
		expect(accumulateVideoPlayback(playback, { currentTime: 5.5, duration: 10 })).toBe(2)
	})

	it('does not count a seek as played time', () => {
		const playback = { lastTime: 2, watchedSeconds: 1 }

		expect(accumulateVideoPlayback(playback, { currentTime: 9, duration: 10, seeking: true })).toBe(1)
		expect(playback.lastTime).toBe(9)
	})

	it('clips ranges to the video duration and ignores ranges beyond it', () => {
		const playback = { lastTime: 0, watchedSeconds: 0 }
		const video = {
			currentTime: 10,
			duration: 10,
			played: {
				length: 2,
				start: (index) => [8, 12][index],
				end: (index) => [14, 14][index],
			},
		}

		expect(accumulateVideoPlayback(playback, video)).toBe(2)
	})
})
