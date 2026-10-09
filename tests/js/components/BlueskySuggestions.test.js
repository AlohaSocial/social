/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BlueskySuggestions from '../../../src/components/BlueskySuggestions.vue'
import { useAccountStore } from '../../../src/store/account.js'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))

const PersonCard = { name: 'PersonCard', props: ['account', 'link', 'followed', 'pending'], emits: ['follow'], template: '<li class="person" @click="$emit(\'follow\', account)">{{ account.acct }}</li>' }

describe('BlueskySuggestions', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('lists whom Bluesky suggests and follows them here by their handle', async () => {
		get.mockResolvedValue({ data: { accounts: [{ id: 'did:plc:a', acct: 'ann.test', display_name: 'Ann' }] } })
		const store = useAccountStore()
		store.followAccount = vi.fn().mockResolvedValue(true)

		const wrapper = mount(BlueskySuggestions, { global: { stubs: { PersonCard } } })
		await flushPromises()
		await wrapper.find('.person').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/social/bluesky/suggestions'))
		expect(store.followAccount).toHaveBeenCalledWith({ accountToFollow: 'ann.test' })
		expect(wrapper.findComponent(PersonCard).props('followed')).toBe(true)
	})

	it('says so when there is nobody to suggest', async () => {
		get.mockResolvedValue({ data: { accounts: [] } })

		const wrapper = mount(BlueskySuggestions, { global: { stubs: { PersonCard } } })
		await flushPromises()

		expect(wrapper.find('.bluesky-suggestions__empty').exists()).toBe(true)
	})
})
