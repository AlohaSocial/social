/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ContactSettings from '../../../src/components/ContactSettings.vue'
import { useAccountStore } from '../../../src/store/account.js'

/** @return {Promise<object>} the section, with the account read */
async function mountSection() {
	const store = useAccountStore()
	store.credentials = { id: '1', acct: 'alice', locked: false }
	store.updateCredentials = vi.fn().mockResolvedValue(true)
	const wrapper = mount(ContactSettings, {
		global: { stubs: { DirectMessagesSettings: true, NotificationPolicySettings: true } },
	})
	await flushPromises()
	return { wrapper, store }
}

describe('who can contact you', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('holds approving followers, direct messages and who may reach Activities together', async () => {
		const { wrapper } = await mountSection()

		expect(wrapper.text()).toContain('Approve who follows you')
		expect(wrapper.find('#direct-messages').exists()).toBe(true)
		expect(wrapper.find('#notification-policy').exists()).toBe(true)
	})

	it('saves the approval switch as soon as it is flipped', async () => {
		const { wrapper, store } = await mountSection()

		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(store.updateCredentials).toHaveBeenCalledWith({ locked: true })
	})
})
