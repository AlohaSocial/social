/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import PullToRefresh, { PULL_THRESHOLD } from '../../../src/components/PullToRefresh.vue'

/**
 * @param {Element} target where the touch happens
 * @param {string} type touchstart, touchmove or touchend
 * @param {number} [y] where the finger is
 * @param {number} [fingers] how many there are
 */
function touch(target, type, y = 0, fingers = 1) {
	const event = new Event(type, { bubbles: true })
	const touches = Array.from({ length: type === 'touchend' ? 0 : fingers }, () => ({ clientY: y }))
	Object.defineProperty(event, 'touches', { value: touches })
	target.dispatchEvent(event)
}

describe('pull to refresh', () => {
	/** the content column, which is what scrolls */
	let column

	beforeEach(() => {
		column = document.createElement('div')
		column.id = 'app-content-vue'
		document.body.appendChild(column)
	})

	afterEach(() => {
		column.remove()
	})

	/**
	 * @param {number} distance how far the finger travels down
	 * @return {Promise<import('@vue/test-utils').VueWrapper>} the indicator, after the pull
	 */
	async function pull(distance) {
		const wrapper = mount(PullToRefresh)
		touch(column, 'touchstart', 100)
		touch(column, 'touchmove', 100 + distance)
		await wrapper.vm.$nextTick()

		return wrapper
	}

	it('follows the finger with resistance, and refreshes once let go past the threshold', async () => {
		const wrapper = await pull(PULL_THRESHOLD * 2 + 10)

		expect(wrapper.find('.pull-refresh').classes()).toContain('pull-refresh--ready')
		expect(wrapper.find('.pull-refresh').attributes('style')).toContain(`--pull-height: ${PULL_THRESHOLD + 5}px`)

		touch(column, 'touchend')
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('refresh')).toHaveLength(1)
		expect(wrapper.find('.pull-refresh').attributes('style')).toContain('--pull-height: 0px')
	})

	it('springs back without refreshing when let go short of it', async () => {
		const wrapper = await pull(PULL_THRESHOLD)

		expect(wrapper.find('.pull-refresh').classes()).not.toContain('pull-refresh--ready')
		touch(column, 'touchend')

		expect(wrapper.emitted('refresh')).toBeUndefined()
	})

	it('leaves scrolling alone: not from below the top, not with two fingers, not upwards', async () => {
		column.scrollTop = 200
		expect((await pull(400)).emitted('refresh')).toBeUndefined()
		touch(column, 'touchend')

		column.scrollTop = 0
		const pinch = mount(PullToRefresh)
		touch(column, 'touchstart', 100, 2)
		touch(column, 'touchmove', 500, 2)
		touch(column, 'touchend')
		expect(pinch.emitted('refresh')).toBeUndefined()

		const up = mount(PullToRefresh)
		touch(column, 'touchstart', 300)
		touch(column, 'touchmove', 280)
		touch(column, 'touchmove', 600)
		touch(column, 'touchend')
		expect(up.emitted('refresh')).toBeUndefined()
	})

	it('listens passively, so it can never hold up a scroll', () => {
		const added = []
		const original = column.addEventListener
		column.addEventListener = (type, listener, options) => {
			added.push([type, options])
			original.call(column, type, listener, options)
		}

		mount(PullToRefresh)

		expect(added.map(([type]) => type)).toEqual(['touchstart', 'touchmove', 'touchend', 'touchcancel'])
		expect(added.every(([, options]) => options?.passive === true)).toBe(true)
	})

	it('keeps a row open while the refresh runs, and stops listening once gone', async () => {
		const wrapper = mount(PullToRefresh, { props: { refreshing: true } })
		expect(wrapper.find('.pull-refresh').classes()).toContain('pull-refresh--refreshing')
		expect(wrapper.find('.pull-refresh').attributes('style')).toContain('--pull-height: 48px')
		expect(wrapper.find('.pull-refresh').attributes('aria-hidden')).toBe('true')

		wrapper.unmount()
		const after = mount(PullToRefresh)
		after.unmount()
		touch(column, 'touchstart', 100)
		touch(column, 'touchmove', 400)
		touch(column, 'touchend')
		expect(after.emitted('refresh')).toBeUndefined()
	})

	it('holds the flower still for a reader who asked for less motion', () => {
		const source = readFileSync(resolve(process.cwd(), 'src/components/PullToRefresh.vue'), 'utf8')
		const reduced = source.slice(source.indexOf('@media (prefers-reduced-motion: reduce)'))

		expect(reduced).toMatch(/\.pull-refresh--refreshing \.pull-refresh__flower \{\s*animation: none;/)
		expect(reduced).toMatch(/transition: none;/)
	})
})
