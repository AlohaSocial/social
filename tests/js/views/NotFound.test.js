/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NotFound from '../../../src/views/NotFound.vue'

const NcButtonStub = { props: ['to'], template: '<a class="nc-button-stub" :data-to="JSON.stringify(to)"><slot /></a>' }

describe('the page for an address that names nothing', () => {
	it('says so, and offers the way home', () => {
		const wrapper = mount(NotFound, { global: { stubs: { NcButton: NcButtonStub } } })

		expect(wrapper.text()).toContain('Page not found')
		expect(wrapper.text()).toContain('There is nothing at this address')
		const home = wrapper.find('.nc-button-stub')
		expect(home.text()).toBe('Back to my feed')
		expect(JSON.parse(home.attributes('data-to'))).toEqual({ name: 'timeline' })
	})

	it('sends a visitor, who has no feed of their own, to the public one', () => {
		globalThis.setInitialState('social', 'serverData', { public: true })
		const wrapper = mount(NotFound, { global: { stubs: { NcButton: NcButtonStub } } })

		const home = wrapper.find('.nc-button-stub')
		expect(home.text()).toBe('Read the public feed')
		expect(JSON.parse(home.attributes('data-to'))).toEqual({ name: 'timeline', params: { type: 'timeline' } })
		document.getElementById('initial-state-social-serverData')?.remove()
	})
})
