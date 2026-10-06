/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'
import CountsSettings from '../../../src/components/CountsSettings.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({ default: { patch: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({ default: { error: vi.fn() } }))

const API = '/index.php/apps/social/api/v1/social/counts'

/**
 * @param {boolean} hidden what the page says
 * @return {object} the mounted section
 */
function mountSettings(hidden = true) {
	setActivePinia(createPinia())
	useSettingsStore().setServerData({ hideCounts: hidden })

	return mount(CountsSettings)
}

const toggle = (wrapper) => wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })

describe('CountsSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('starts off — the numbers hidden — and says what that means', () => {
		const wrapper = mountSettings()

		expect(toggle(wrapper).props('modelValue')).toBe(false)
		expect(wrapper.text()).toContain('Show the numbers')
		expect(wrapper.text()).toContain('Hidden')
	})

	it('shows the numbers when turned on, saves it, and the whole page follows at once', async () => {
		axios.patch.mockResolvedValue({ data: { hide: false } })
		const wrapper = mountSettings()

		toggle(wrapper).vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith(API, { hide: false })
		expect(useSettingsStore().hidesCounts).toBe(false)
		expect(toggle(wrapper).props('modelValue')).toBe(true)
	})

	it('puts the switch back and says so when the server refuses', async () => {
		axios.patch.mockRejectedValue(new Error('nope'))
		const wrapper = mountSettings()

		toggle(wrapper).vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not save that setting')
		expect(useSettingsStore().hidesCounts).toBe(true)
	})
})
