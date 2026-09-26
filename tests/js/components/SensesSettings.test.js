/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SensesSettings from '../../../src/components/SensesSettings.vue'

const senses = vi.hoisted(() => ({
	sounds: false,
	vibration: true,
	play: vi.fn(),
	buzz: vi.fn(),
}))
vi.mock('../../../src/services/senses.js', () => ({
	soundsEnabled: () => senses.sounds,
	vibrationEnabled: () => senses.vibration,
	setSoundsEnabled: (on) => { senses.sounds = on },
	setVibrationEnabled: (on) => { senses.vibration = on },
	play: senses.play,
	buzz: senses.buzz,
}))

const switches = (wrapper) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })

describe('SensesSettings', () => {
	beforeEach(() => {
		senses.sounds = false
		senses.vibration = true
		senses.play.mockReset()
		senses.buzz.mockReset()
	})

	it('shows the switches as this device has them', () => {
		const wrapper = mount(SensesSettings)

		expect(switches(wrapper)[0].props('modelValue')).toBe(false)
		expect(switches(wrapper)[1].props('modelValue')).toBe(true)
	})

	/** turning sound on plays one, so the reader knows what they turned on */
	it('keeps the choice and lets the reader hear or feel it', async () => {
		const wrapper = mount(SensesSettings)

		await switches(wrapper)[0].vm.$emit('update:modelValue', true)
		expect(senses.sounds).toBe(true)
		expect(senses.play).toHaveBeenCalledWith('like')

		await switches(wrapper)[1].vm.$emit('update:modelValue', false)
		await switches(wrapper)[1].vm.$emit('update:modelValue', true)
		expect(senses.buzz).toHaveBeenCalledWith('like')
	})

	it('plays a few of them on Listen, whatever the switch says', async () => {
		vi.useFakeTimers()
		const wrapper = mount(SensesSettings)

		await wrapper.findComponent({ name: 'NcButton' }).vm.$emit('click')
		vi.runAllTimers()
		vi.useRealTimers()

		expect(senses.play.mock.calls.map((call) => call[0])).toEqual(['like', 'boost', 'post', 'dm'])
		expect(senses.play.mock.calls.every((call) => call[1]?.force === true)).toBe(true)
	})
})
