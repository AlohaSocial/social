/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import InterestsSettings from '../../../src/components/InterestsSettings.vue'
import { fetchInterests, resetInterests, saveInterestSettings } from '../../../src/services/interests.js'
import { showError, showSuccess } from '../../../src/services/toast.js'

vi.mock('../../../src/services/interests.js', () => ({
	fetchInterests: vi.fn(),
	resetInterests: vi.fn(),
	saveInterestSettings: vi.fn(),
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const initialState = {
	settings: { enabled: true, learning: true, paused: false, languages: [], noticeAcknowledged: true },
	// These rows may still exist in upgraded databases but must not be exposed.
	interests: [{ tag: 'legacy-choice', rank: 0, source: 'manual', pinned: true, score: 3, trend: null }],
	candidates: [],
	thin: true,
	cap: 30,
}

const NcDialogStub = {
	name: 'NcDialog',
	props: { open: Boolean, name: String, buttons: Array },
	emits: ['update:open'],
	template: '<div v-if="open" class="dialog-stub"><slot /></div>',
}

const SwitchStub = {
	name: 'NcCheckboxRadioSwitch',
	props: { modelValue: Boolean, disabled: Boolean, type: String },
	emits: ['update:modelValue'],
	template: '<label><slot /></label>',
}

const SelectStub = {
	name: 'NcSelect',
	props: { modelValue: Array, options: Array, inputLabel: String },
	emits: ['update:modelValue'],
	template: '<div class="select-stub">{{ inputLabel }}</div>',
}

async function mountSettings() {
	fetchInterests.mockResolvedValue(initialState)
	const wrapper = mount(InterestsSettings, {
		global: { stubs: { NcButton: true, NcCheckboxRadioSwitch: SwitchStub, NcDialog: NcDialogStub, NcNoteCard: true, NcSelect: SelectStub } },
	})
	await flushPromises()
	return wrapper
}

describe('InterestsSettings', () => {
	let wrapper

	beforeEach(() => vi.clearAllMocks())
	afterEach(() => wrapper?.unmount())

	it('does not offer reader-selected topics, even when legacy choices exist', async () => {
		wrapper = await mountSettings()

		expect(fetchInterests).toHaveBeenCalledOnce()
		expect(wrapper.text()).not.toContain('legacy-choice')
		expect(wrapper.find('.interest-cloud').exists()).toBe(false)
		expect(wrapper.findAll('input').length).toBe(0)
	})

	it('saves the automatic learning preference', async () => {
		const saved = { ...initialState, settings: { ...initialState.settings, learning: false } }
		saveInterestSettings.mockResolvedValue(saved)
		wrapper = await mountSettings()

		wrapper.findComponent(SwitchStub).vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(saveInterestSettings).toHaveBeenCalledWith({ learning: false })
	})

	it('says learning is off until the reader turns it on', async () => {
		wrapper = await mountSettings()

		expect(wrapper.text()).toContain('Off until you turn it on.')
		expect(wrapper.text()).toContain('You cannot choose its topics.')
	})

	it('shows the switch off for a reader who has not opted in', async () => {
		fetchInterests.mockResolvedValue({ ...initialState, settings: { ...initialState.settings, learning: false } })
		wrapper = mount(InterestsSettings, {
			global: { stubs: { NcButton: true, NcCheckboxRadioSwitch: SwitchStub, NcDialog: NcDialogStub, NcNoteCard: true, NcSelect: SelectStub } },
		})
		await flushPromises()

		expect(wrapper.findComponent(SwitchStub).props('modelValue')).toBe(false)
	})

	it('saves only the selected language filter', async () => {
		saveInterestSettings.mockResolvedValue(initialState)
		wrapper = await mountSettings()
		wrapper.findComponent(SelectStub).vm.$emit('update:modelValue', [{ code: 'de', name: 'German' }])
		await flushPromises()

		expect(saveInterestSettings).toHaveBeenCalledWith({ languages: ['de'] })
	})

	it('confirms and resets automatic interests', async () => {
		resetInterests.mockResolvedValue(initialState)
		wrapper = await mountSettings()
		wrapper.vm.confirmingReset = true
		await flushPromises()
		expect(wrapper.find('.dialog-stub').exists()).toBe(true)

		const resetButton = wrapper.vm.resetButtons.find((button) => button.variant === 'error')
		await resetButton.callback()
		await flushPromises()

		expect(resetInterests).toHaveBeenCalledOnce()
		expect(showSuccess).toHaveBeenCalledOnce()
		expect(showError).not.toHaveBeenCalled()
	})
})
