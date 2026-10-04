/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import {
	DEFAULT_DELIVERY,
	MAX_TIMES,
	fetchNotificationDelivery,
	isValidTime,
	normaliseDelivery,
	saveNotificationDelivery,
	sortTimes,
} from '../../../src/services/notificationDelivery.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), patch: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1/social/notification_delivery'

const digest = {
	mode: 'digest',
	times: ['18:00', '08:00'],
	passthrough: { direct: false, mentions_from_followed: true },
	quiet: { from: '22:00', to: '07:00' },
}

describe('the notification delivery API', () => {
	beforeEach(() => {
		axios.get.mockReset().mockResolvedValue({ data: digest })
		axios.patch.mockReset().mockResolvedValue({ data: digest })
	})

	it('knows a wall-clock time when it sees one', () => {
		expect(isValidTime('00:00')).toBe(true)
		expect(isValidTime('08:05')).toBe(true)
		expect(isValidTime('23:59')).toBe(true)

		expect(isValidTime('24:00')).toBe(false)
		expect(isValidTime('8:00')).toBe(false)
		expect(isValidTime('08:60')).toBe(false)
		expect(isValidTime('08:00:00')).toBe(false)
		expect(isValidTime('')).toBe(false)
		expect(isValidTime(null)).toBe(false)
		expect(isValidTime(800)).toBe(false)
	})

	it('puts the times of a day in order, each once, and drops what is not a time', () => {
		expect(sortTimes(['18:00', '08:00', '12:30'])).toEqual(['08:00', '12:30', '18:00'])
		expect(sortTimes(['08:00', '08:00'])).toEqual(['08:00'])
		expect(sortTimes(['08:00', 'noon', '', '25:00'])).toEqual(['08:00'])
		expect(sortTimes(undefined)).toEqual([])
	})

	it('starts everybody off with two times a day and both exceptions on', () => {
		expect(DEFAULT_DELIVERY.mode).toBe('instant')
		expect(DEFAULT_DELIVERY.times).toEqual(['08:00', '18:00'])
		expect(DEFAULT_DELIVERY.passthrough).toEqual({ direct: true, mentions_from_followed: true })
		expect(DEFAULT_DELIVERY.quiet).toEqual({ from: '', to: '' })
		expect(MAX_TIMES).toBe(4)
	})

	/** An older server, or a thinner answer, is filled up rather than crashed on. */
	it('fills a thin answer up to the whole shape', () => {
		expect(normaliseDelivery({})).toEqual({
			mode: 'instant',
			times: ['08:00', '18:00'],
			passthrough: { direct: true, mentions_from_followed: true },
			quiet: { from: '', to: '' },
		})
		expect(normaliseDelivery(null).mode).toBe('instant')
		expect(normaliseDelivery({ mode: 'whenever' }).mode).toBe('instant')
		expect(normaliseDelivery({ passthrough: { direct: false } }).passthrough)
			.toEqual({ direct: false, mentions_from_followed: true })
		expect(normaliseDelivery({ quiet: { from: '22:00' } }).quiet).toEqual({ from: '22:00', to: '' })
		expect(normaliseDelivery({ quiet: { from: 'late', to: '07:00' } }).quiet).toEqual({ from: '', to: '07:00' })
	})

	it('reads the state and hands it back sorted', async () => {
		expect(await fetchNotificationDelivery()).toEqual({
			mode: 'digest',
			times: ['08:00', '18:00'],
			passthrough: { direct: false, mentions_from_followed: true },
			quiet: { from: '22:00', to: '07:00' },
		})
		expect(axios.get).toHaveBeenCalledWith(API)
	})

	it('patches only what changed and takes the whole answer back', async () => {
		axios.patch.mockResolvedValue({ data: { ...digest, times: ['09:00'] } })

		const saved = await saveNotificationDelivery({ times: ['09:00'] })

		expect(axios.patch).toHaveBeenCalledWith(API, { times: ['09:00'] })
		expect(saved.times).toEqual(['09:00'])
		expect(saved.mode).toBe('digest')
	})

	/** The server's refusal is the caller's to show, so it is not swallowed here. */
	it('lets a refusal through', async () => {
		const refusal = Object.assign(new Error('422'), { response: { status: 422, data: { error: 'Times must be distinct' } } })
		axios.patch.mockRejectedValue(refusal)

		await expect(saveNotificationDelivery({ times: ['08:00', '08:00'] })).rejects.toBe(refusal)
	})
})
