/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { DEFAULT_LIMITS, knownLimits, limitsFrom, loadLimits, resetLimitsForTests } from '../../../src/services/instanceLimits.js'
import { useInstanceStore } from '../../../src/store/instance.js'

// The store waits for the timeline before it asks; that waiting is boot.js's
// and is tested there. Here it runs at once, so these tests are about the
// numbers rather than about the wait.
vi.mock('../../../src/services/boot.js', () => ({
	afterFirstTimeline: (callback) => callback(),
}))

const instance = (statuses) => ({ configuration: { statuses } })

describe('the server\'s limits', () => {
	afterEach(() => {
		resetLimitsForTests()
		vi.unstubAllGlobals()
	})

	describe('limitsFrom', () => {
		it('reports whether the server can translate', () => {
			expect(limitsFrom({ configuration: { translation: { enabled: true } } }).translation).toBe(true)
		})

		// a server that says nothing about it cannot do it: a translate button
		// that does nothing is worse than no button
		it('assumes it cannot when the server says nothing', () => {
			expect(limitsFrom({ configuration: {} }).translation).toBe(false)
		})

		it('reads both numbers out of the instance entity', () => {
			expect(limitsFrom(instance({ max_characters: 1000, max_media_attachments: 4 })))
				.toEqual({ maxCharacters: 1000, maxAttachments: 4, imageSizeLimit: 10485760, videoSizeLimit: 2147483648, translation: false })
		})

		/** what the composer shrinks a picture to before it uploads it */
		it('reads the upload ceiling out of the instance entity', () => {
			expect(limitsFrom({ configuration: { media_attachments: { image_size_limit: 20971520 } } }).imageSizeLimit)
				.toBe(20971520)
			expect(limitsFrom({ configuration: { media_attachments: { image_size_limit: 0 } } }).imageSizeLimit)
				.toBe(10485760)
		})

		/** a video cannot be shrunk, so it is turned away before it travels */
		it('reads the video ceiling out of the instance entity, apart from the picture one', () => {
			const limits = limitsFrom({ configuration: { media_attachments: { image_size_limit: 10485760, video_size_limit: 524288000 } } })

			expect(limits.videoSizeLimit).toBe(524288000)
			expect(limits.imageSizeLimit).toBe(10485760)
			expect(limitsFrom({ configuration: { media_attachments: { video_size_limit: 'lots' } } }).videoSizeLimit)
				.toBe(2147483648)
		})

		it('takes strings, as a JSON entity may carry them', () => {
			expect(limitsFrom(instance({ max_characters: '750', max_media_attachments: '6' })))
				.toEqual({ maxCharacters: 750, maxAttachments: 6, imageSizeLimit: 10485760, videoSizeLimit: 2147483648, translation: false })
		})

		it.each([
			['nothing at all', null],
			['an entity without a configuration', {}],
			['zeroes', instance({ max_characters: 0, max_media_attachments: 0 })],
			['nonsense', instance({ max_characters: 'lots', max_media_attachments: -3 })],
			['fractions', instance({ max_characters: 12.5, max_media_attachments: 2.5 })],
		])('falls back to the old constants for %s', (_, entity) => {
			expect(limitsFrom(entity)).toEqual({ maxCharacters: 500, maxAttachments: 10, imageSizeLimit: 10485760, videoSizeLimit: 2147483648, translation: false })
		})
	})

	describe('loadLimits', () => {
		it('asks the instance route once and remembers the answer', async () => {
			const fetch = vi.fn(async () => ({
				ok: true,
				json: async () => instance({ max_characters: 2000, max_media_attachments: 8 }),
			}))
			vi.stubGlobal('fetch', fetch)

			expect(knownLimits()).toEqual(DEFAULT_LIMITS)
			const [first, second] = await Promise.all([loadLimits(), loadLimits()])

			expect(fetch).toHaveBeenCalledTimes(1)
			expect(fetch).toHaveBeenCalledWith(
				'/index.php/apps/social/api/v1/instance/',
				{ credentials: 'same-origin', headers: { Accept: 'application/json' } },
			)
			expect(first).toEqual({ maxCharacters: 2000, maxAttachments: 8, imageSizeLimit: 10485760, videoSizeLimit: 2147483648, translation: false })
			expect(second).toBe(first)
			expect(knownLimits()).toEqual(first)
		})

		it('keeps the defaults when the server answers with an error', async () => {
			vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => ({}) })))

			expect(await loadLimits()).toEqual(DEFAULT_LIMITS)
		})

		it('keeps the defaults when the request fails outright', async () => {
			vi.stubGlobal('fetch', vi.fn(async () => {
				throw new TypeError('Failed to fetch')
			}))

			expect(await loadLimits()).toEqual(DEFAULT_LIMITS)
			expect(knownLimits()).toEqual(DEFAULT_LIMITS)
		})
	})

	describe('the instance store', () => {
		it('starts at the old constants, so a composer opened before the answer is usable', () => {
			setActivePinia(createPinia())
			const store = useInstanceStore()

			expect(store.maxCharacters).toBe(500)
			expect(store.maxAttachments).toBe(10)
			expect(store.videoSizeLimit).toBe(2147483648)
		})

		it('takes the server\'s numbers, asking once for the page', async () => {
			const fetch = vi.fn(async () => ({
				ok: true,
				json: async () => ({ configuration: { statuses: { max_characters: 5000, max_media_attachments: 20 }, media_attachments: { video_size_limit: 524288000 } } }),
			}))
			vi.stubGlobal('fetch', fetch)
			setActivePinia(createPinia())
			const store = useInstanceStore()

			store.load()
			store.load()
			// the request is in flight by now; the store takes its answer when
			// it comes
			await loadLimits()
			await Promise.resolve()

			expect(fetch).toHaveBeenCalledTimes(1)
			expect(store.maxCharacters).toBe(5000)
			expect(store.maxAttachments).toBe(20)
			expect(store.videoSizeLimit).toBe(524288000)
		})
	})
})
