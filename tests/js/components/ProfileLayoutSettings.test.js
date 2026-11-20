/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import ProfileLayoutSettings from '../../../src/components/ProfileLayoutSettings.vue'
import { useAccountStore } from '../../../src/store/account.js'

vi.mock('@nextcloud/axios', () => ({ default: { put: vi.fn() } }))

function setup(layout) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useAccountStore()
	store.setOwnAccount({
		id: '1',
		acct: 'alice',
		username: 'alice',
		url: 'https://cloud.example.org/@alice',
		profile_layout: layout,
	})
	return { store, wrapper: mount(ProfileLayoutSettings, { global: { plugins: [pinia] } }) }
}

describe('public profile layout', () => {
	beforeEach(() => vi.clearAllMocks())
	it('preserves existing defaults and resets a hidden default to Posts', async () => {
		const { wrapper } = setup()
		expect(wrapper.get('select').element.value).toBe('posts')
		await wrapper.get('select').setValue('photos')
		wrapper.vm.toggleTab('photos', false)
		await flushPromises()
		expect(wrapper.get('select').element.value).toBe('posts')
	})
	it('saves a change as soon as it is made, and confirms once the server has it', async () => {
		const layout = { default_tab: 'photos', tabs: ['posts', 'photos'] }
		axios.put.mockResolvedValue({ data: { status: 1, result: { layout } } })
		const { store, wrapper } = setup(layout)
		await wrapper.get('select').trigger('change')
		await flushPromises()
		expect(axios.put).toHaveBeenCalledWith(expect.stringContaining('/account/profile-layout'), { layout })
		expect(store.currentAccount.profile_layout).toEqual(layout)
		expect(wrapper.get('[role="status"]').text()).toContain('saved')
	})
	it('keeps an unsuccessful change retryable without a success announcement', async () => {
		axios.put.mockRejectedValue(new Error('offline'))
		const { wrapper } = setup()
		await wrapper.get('select').trigger('change')
		await flushPromises()
		expect(wrapper.get('[role="alert"]').text()).toBeTruthy()
		expect(wrapper.find('[role="status"]').exists()).toBe(false)
		expect(wrapper.get('select').attributes('disabled')).toBeUndefined()
		expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
	})
})
