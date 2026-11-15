/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PageHeader from '../../../src/components/PageHeader.vue'

describe('PageHeader', () => {
	it('takes no room when it holds nothing but a title for screen readers', () => {
		const wrapper = mount(PageHeader, { props: { title: 'Discover', hideTitle: true } })

		expect(wrapper.classes()).toContain('page-header--bare')
		expect(wrapper.find('h1').classes()).toContain('hidden-visually')
	})

	it.each([
		['a visible title', { title: 'About' }, {}],
		['a description', { title: 'About', hideTitle: true, description: 'Rules' }, {}],
		['actions', { title: 'Photos', hideTitle: true }, { actions: '<button>Upload</button>' }],
	])('keeps its room with %s', (what, props, slots) => {
		expect(mount(PageHeader, { props, slots }).classes()).not.toContain('page-header--bare')
	})
})
