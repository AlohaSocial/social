/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import BloomingFlower from '../../../src/components/BloomingFlower.vue'

/**
 * @param {boolean} reduce whether the reader asked their system for less motion
 */
function wantsLessMotion(reduce) {
	window.matchMedia = vi.fn((query) => ({ matches: reduce && query.includes('prefers-reduced-motion') }))
}

describe('BloomingFlower', () => {
	afterEach(() => vi.restoreAllMocks())

	it('is the five petals of the logo, hidden from assistive technology', () => {
		wantsLessMotion(false)
		const wrapper = mount(BloomingFlower, { props: { size: 32 } })

		expect(wrapper.findAll('.blooming-flower__petal')).toHaveLength(5)
		expect(wrapper.attributes('aria-hidden')).toBe('true')
		expect(wrapper.attributes('focusable')).toBe('false')
		expect(wrapper.attributes('width')).toBe('32')
	})

	it('blooms when it is drawn', () => {
		wantsLessMotion(false)

		expect(mount(BloomingFlower).classes()).toContain('blooming-flower--blooming')
	})

	it('is simply open for a reader who asked for less motion', () => {
		wantsLessMotion(true)

		expect(mount(BloomingFlower).classes()).not.toContain('blooming-flower--blooming')
	})

	it('blooms once per page for a named moment, however often it is drawn', () => {
		wantsLessMotion(false)

		expect(mount(BloomingFlower, { props: { onceKey: 'test-moment' } }).classes()).toContain('blooming-flower--blooming')
		expect(mount(BloomingFlower, { props: { onceKey: 'test-moment' } }).classes()).not.toContain('blooming-flower--blooming')
		expect(mount(BloomingFlower, { props: { onceKey: 'another-moment' } }).classes()).toContain('blooming-flower--blooming')
	})

	it('keeps still under reduced motion and stays visible in forced colours', () => {
		const source = readFileSync(resolve(process.cwd(), 'src/components/BloomingFlower.vue'), 'utf8')

		expect(source).toMatch(/@media \(prefers-reduced-motion: reduce\)[\s\S]*animation: none/)
		expect(source).toMatch(/@media \(forced-colors: active\)[\s\S]*CanvasText/)
	})
})
