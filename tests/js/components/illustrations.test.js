/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import NoMessages from '../../../src/components/illustrations/NoMessages.vue'
import NoReplies from '../../../src/components/illustrations/NoReplies.vue'
import NobodyYet from '../../../src/components/illustrations/NobodyYet.vue'
import QuietTimeline from '../../../src/components/illustrations/QuietTimeline.vue'
import AlohaScene, { SCENES } from '../../../src/components/illustrations/AlohaScene.vue'

const ILLUSTRATIONS = [
	['NoMessages', NoMessages],
	['NoReplies', NoReplies],
	['NobodyYet', NobodyYet],
	['QuietTimeline', QuietTimeline],
]

/**
 * The drawings above an empty page. They are decoration beside a heading that
 * already says what is going on, so the rules that apply to all four are the
 * same rules: they are drawn rather than fetched, they follow the theme instead
 * of being corrected by a filter, and they are never read aloud.
 */
describe.each(ILLUSTRATIONS)('the %s illustration', (name, component) => {
	it('is drawn, not fetched', () => {
		const wrapper = mount(component)

		expect(wrapper.find('svg').exists()).toBe(true)
		expect(wrapper.find('img').exists()).toBe(false)
	})

	/**
	 * A heading and a sentence sit right beside it saying the same thing, so
	 * announcing the picture as well only repeats them.
	 */
	it('is not announced, and cannot be tabbed to', () => {
		const svg = mount(component).find('svg')

		expect(svg.attributes('aria-hidden')).toBe('true')
		expect(svg.attributes('focusable')).toBe('false')
	})

	/**
	 * A hard-coded colour is the wrong one in a dark theme and unreadable in a
	 * high-contrast one; `currentColor` is whatever the text beside it is.
	 */
	it('follows the theme rather than naming its own colours', () => {
		const html = mount(component).html()

		expect(html).not.toMatch(/(stroke|fill)="#[0-9a-f]{3,8}"/i)
		expect(html).not.toMatch(/(stroke|fill)="rgb/i)
		expect(html).toContain('currentColor')
	})

	/** It has to scale with the text around it rather than to the viewport. */
	it('has a viewBox and a size to draw at', () => {
		const svg = mount(component).find('svg')

		expect(svg.attributes('viewBox')).toBeTruthy()
		expect(svg.attributes('width')).toBeTruthy()
		expect(svg.attributes('height')).toBeTruthy()
	})
})

/**
 * The full-size scenes are in colour, but the colours are mixed with the
 * page's own background in the stylesheet, never written into the markup, so a
 * dark theme gets a dimmed sunset rather than a glaring one.
 */
describe.each(SCENES)('the %s scene', (scene) => {
	const mountScene = () => mount(AlohaScene, { props: { scene } })

	it('is drawn, not fetched, and is not announced', () => {
		const svg = mountScene().find('svg')

		expect(svg.exists()).toBe(true)
		expect(svg.attributes('aria-hidden')).toBe('true')
		expect(svg.attributes('focusable')).toBe('false')
		expect(svg.classes()).toContain(`aloha-scene--${scene}`)
	})

	it('names no colours of its own in the markup', () => {
		const html = mountScene().html()

		expect(html).not.toMatch(/(stroke|fill)="#[0-9a-f]{3,8}"/i)
		expect(html).not.toMatch(/(stroke|fill)="rgb/i)
	})

	it('draws inside its round window', () => {
		const wrapper = mountScene()
		const clip = wrapper.find('clipPath')

		expect(clip.exists()).toBe(true)
		expect(wrapper.find('g[clip-path]').attributes('clip-path')).toBe(`url(#${clip.attributes('id')})`)
		// more than the window itself: every scene draws something in it
		expect(wrapper.findAll('g[clip-path] > *').length).toBeGreaterThan(2)
	})
})

describe('AlohaScene', () => {
	it('gives every drawing on a page its own window, so two scenes do not share a clip', () => {
		const first = mount(AlohaScene, { props: { scene: 'tide' } }).find('clipPath').attributes('id')
		const second = mount(AlohaScene, { props: { scene: 'tide' } }).find('clipPath').attributes('id')

		expect(first).not.toBe(second)
	})

	it('draws the five petals of the plumeria and the eight steps up the beach', () => {
		expect(mount(AlohaScene, { props: { scene: 'plumeria' } }).findAll('.petal')).toHaveLength(5)
		expect(mount(AlohaScene, { props: { scene: 'tracks' } }).findAll('.step')).toHaveLength(8)
	})
})
