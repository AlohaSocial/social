/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import InfoCard from '../../../src/components/InfoCard.vue'

const { reader } = vi.hoisted(() => ({ reader: { uid: 'alice' } }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => reader }))

function card(dismissKey = 'feed-test') {
	return mount(InfoCard, {
		props: { dismissKey, title: 'About this feed' },
		slots: { default: '<p>Explanatory text</p>' },
	})
}

beforeEach(() => {
	reader.uid = 'alice'
	localStorage.clear()
})
afterEach(() => vi.restoreAllMocks())

describe('optional information cards', () => {
	it('shows a named card and persists dismissal for the current reader', async () => {
		const wrapper = card()
		expect(wrapper.find('section').attributes('aria-label')).toBe('About this feed')
		expect(wrapper.text()).toContain('Explanatory text')
		await wrapper.find('button').trigger('click')
		expect(wrapper.find('section').exists()).toBe(false)
		expect(card().find('section').exists()).toBe(false)
		reader.uid = 'bob'
		expect(card().find('section').exists()).toBe(true)
	})

	it('keeps different card contexts independent when a route reuses the component', async () => {
		const wrapper = card()
		await wrapper.find('button').trigger('click')
		await wrapper.setProps({ dismissKey: 'other-feed' })
		expect(wrapper.find('section').exists()).toBe(true)
		await wrapper.setProps({ dismissKey: 'feed-test' })
		expect(wrapper.find('section').exists()).toBe(false)
	})

	it('still closes and remembers the choice in memory when storage is blocked', async () => {
		vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => {
			throw new Error('Storage unavailable')
		})
		vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
			throw new Error('Storage unavailable')
		})
		const wrapper = card('private-window')
		await wrapper.find('button').trigger('click')
		expect(wrapper.find('section').exists()).toBe(false)
		expect(card('private-window').find('section').exists()).toBe(false)
	})
})
