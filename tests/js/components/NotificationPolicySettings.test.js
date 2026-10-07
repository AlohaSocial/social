/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'
import NotificationPolicySettings from '../../../src/components/NotificationPolicySettings.vue'
import { useNotificationsStore } from '../../../src/store/notifications.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), patch: vi.fn(), delete: vi.fn(), post: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), error: vi.fn() } }))

const API = '/index.php/apps/social/api/v2/notifications/policy'
const ALLOWED = '/index.php/apps/social/api/v1/social/notifications/allowed'
const open = {
	for_not_following: 'accept',
	for_not_followers: 'accept',
	for_new_accounts: 'accept',
	for_private_mentions: 'accept',
	for_limited_accounts: 'accept',
	summary: { pending_requests_count: 0, pending_notifications_count: 0 },
}
const alice = { id: '11', acct: 'alice@remote.example', display_name: 'Alice', username: 'alice' }
const bob = { id: '12', acct: 'bob@remote.example', display_name: 'Bob', username: 'bob' }

const stubs = {
	ActorAvatar: { name: 'ActorAvatar', props: ['actor', 'size'], template: '<span class="avatar-stub" />' },
	RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}

/**
 * @param {object} answer what the server says the policy is
 * @param {object[]} allowed the always-allowed accounts
 * @return {Promise<object>} the mounted part
 */
async function mountSettings(answer = open, allowed = []) {
	axios.get.mockImplementation((url) => Promise.resolve({ data: url === ALLOWED ? allowed : answer }))
	let current = { ...answer }
	axios.patch.mockImplementation((url, changes) => {
		current = { ...current, ...changes }
		return Promise.resolve({ data: current })
	})
	const wrapper = mount(NotificationPolicySettings, { global: { stubs } })
	await flushPromises()

	return wrapper
}

const radios = (wrapper) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
const radio = (wrapper, key, value) => radios(wrapper).find((control) => control.props('name') === 'social-policy-' + key && control.props('value') === value)
const row = (wrapper, label) => wrapper.findAll('fieldset').find((one) => one.find('legend').text() === label)

describe('NotificationPolicySettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		setActivePinia(createPinia())
	})

	it('shows the five rows where they stand, each Allow or Hold for review', async () => {
		const wrapper = await mountSettings({ ...open, for_new_accounts: 'filter' })

		expect(axios.get).toHaveBeenCalledWith(API)
		expect(wrapper.find('h4').text()).toBe('Who may reach you')
		expect(wrapper.findAll('legend').map((legend) => legend.text())).toEqual([
			'People you don\'t follow',
			'People who don\'t follow you',
			'New accounts (less than 30 days old)',
			'Private mentions you didn\'t ask for',
			'Accounts this server limited',
		])
		for (const fieldset of wrapper.findAll('fieldset')) {
			expect(fieldset.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).map((one) => one.text())).toEqual(['Allow', 'Hold for review'])
		}
		expect(radio(wrapper, 'for_new_accounts', 'filter').props('modelValue')).toBe('filter')
		expect(radio(wrapper, 'for_not_following', 'accept').props('modelValue')).toBe('accept')
	})

	it('says what held means', async () => {
		const wrapper = await mountSettings()

		expect(wrapper.text()).toContain('Held people can still write to you. Their posts, likes and follows wait in Activities until you look; they do not ring, push or mail.')
	})

	it('saves a choice as it is made, naming only that key', async () => {
		const wrapper = await mountSettings()

		radio(wrapper, 'for_not_followers', 'filter').vm.$emit('update:modelValue', 'filter')
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledTimes(1)
		expect(axios.patch).toHaveBeenCalledWith(API, { for_not_followers: 'filter' })
		expect(radio(wrapper, 'for_not_followers', 'filter').props('modelValue')).toBe('filter')
	})

	it('saves nothing for the choice that is already made', async () => {
		const wrapper = await mountSettings()

		radio(wrapper, 'for_not_following', 'accept').vm.$emit('update:modelValue', 'accept')
		await flushPromises()

		expect(axios.patch).not.toHaveBeenCalled()
	})

	it('puts the choice back with the server\'s reason when it is refused', async () => {
		const wrapper = await mountSettings()
		axios.patch.mockRejectedValue({ response: { data: { error: 'not now' } } })

		radio(wrapper, 'for_private_mentions', 'filter').vm.$emit('update:modelValue', 'filter')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('not now')
		expect(radio(wrapper, 'for_private_mentions', 'accept').props('modelValue')).toBe('accept')
	})

	describe('a key an app set to drop', () => {
		it('is shown as Hold for review, with a note', async () => {
			const wrapper = await mountSettings({ ...open, for_new_accounts: 'drop' })

			expect(radio(wrapper, 'for_new_accounts', 'filter').props('modelValue')).toBe('filter')
			expect(row(wrapper, 'New accounts (less than 30 days old)').text()).toContain('An app set this to discard; choosing here replaces it')
			expect(row(wrapper, 'People you don\'t follow').text()).not.toContain('An app set this to discard')
		})

		it('is replaced by Hold for review when that is clicked, and the note goes', async () => {
			const wrapper = await mountSettings({ ...open, for_new_accounts: 'drop' })

			await radio(wrapper, 'for_new_accounts', 'filter').find('input').trigger('click')
			await flushPromises()

			expect(axios.patch).toHaveBeenCalledTimes(1)
			expect(axios.patch).toHaveBeenCalledWith(API, { for_new_accounts: 'filter' })
			expect(wrapper.text()).not.toContain('An app set this to discard')
		})

		it('is replaced by Allow when that is chosen', async () => {
			const wrapper = await mountSettings({ ...open, for_new_accounts: 'drop' })

			radio(wrapper, 'for_new_accounts', 'accept').vm.$emit('update:modelValue', 'accept')
			await flushPromises()

			expect(axios.patch).toHaveBeenCalledWith(API, { for_new_accounts: 'accept' })
		})

		it('is never sent from here', async () => {
			const wrapper = await mountSettings()

			radio(wrapper, 'for_new_accounts', 'filter').vm.$emit('update:modelValue', 'drop')
			await flushPromises()

			expect(axios.patch).not.toHaveBeenCalled()
		})
	})

	it('puts the one-time notice away after a save', async () => {
		const wrapper = await mountSettings()
		const store = useNotificationsStore()
		store.setPolicyNotice(true)

		radio(wrapper, 'for_not_following', 'filter').vm.$emit('update:modelValue', 'filter')
		await flushPromises()

		expect(store.policyNotice).toBe(false)
	})

	describe('always allowed', () => {
		it('lists the people accepted from requests', async () => {
			const wrapper = await mountSettings(open, [alice, bob])

			expect(axios.get).toHaveBeenCalledWith(ALLOWED)
			expect(wrapper.find('h5').text()).toBe('Always allowed')
			const people = wrapper.findAll('.policy-settings__person')
			expect(people.map((one) => one.find('.policy-settings__acct').text())).toEqual(['alice@remote.example', 'bob@remote.example'])
			expect(people[0].findComponent({ name: 'NcButton' }).text()).toBe('Stop allowing')
		})

		it('says so when there is nobody', async () => {
			const wrapper = await mountSettings(open, [])

			expect(wrapper.find('.policy-settings__empty').text()).toBe('Nobody yet. People you accept from your requests appear here.')
		})

		it('stops allowing one person, who leaves the list', async () => {
			const wrapper = await mountSettings(open, [alice, bob])
			axios.delete.mockResolvedValue({ data: {} })

			await wrapper.findAll('.policy-settings__person')[0].findComponent({ name: 'NcButton' }).trigger('click')
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(`${ALLOWED}/11`)
			expect(wrapper.findAll('.policy-settings__acct').map((one) => one.text())).toEqual(['bob@remote.example'])
		})

		it('keeps the person and says why when the server refuses', async () => {
			const wrapper = await mountSettings(open, [alice])
			axios.delete.mockRejectedValue({ response: { data: { error: 'gone wrong' } } })

			await wrapper.find('.policy-settings__person').findComponent({ name: 'NcButton' }).trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('gone wrong')
			expect(wrapper.findAll('.policy-settings__person')).toHaveLength(1)
		})
	})
})
