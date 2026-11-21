/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import AltBadge from '../../../src/components/AltBadge.vue'

/**
 * @param {string} description what the picture is
 * @return {object} the mounted badge
 */
function mountBadge(description = 'A cat asleep on a keyboard') {
	return mount(AltBadge, { props: { description } })
}

describe('the ALT badge', () => {
	it('starts closed, and says so', () => {
		// in the document, where jsdom computes the style that hides it
		const wrapper = mount(AltBadge, { props: { description: 'A cat asleep on a keyboard' }, attachTo: document.body })

		expect(wrapper.find('.alt-badge').attributes('aria-expanded')).toBe('false')
		expect(wrapper.find('.alt-description').isVisible()).toBe(false)
		wrapper.unmount()
	})

	it('reveals the description when pressed', async () => {
		const wrapper = mountBadge()

		await wrapper.find('.alt-badge').trigger('click')

		expect(wrapper.find('.alt-badge').attributes('aria-expanded')).toBe('true')
		expect(wrapper.find('.alt-description').isVisible()).toBe(true)
		expect(wrapper.find('.alt-description').text()).toBe('A cat asleep on a keyboard')
	})

	it('closes again when pressed a second time', async () => {
		const wrapper = mountBadge()

		await wrapper.find('.alt-badge').trigger('click')
		await wrapper.find('.alt-badge').trigger('click')

		expect(wrapper.find('.alt-badge').attributes('aria-expanded')).toBe('false')
	})

	/** The badge sits on a picture that is itself a link or a lightbox. */
	it('does not let the click reach the picture behind it', async () => {
		const wrapper = mount({
			components: { AltBadge },
			data: () => ({ opened: 0 }),
			template: '<div @click="opened++"><AltBadge description="x" /></div>',
		})

		await wrapper.find('.alt-badge').trigger('click')

		expect(wrapper.vm.opened).toBe(0)
	})

	/** One post may show eight pictures, and an id has to be unique per page. */
	it('gives each badge its own id, and points the button at it', () => {
		const first = mountBadge()
		const second = mountBadge()

		const id = first.find('.alt-description').attributes('id')
		expect(first.find('.alt-badge').attributes('aria-controls')).toBe(id)
		expect(second.find('.alt-description').attributes('id')).not.toBe(id)
	})

	/**
	 * A different picture in the same frame is a different description, and it
	 * should not arrive already open.
	 */
	it('closes when the picture it describes changes', async () => {
		const wrapper = mountBadge()
		await wrapper.find('.alt-badge').trigger('click')

		await wrapper.setProps({ description: 'A different picture' })

		expect(wrapper.find('.alt-badge').attributes('aria-expanded')).toBe('false')
		expect(wrapper.find('.alt-description').text()).toBe('A different picture')
	})
})
