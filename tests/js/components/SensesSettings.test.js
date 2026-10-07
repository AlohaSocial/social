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
	videoSound: true,
	play: vi.fn(),
	buzz: vi.fn(),
}))
vi.mock('../../../src/services/senses.js', () => ({
	soundsEnabled: () => senses.sounds,
	vibrationEnabled: () => senses.vibration,
	videoSoundEnabled: () => senses.videoSound,
	setVideoSoundEnabled: (on) => { senses.videoSound = on },
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
		senses.videoSound = true
		window.sessionStorage.clear()
		senses.play.mockReset()
		senses.buzz.mockReset()
	})

	it('shows the switches as this device has them', () => {
		const wrapper = mount(SensesSettings)

		expect(switches(wrapper)[0].props('modelValue')).toBe(false)
		expect(switches(wrapper)[1].props('modelValue')).toBe(true)
		expect(switches(wrapper)[2].props('modelValue')).toBe(true)
	})

	it('says Shorts start with their sound on, without a word about stories', () => {
		const wrapper = mount(SensesSettings)

		expect(wrapper.text()).toContain('Shorts start with their sound on')
		expect(wrapper.text()).not.toMatch(/stor(y|ies)/i)
	})

	/** the switch has to win over a mute made earlier in this tab, or it seems to do nothing */
	it('keeps whether videos start with sound, and forgets the tab\'s own mute', async () => {
		window.sessionStorage.setItem('social.videoMuted', '1')
		const wrapper = mount(SensesSettings)

		await switches(wrapper)[2].vm.$emit('update:modelValue', false)

		expect(senses.videoSound).toBe(false)
		expect(window.sessionStorage.getItem('social.videoMuted')).toBeNull()
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
