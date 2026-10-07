/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'
import NotificationPolicySettings from '../../../src/components/NotificationPolicySettings.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), patch: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), error: vi.fn() } }))

const API = '/index.php/apps/social/api/v2/notifications/policy'
const open = {
	for_not_following: 'accept',
	for_not_followers: 'accept',
	for_new_accounts: 'accept',
	for_private_mentions: 'accept',
	for_limited_accounts: 'accept',
	summary: { pending_requests_count: 0, pending_notifications_count: 0 },
}

/**
 * @param {object} answer what the server says the policy is
 * @return {Promise<object>} the mounted section
 */
async function mountSettings(answer = open) {
	axios.get.mockResolvedValue({ data: answer })
	let current = { ...answer }
	axios.patch.mockImplementation((url, changes) => {
		current = { ...current, ...changes }
		return Promise.resolve({ data: current })
	})
	const wrapper = mount(NotificationPolicySettings)
	await flushPromises()

	return wrapper
}

const radios = (wrapper) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
const radio = (wrapper, key, value) => radios(wrapper).find((control) => control.props('name') === 'social-policy-' + key && control.props('value') === value)

describe('NotificationPolicySettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('asks the server for the policy and shows the five questions where they stand', async () => {
		const wrapper = await mountSettings({ ...open, for_new_accounts: 'filter' })

		expect(axios.get).toHaveBeenCalledWith(API)
		expect(wrapper.findAll('legend').map((legend) => legend.text())).toEqual([
			'People you do not follow',
			'People who do not follow you',
			'Accounts less than a month old',
			'Private mentions you did not start',
			'Accounts a moderator has limited',
		])
		expect(radio(wrapper, 'for_new_accounts', 'filter').props('modelValue')).toBe('filter')
		expect(radio(wrapper, 'for_not_following', 'accept').props('modelValue')).toBe('accept')
	})

	it('saves a decision as it is made, naming only that question', async () => {
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

	it('puts the choice back and says so when the server refuses', async () => {
		const wrapper = await mountSettings()
		axios.patch.mockRejectedValue({ response: { data: { error: 'not now' } } })

		radio(wrapper, 'for_private_mentions', 'drop').vm.$emit('update:modelValue', 'drop')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('not now')
		expect(radio(wrapper, 'for_private_mentions', 'drop').props('modelValue')).toBe('accept')
	})

	it('reads an unknown decision as accepting, which is what every account had before the policy existed', async () => {
		const wrapper = await mountSettings({ ...open, for_limited_accounts: 'something-new' })

		expect(radio(wrapper, 'for_limited_accounts', 'accept').props('modelValue')).toBe('accept')
	})
})
