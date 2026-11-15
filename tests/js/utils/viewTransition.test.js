/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { morph, transitionsWanted, uniqueTransitionName } from '../../../src/utils/viewTransition.js'

function withApi(implementation) {
	document.startViewTransition = implementation
}

function wantsLessMotion(reduce) {
	window.matchMedia = vi.fn(() => ({ matches: reduce, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
}

describe('view transitions as an enhancement', () => {
	afterEach(() => {
		delete document.startViewTransition
		vi.restoreAllMocks()
	})

	it('are wanted only where the browser has them and motion is welcome', () => {
		wantsLessMotion(false)
		expect(transitionsWanted()).toBe(false)

		withApi(vi.fn())
		expect(transitionsWanted()).toBe(true)

		wantsLessMotion(true)
		expect(transitionsWanted()).toBe(false)
	})

	describe('morphing one element into another', () => {
		/**
		 * A browser that finds two elements with one name abandons the whole
		 * transition, so the name has to have left the first element before
		 * the second one gets it.
		 */
		it('moves the name from the start to the end, never both at once', async () => {
			wantsLessMotion(false)
			const from = document.createElement('div')
			const to = document.createElement('div')
			let finish
			const finished = new Promise((resolve) => {
				finish = resolve
			})
			const seen = []
			withApi((callback) => {
				seen.push(from.style.viewTransitionName)
				const done = Promise.resolve(callback()).then(() => {
					seen.push(from.style.viewTransitionName, to.style.viewTransitionName)
				})
				return { updateCallbackDone: done, finished, ready: Promise.resolve() }
			})

			await morph(from, vi.fn(), () => to)

			expect(seen[0]).toMatch(/^social-morph-\d+$/)
			expect(seen[1]).toBe('')
			expect(seen[2]).toBe(seen[0])
			expect(to.style.viewTransitionClass).toBe('social-morph')

			finish()
			await finished
			await Promise.resolve()
			expect(from.style.viewTransitionName).toBe('')
			expect(to.style.viewTransitionName).toBe('')
		})

		it('gives every morph a name of its own', () => {
			expect(uniqueTransitionName()).not.toBe(uniqueTransitionName())
		})

		it('applies the change directly when the browser has no transitions', async () => {
			wantsLessMotion(false)
			const change = vi.fn()
			const from = document.createElement('div')

			await morph(from, change, () => null)

			expect(change).toHaveBeenCalledTimes(1)
			expect(from.style.viewTransitionName).toBe('')
		})

		it('applies the change directly for a reader who asked for less motion', async () => {
			wantsLessMotion(true)
			const start = vi.fn()
			withApi(start)
			const change = vi.fn()

			await morph(document.createElement('div'), change, () => null)

			expect(change).toHaveBeenCalledTimes(1)
			expect(start).not.toHaveBeenCalled()
		})

		it('still applies the change when the transition itself fails', async () => {
			wantsLessMotion(false)
			const change = vi.fn()
			withApi((callback) => {
				callback()
				return { updateCallbackDone: Promise.reject(new Error('interrupted')), finished: Promise.reject(new Error('interrupted')), ready: Promise.reject(new Error('skipped')) }
			})

			await morph(document.createElement('div'), change, () => null)

			expect(change).toHaveBeenCalledTimes(1)
		})
	})
})
