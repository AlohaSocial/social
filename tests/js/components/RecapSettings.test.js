/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import { createPinia, setActivePinia } from 'pinia'
import RecapSettings from '../../../src/components/RecapSettings.vue'
import { useSettingsStore } from '../../../src/store/settings.js'
import { showError } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const ROUTE = '/index.php/apps/social/api/v1/memories/recap'

/**
 * @param {object|Error} answer what the read answers with, or throws
 * @return {Promise<object>} the mounted switch, once the read has settled
 */
async function mountSettings(answer = { enabled: false }) {
	axios.get.mockImplementation(() => (answer instanceof Error
		? Promise.reject(answer)
		: Promise.resolve({ data: answer })))
	axios.post.mockResolvedValue({ data: {} })
	setActivePinia(createPinia())
	useSettingsStore().setServerData({ public: false, startup: { recap_enabled: answer?.enabled === true } })

	const wrapper = mount(RecapSettings)
	await flushPromises()

	return wrapper
}

const toggle = (wrapper) => wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })

describe('the weekly recap switch', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('starts off where the server says it is', async () => {
		expect(toggle(await mountSettings({ enabled: true })).props('modelValue')).toBe(true)
		expect(toggle(await mountSettings({ enabled: false })).props('modelValue')).toBe(false)
	})

	/** The recap is opt-in, so anything other than a plain yes is off. */
	it('is off for an answer that does not say yes', async () => {
		expect(toggle(await mountSettings({})).props('modelValue')).toBe(false)
		expect(toggle(await mountSettings({ enabled: 'yes' })).props('modelValue')).toBe(false)
	})

	it('cannot be moved until it knows where it stands', async () => {
		axios.get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(RecapSettings)

		expect(toggle(wrapper).props('disabled')).toBe(true)
	})

	it('can be moved once it does', async () => {
		expect(toggle(await mountSettings()).props('disabled')).toBe(false)
	})

	/** Nobody else sees it, it is weekly, and there is no streak to keep up. */
	it('says what turning it on will actually do', async () => {
		const lede = (await mountSettings()).find('.recap-settings__lede').text()

		expect(lede).toContain('Nobody else sees it')
		expect(lede).toContain('no streak to keep up')
	})

	it('saves the new setting when it is moved', async () => {
		const wrapper = await mountSettings()

		toggle(wrapper).vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(ROUTE, { enabled: true })
		expect(toggle(wrapper).props('modelValue')).toBe(true)
		expect(useSettingsStore().getServerData.startup.recap_enabled).toBe(true)
	})

	/** What the page says about the recap follows the switch. */
	it('tells the page when the recap is turned off', async () => {
		const wrapper = await mountSettings({ enabled: true })

		toggle(wrapper).vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(useSettingsStore().getServerData.startup.recap_enabled).toBe(false)
	})

	/** A switch that waits for a round trip before it moves reads as broken. */
	it('moves at once rather than on the way back', async () => {
		const wrapper = await mountSettings()
		axios.post.mockReturnValue(new Promise(() => {}))

		toggle(wrapper).vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		expect(toggle(wrapper).props('modelValue')).toBe(true)
	})

	/** And moves back if the save did not take, rather than lying about it. */
	it('goes back where it was when the save fails, and says so', async () => {
		const wrapper = await mountSettings({ enabled: true })
		axios.post.mockRejectedValue(new Error('nope'))

		toggle(wrapper).vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(toggle(wrapper).props('modelValue')).toBe(true)
		expect(showError).toHaveBeenCalledWith('Could not save that setting')
	})

	/**
	 * An unreadable setting is not worth a toast on a settings page the viewer
	 * may only be passing through; the switch shows off and still works.
	 */
	it('stays usable when it could not be read at all', async () => {
		const wrapper = await mountSettings(new Error('offline'))

		expect(toggle(wrapper).props('modelValue')).toBe(false)
		expect(toggle(wrapper).props('disabled')).toBe(false)
		expect(showError).not.toHaveBeenCalled()
	})
})
