/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/interests.js', () => ({
	fetchInterests: vi.fn(),
	saveInterestSettings: vi.fn(),
	resetInterests: vi.fn(),
}))

import axios from '@nextcloud/axios'
import ForYouSettings from '../../../src/components/ForYouSettings.vue'
import { fetchInterests, resetInterests, saveInterestSettings } from '../../../src/services/interests.js'
import { useSettingsStore } from '../../../src/store/settings.js'

const LEARNING = '/index.php/apps/social/api/v1/recommendations/learning'

/**
 * @param {object} interests the interest state the server answers with
 * @param {string} activity the activity learning mode
 * @return {Promise<object>} the mounted section
 */
async function mountSection(interests = { learning: true, paused: false, languages: [] }, activity = 'off') {
	useSettingsStore().setServerData({ interests: { enabled: true, recommendationsEnabled: true } })
	fetchInterests.mockResolvedValue({ settings: interests })
	saveInterestSettings.mockImplementation(async (changes) => ({ settings: { ...interests, ...changes } }))
	axios.get.mockResolvedValue({ data: { revision: 3, learning_mode: activity } })
	axios.put.mockImplementation(async (url, { mode }) => ({ data: { revision: 4, learning_mode: mode } }))
	const wrapper = mount(ForYouSettings, { global: { stubs: { NcSelect: true, NcDialog: true } } })
	await flushPromises()
	return wrapper
}

function radio(wrapper, value) {
	return wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		.find((one) => one.props('type') === 'radio' && one.props('value') === value)
}
function activitySwitch(wrapper) {
	return wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		.find((one) => one.props('type') === 'switch')
}

describe('the For you settings', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
	})

	it('is one choice of On, Paused or Off, read from the server', async () => {
		const wrapper = await mountSection({ learning: true, paused: true, languages: [] })

		expect(radio(wrapper, 'paused').props('modelValue')).toBe('paused')
		expect(wrapper.text()).toContain('learns nothing new')
	})

	it('turns learning off everywhere at once', async () => {
		const wrapper = await mountSection(undefined, 'on')

		radio(wrapper, 'off').vm.$emit('update:modelValue', 'off')
		await flushPromises()

		expect(saveInterestSettings).toHaveBeenCalledWith({ learning: false, paused: false })
		expect(axios.put).toHaveBeenCalledWith(LEARNING, { if_revision: 3, mode: 'off' })
		expect(activitySwitch(wrapper).props('disabled')).toBe(true)
	})

	it('pauses the activity learning with the rest, when it is on', async () => {
		const wrapper = await mountSection(undefined, 'on')

		radio(wrapper, 'paused').vm.$emit('update:modelValue', 'paused')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(LEARNING, { if_revision: 3, mode: 'paused' })
	})

	it('learns from likes, saves and watching only when asked to', async () => {
		const wrapper = await mountSection()
		expect(activitySwitch(wrapper).props('modelValue')).toBe(false)

		activitySwitch(wrapper).vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(LEARNING, { if_revision: 3, mode: 'on' })
		expect(activitySwitch(wrapper).props('modelValue')).toBe(true)
	})

	it('clears both kinds of learning with one button', async () => {
		resetInterests.mockResolvedValue({ settings: { learning: true, paused: false, languages: [] } })
		axios.post.mockResolvedValue({ data: { revision: 5, learning_mode: 'off' } })
		const wrapper = await mountSection()

		await wrapper.vm.reset()

		expect(resetInterests).toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/recommendations/reset', { if_revision: 3, scope: 'learning' })
	})
})
