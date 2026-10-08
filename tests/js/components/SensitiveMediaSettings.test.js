/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import SensitiveMediaSettings from '../../../src/components/SensitiveMediaSettings.vue'
import { showError, showSuccess } from '../../../src/services/toast.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { put: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const API = '/index.php/apps/social/api/v1/preferences'

/**
 * @param {string} choice what the account chose, as the page carries it
 * @return {object} the mounted setting
 */
function mountSetting(choice = '') {
	setActivePinia(createPinia())
	useSettingsStore().setServerData({ nsfwChoice: choice })
	return mount(SensitiveMediaSettings)
}

const select = (wrapper) => wrapper.findComponent({ name: 'NcSelect' })

describe('SensitiveMediaSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('shows what the account chose, and following the server when it chose nothing', () => {
		expect(select(mountSetting('hide_all')).props('modelValue').id).toBe('hide_all')
		expect(select(mountSetting('')).props('modelValue').text).toBe('Whatever this server does')
	})

	it('saves a choice as soon as it is made, and says when it applies', async () => {
		const wrapper = mountSetting('')
		axios.put.mockResolvedValue({ data: {} })

		select(wrapper).vm.$emit('update:modelValue', { id: 'show_all', text: 'Show it like anything else' })
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(API, { expandMedia: 'show_all' })
		expect(showSuccess).toHaveBeenCalledWith('Saved. It applies the next time this page loads.')
	})

	it('says so when the server refuses', async () => {
		const wrapper = mountSetting('')
		axios.put.mockRejectedValue(new Error('no'))

		select(wrapper).vm.$emit('update:modelValue', { id: 'default', text: 'Cover it, one press away' })
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not save that setting')
	})
})
