/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { canViewTransition, markDirection, reducedMotion, startPageTransition } from './pageTransition.js'

function asks(less) {
	window.matchMedia = vi.fn().mockReturnValue({ matches: less })
}

describe('reducedMotion', () => {
	afterEach(() => vi.restoreAllMocks())

	it('answers what the reader asked their system for', () => {
		asks(true)
		expect(reducedMotion()).toBe(true)

		asks(false)
		expect(reducedMotion()).toBe(false)
	})

	it('answers no for a browser that cannot be asked', () => {
		window.matchMedia = undefined
		expect(reducedMotion()).toBe(false)
	})
})

describe('canViewTransition', () => {
	afterEach(() => {
		delete document.startViewTransition
		vi.restoreAllMocks()
	})

	it('is false where the browser has no such thing', () => {
		asks(false)
		expect(canViewTransition()).toBe(false)
	})

	it('is true where it does', () => {
		asks(false)
		document.startViewTransition = vi.fn()
		expect(canViewTransition()).toBe(true)
	})

	/** A picture animated between two states is still movement. */
	it('is false for a reader who asked for less movement, however capable the browser', () => {
		asks(true)
		document.startViewTransition = vi.fn()
		expect(canViewTransition()).toBe(false)
	})
})

describe('startPageTransition', () => {
	beforeEach(() => vi.useFakeTimers())

	afterEach(() => {
		vi.useRealTimers()
		delete document.startViewTransition
		vi.restoreAllMocks()
	})

	it('just runs the update where the browser cannot take a picture', async () => {
		asks(false)
		const update = vi.fn().mockResolvedValue(undefined)

		await startPageTransition(update)

		expect(update).toHaveBeenCalledOnce()
	})

	it('runs the update inside the transition where it can', async () => {
		asks(false)
		const update = vi.fn().mockResolvedValue(undefined)
		document.startViewTransition = vi.fn((run) => {
			run()

			return { finished: Promise.resolve() }
		})

		await startPageTransition(update)

		expect(document.startViewTransition).toHaveBeenCalledOnce()
		expect(update).toHaveBeenCalledOnce()
	})

	it('lands what is carried across once the page is drawn, and lets it go after', async () => {
		asks(false)
		const order = []
		let finish
		const finished = new Promise((resolve) => {
			finish = resolve
		})
		const shared = { land: vi.fn(() => order.push('land')), release: vi.fn(() => order.push('release')) }
		document.startViewTransition = vi.fn((run) => {
			run()

			return { finished }
		})

		await startPageTransition(async () => {
			order.push('update')
		}, shared)
		await vi.advanceTimersByTimeAsync(0)
		expect(order).toEqual(['update', 'land'])

		finish()
		await vi.advanceTimersByTimeAsync(0)
		expect(order).toEqual(['update', 'land', 'release'])
	})

	it('lets go of what would have been carried where nothing is animated', async () => {
		asks(true)
		const shared = { land: vi.fn(), release: vi.fn() }

		await startPageTransition(vi.fn().mockResolvedValue(undefined), shared)

		expect(shared.land).not.toHaveBeenCalled()
		expect(shared.release).toHaveBeenCalledOnce()
	})

	/**
	 * The browser holds the old picture until the callback settles. A page
	 * whose data is slow would otherwise freeze everything, and a freeze is
	 * worse than a cut.
	 */
	it('does not wait for ever on a page that will not arrive', async () => {
		asks(false)
		document.startViewTransition = vi.fn(() => ({ finished: Promise.resolve() }))

		let settled = false
		const waiting = startPageTransition(() => new Promise(() => {})).then(() => {
			settled = true
		})

		expect(settled).toBe(false)
		await vi.advanceTimersByTimeAsync(500)
		await waiting

		expect(settled).toBe(true)
	})
})

describe('markDirection', () => {
	afterEach(() => document.documentElement.removeAttribute('data-page-direction'))

	it('names the direction where the pseudo-elements can read it', () => {
		markDirection('forward')
		expect(document.documentElement.getAttribute('data-page-direction')).toBe('forward')

		markDirection('back')
		expect(document.documentElement.getAttribute('data-page-direction')).toBe('back')
	})

	it('takes it off again when there is no direction to give', () => {
		markDirection('forward')
		markDirection('')

		expect(document.documentElement.hasAttribute('data-page-direction')).toBe(false)
	})
})
