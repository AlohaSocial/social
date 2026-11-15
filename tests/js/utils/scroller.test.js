/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

import { scrollOffset, scroller, scrollToTop } from '../../../src/utils/scroller.js'

/** @return {Element} the app's content column, added to the page */
function column() {
	const element = document.createElement('div')
	element.id = 'app-content-vue'
	document.body.appendChild(element)

	return element
}

describe('scroller', () => {
	afterEach(() => {
		document.querySelector('#app-content-vue')?.remove()
		Object.defineProperty(window, 'scrollY', { value: 0, configurable: true, writable: true })
	})

	it('finds the column the app renders into', () => {
		const element = column()
		expect(scroller()).toBe(element)
	})

	it('answers null before the app has rendered', () => {
		expect(scroller()).toBeNull()
	})

	it('measures the column, not the window', () => {
		// Nextcloud gives an app a fixed viewport: `window.scrollY` stays 0
		// however far down the column the reader is
		const element = column()
		element.scrollTop = 800

		expect(scrollOffset()).toBe(800)
		expect(window.scrollY).toBe(0)
	})

	it('falls back to the window where there is no column', () => {
		Object.defineProperty(window, 'scrollY', { value: 120, configurable: true, writable: true })

		expect(scrollOffset()).toBe(120)
	})

	describe('scrollToTop', () => {
		/** @param {boolean} reduce whether the reader asked for less motion */
		function motion(reduce) {
			vi.spyOn(window, 'matchMedia').mockImplementation((query) => ({ matches: reduce && query.includes('reduce'), media: query }))
		}

		afterEach(() => vi.restoreAllMocks())

		it('glides the column back to the top', () => {
			motion(false)
			const element = column()
			element.scrollTo = vi.fn()

			scrollToTop()

			expect(element.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' })
		})

		it('jumps for a reader who asked for less motion', () => {
			motion(true)
			const element = column()
			element.scrollTo = vi.fn()

			scrollToTop()

			expect(element.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' })
		})

		it('moves the window where there is no column', () => {
			motion(false)
			const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

			scrollToTop()

			expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' })
		})
	})
})
