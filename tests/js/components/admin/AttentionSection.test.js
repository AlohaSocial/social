/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AttentionSection from '../../../../src/components/admin/AttentionSection.vue'

describe('the list of what needs attention', () => {
	it('says when nothing is waiting', () => {
		const wrapper = mount(AttentionSection, { props: { items: [] } })

		expect(wrapper.text()).toContain('Nothing is waiting for you.')
		expect(wrapper.find('.attention').exists()).toBe(false)
	})

	it('draws each item as a link to its section, marked by how urgent it is', () => {
		const wrapper = mount(AttentionSection, {
			props: {
				items: [
					{ section: 'background', count: 1, type: 'error', text: 'background job has not run when it should have' },
					{ section: 'reports', count: 3, type: 'warning', text: 'open reports' },
				],
			},
		})
		const items = wrapper.findAll('.attention__item')

		expect(items.map((item) => item.attributes('href'))).toEqual(['#background', '#reports'])
		expect(items[0].classes()).toContain('attention__item--error')
		expect(items[1].text()).toContain('3')
		expect(items[1].text()).toContain('open reports')
	})

	it('asks the page to open the section rather than following the link', async () => {
		const wrapper = mount(AttentionSection, {
			props: { items: [{ section: 'reports', count: 3, type: 'warning', text: 'open reports' }] },
		})
		await wrapper.find('.attention__item').trigger('click')

		expect(wrapper.emitted('go')).toEqual([['reports']])
	})
})
