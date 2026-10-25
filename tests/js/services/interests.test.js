/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import {
	fetchInterests,
	hasInterestsFeed,
	isRecommendationLearningActive,
	isTracking,
	lessLikeThis,
	recordRecommendationProfileVisit,
	saveInterestSettings,
	sendSignals,
	undoLessLikeThis,
} from '../../../src/services/interests.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getRequestToken: () => 'token/+1',
}))

const API = '/index.php/apps/social/api/v1/interests'
const events = [{ status_id: '1789344497747043682', kind: 'dwell', ms: 5400, context: 'home' }]

describe('the interests API', () => {
	beforeEach(() => {
		for (const method of ['get', 'post', 'put', 'delete']) {
			axios[method].mockReset().mockResolvedValue({ data: { thin: true } })
		}
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('tracks only while the feature, the reader and the pause all allow it', () => {
		expect(isTracking({ enabled: true, learning: true, paused: false })).toBe(true)
		expect(isTracking({ enabled: false, learning: true, paused: false })).toBe(false)
		expect(isTracking({ enabled: true, learning: false, paused: false })).toBe(false)
		expect(isTracking({ enabled: true, learning: true, paused: true })).toBe(false)
		expect(isTracking({ enabled: true, learning: true, paused: false, instancePaused: true })).toBe(false)
		expect(isTracking(undefined)).toBe(false)
	})

	it('keeps the feed while paused', () => {
		expect(hasInterestsFeed({ enabled: true, learning: true, paused: true })).toBe(true)
		expect(hasInterestsFeed({ enabled: true, learning: false })).toBe(false)
		expect(hasInterestsFeed(null)).toBe(false)
	})

	it('honours the instance learning stop and supports older servers', () => {
		expect(isRecommendationLearningActive({ learning_mode: 'on', learning_active: true })).toBe(true)
		expect(isRecommendationLearningActive({ learning_mode: 'on', learning_active: false })).toBe(false)
		expect(isRecommendationLearningActive({ learning_mode: 'on' })).toBe(true)
		expect(isRecommendationLearningActive({ learning_mode: 'paused' })).toBe(false)
	})

	it('does not send profile identity when the instance has paused learning', async () => {
		axios.get.mockResolvedValue({ data: { learning_mode: 'on', learning_active: false, generation: 7 } })

		await recordRecommendationProfileVisit('https://remote.example/users/bob')

		expect(axios.get).toHaveBeenCalledOnce()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('reads and saves the state', async () => {
		expect(await fetchInterests()).toEqual({ thin: true })
		expect(axios.get).toHaveBeenCalledWith(API)

		await saveInterestSettings({ noticeAcknowledged: true })
		expect(axios.put).toHaveBeenCalledWith(API + '/settings', { noticeAcknowledged: true })
	})

	it('sends less like this and takes it back by the post\'s id', async () => {
		await lessLikeThis('1789344497747043682')
		expect(axios.post).toHaveBeenCalledWith(API + '/less/1789344497747043682')

		await undoLessLikeThis('1789344497747043682')
		expect(axios.delete).toHaveBeenCalledWith(API + '/less/1789344497747043682')
	})

	it('posts signals as a request while the page stays', async () => {
		await sendSignals(events)

		expect(axios.post).toHaveBeenCalledWith(API + '/signals', { events })
	})

	it('drops learning events when the instance has paused learning', async () => {
		const event = { event_id: '9d4e3f8e-bde7-4ab6-91d3-0ad990ff63a5', generation: 7, status_id: '123', kind: 'open', context: 'interests' }
		axios.get.mockResolvedValue({ data: { learning_mode: 'on', learning_active: false, generation: 7 } })

		await sendSignals([event])

		expect(axios.post).toHaveBeenCalledWith(API + '/signals', { events: [{ status_id: '123', kind: 'open', context: 'interests' }] })
		expect(axios.post).not.toHaveBeenCalledWith(expect.stringContaining('/recommendations/signals'), expect.anything())
	})

	it('drops buffered events from a stale consent generation', async () => {
		const event = { event_id: '9d4e3f8e-bde7-4ab6-91d3-0ad990ff63a5', generation: 6, status_id: '123', kind: 'open', context: 'interests' }
		axios.get.mockResolvedValue({ data: { learning_mode: 'on', learning_active: true, generation: 7 } })

		await sendSignals([event])

		expect(axios.post).toHaveBeenCalledWith(API + '/signals', { events: [{ status_id: '123', kind: 'open', context: 'interests' }] })
		expect(axios.post).not.toHaveBeenCalledWith(expect.stringContaining('/recommendations/signals'), expect.anything())
	})

	it('routes recommendation-only events without sending them to legacy hashtag learning', async () => {
		const event = { event_id: '9d4e3f8e-bde7-4ab6-91d3-0ad990ff63a5', generation: 7, status_id: '123', kind: 'open', context: 'interests' }
		axios.get.mockResolvedValue({ data: { learning_mode: 'on', learning_active: true, generation: 7 } })

		await sendSignals([event], { legacy: false })

		expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/recommendations/signals'), { events: [event] })
		expect(axios.post).not.toHaveBeenCalledWith(API + '/signals', expect.anything())
	})

	it('sends only legacy event fields and omits recommendation-only observations', async () => {
		const events = [
			{ event_id: '9d4e3f8e-bde7-4ab6-91d3-0ad990ff63a5', generation: 7, status_id: '123', kind: 'open', context: 'home' },
			{ event_id: 'a4be2bc3-3d29-4ab6-a89f-941301f17a09', generation: 7, status_id: '124', kind: 'impression', context: 'home', session_id: 'f1286102-a496-4b3b-8a04-290c9db07b51', visible_ms: 1200 },
		]

		await sendSignals(events, { legacy: true })

		expect(axios.post).toHaveBeenCalledWith(API + '/signals', { events: [{ status_id: '123', kind: 'open', context: 'home' }] })
	})

	it('keeps hashtagless recommendation events out of legacy learning and strips internal routing metadata', async () => {
		const event = {
			event_id: '9d4e3f8e-bde7-4ab6-91d3-0ad990ff63a5',
			generation: 7,
			status_id: '123',
			kind: 'open',
			context: 'interests',
			legacy_eligible: false,
		}
		axios.get.mockResolvedValue({ data: { learning_mode: 'on', learning_active: true, generation: 7 } })

		await sendSignals([event], { legacy: true })

		expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/recommendations/signals'), { events: [{
			event_id: event.event_id,
			generation: 7,
			status_id: '123',
			kind: 'open',
			context: 'interests',
		}] })
		expect(axios.post).not.toHaveBeenCalledWith(API + '/signals', expect.anything())
	})

	it('sends nothing for nothing', async () => {
		await sendSignals([])

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('sends a beacon on the way out, the token in the query and the body as JSON', async () => {
		const sendBeacon = vi.fn(() => true)
		vi.stubGlobal('navigator', { sendBeacon })

		await sendSignals(events, { beacon: true })

		expect(axios.post).not.toHaveBeenCalled()
		const [target, blob] = sendBeacon.mock.calls[0]
		expect(target).toBe(API + '/signals?requesttoken=token%2F%2B1')
		expect(blob.type).toBe('application/json')
		expect(JSON.parse(await blob.text())).toEqual({ events })
	})

	it('sends recommendation-only observations by beacon without requiring a legacy event', async () => {
		const sendBeacon = vi.fn(() => true)
		vi.stubGlobal('navigator', { sendBeacon })
		const event = {
			event_id: '9d4e3f8e-bde7-4ab6-91d3-0ad990ff63a5',
			generation: 7,
			status_id: '123',
			kind: 'impression',
			context: 'interests',
			session_id: 'a4be2bc3-3d29-4ab6-a89f-941301f17a09',
			visible_ms: 1200,
		}

		await sendSignals([event], { beacon: true })

		expect(sendBeacon).toHaveBeenCalledOnce()
		const [target, blob] = sendBeacon.mock.calls[0]
		expect(target).toBe('/index.php/apps/social/api/v1/recommendations/signals?requesttoken=token%2F%2B1')
		expect(JSON.parse(await blob.text())).toEqual({ events: [event] })
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('falls back to a request when the browser will not queue the beacon', async () => {
		vi.stubGlobal('navigator', { sendBeacon: vi.fn(() => false) })

		await sendSignals(events, { beacon: true })

		expect(axios.post).toHaveBeenCalledWith(API + '/signals', { events })
	})
})
