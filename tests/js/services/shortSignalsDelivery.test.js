/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { createShortSignals } from '../../../src/services/shortSignals.js'
import { sendSignals } from '../../../src/services/interests.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'request-token' }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php/' + path }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const legacyEndpoint = '/index.php/apps/social/api/v1/interests/signals'
const recommendationsEndpoint = '/index.php/apps/social/api/v1/recommendations/signals'

function reportOneVideo({ generation = null, legacy = false } = {}) {
	let now = 1000
	const signals = createShortSignals({
		now: () => now,
		send: sendSignals,
		generation,
		legacyLearning: () => legacy,
	})
	const status = { id: '123', tags: [{ name: 'video' }] }
	signals.enter(status, 'video-1')
	signals.progress(status, {
		currentTime: 1,
		duration: 2,
		played: { length: 1, start: () => 0, end: () => 1 },
	}, 'video-1')
	now += 2500
	signals.leave()
}

describe('Shorts signal delivery through the real signal sender', () => {
	beforeEach(() => {
		axios.get.mockReset().mockImplementation(async (url) => ({
			data: url.includes('/recommendations/preferences')
				? { learning_active: true, generation: 7 }
				: {},
		}))
		axios.post.mockReset().mockResolvedValue({ data: {} })
	})

	it('sends measured dwell to legacy learning when recommendation learning is off', async () => {
		axios.get.mockResolvedValue({ data: { learning_active: false, generation: 7 } })
		reportOneVideo({ legacy: true })

		await vi.waitFor(() => expect(axios.post).toHaveBeenCalledOnce())
		expect(axios.post).toHaveBeenCalledWith(legacyEndpoint, { events: [{
			status_id: '123',
			kind: 'dwell',
			ms: 1000,
			context: 'shorts',
		}] })
	})

	it('sends playback ratio to recommendation learning without a legacy event', async () => {
		reportOneVideo({ generation: 7 })

		await vi.waitFor(() => expect(axios.post).toHaveBeenCalledOnce())
		expect(axios.post).toHaveBeenCalledWith(recommendationsEndpoint, {
			events: [expect.objectContaining({
				status_id: '123',
				kind: 'video_watch_ratio',
				ratio: 0.5,
				generation: 7,
			})],
		})
	})

	it('delivers both representations when legacy and recommendation learning are enabled', async () => {
		reportOneVideo({ generation: 7, legacy: true })

		await vi.waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2))
		expect(axios.post).toHaveBeenCalledWith(legacyEndpoint, { events: [{
			status_id: '123',
			kind: 'dwell',
			ms: 1000,
			context: 'shorts',
		}] })
		expect(axios.post).toHaveBeenCalledWith(recommendationsEndpoint, {
			events: [expect.objectContaining({ kind: 'video_watch_ratio', ratio: 0.5 })],
		})
	})
})
