/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import InterestsSection from '../../../../src/components/admin/InterestsSection.vue'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../../src/services/toast.js', () => ({ showError, showSuccess }))

const ROUTE = '/index.php/apps/social/admin/interests'
const DEFAULTS = { enabled: true, learningDefault: true, halfLife: 30, threshold: 3, cap: 30, window: 7 }

/**
 * @param {object} settings `adminSettings.interests`
 * @return {object} the mounted card
 */
function mountCard(settings = DEFAULTS) {
	return mount(InterestsSection, { props: { settings } })
}

const switches = (wrapper) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
const fields = (wrapper) => wrapper.findAllComponents({ name: 'NcTextField' })
const saveButton = (wrapper) => wrapper.findComponent({ name: 'NcButton' })

/**
 * @param {object} wrapper the mounted card
 * @param {number} index which of the four numbers
 * @param {string} value what to type into it
 */
async function type(wrapper, index, value) {
	await fields(wrapper)[index].find('input').setValue(value)
}

describe('the For you card', () => {
	beforeEach(() => {
		post.mockReset().mockImplementation((url, body) => Promise.resolve({ data: body }))
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('shows what stands now', () => {
		const wrapper = mountCard({ ...DEFAULTS, learningDefault: false, threshold: 2.5 })

		expect(switches(wrapper).map((box) => box.props('modelValue'))).toEqual([true, false])
		expect(fields(wrapper).map((field) => field.props('modelValue'))).toEqual(['30', '2.5', '30', '7'])
	})

	it('explains that the learning default is the GDPR choice', () => {
		const wrapper = mountCard()

		expect(wrapper.text()).toContain('The GDPR-relevant choice: off makes learning opt-in')
	})

	it('fills in the defaults for an instance that never saved the card', () => {
		const wrapper = mountCard({})

		expect(switches(wrapper).map((box) => box.props('modelValue'))).toEqual([true, true])
		expect(fields(wrapper).map((field) => field.props('modelValue'))).toEqual(['30', '3', '30', '7'])
	})

	it('saves all six values in one request, the numbers as numbers', async () => {
		const wrapper = mountCard()
		await switches(wrapper)[1].vm.$emit('update:modelValue', false)
		await type(wrapper, 0, '60')
		await type(wrapper, 1, '4.5')

		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(ROUTE, {
			enabled: true,
			learningDefault: false,
			halfLife: 60,
			threshold: 4.5,
			cap: 30,
			window: 7,
		})
		expect(showSuccess).toHaveBeenCalledWith('Saved')
	})

	it('draws what the server saved', async () => {
		post.mockResolvedValue({ data: { ...DEFAULTS, cap: 40 } })
		const wrapper = mountCard()
		await type(wrapper, 2, '41')

		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(fields(wrapper)[2].props('modelValue')).toBe('40')
	})

	it.each([
		[0, '6', 'A whole number from 7 to 180'],
		[0, '181', 'A whole number from 7 to 180'],
		[0, '30.5', 'A whole number from 7 to 180'],
		[1, '0.4', 'A number from 0.5 to 20'],
		[1, '21', 'A number from 0.5 to 20'],
		[2, '4', 'A whole number from 5 to 100'],
		[2, '101', 'A whole number from 5 to 100'],
		[3, '0', 'A whole number from 1 to 30'],
		[3, '31', 'A whole number from 1 to 30'],
		[3, '', 'A whole number from 1 to 30'],
	])('refuses %s = "%s" before asking the server', async (index, value, said) => {
		const wrapper = mountCard()
		await type(wrapper, index, value)

		expect(fields(wrapper)[index].props('error')).toBe(true)
		expect(fields(wrapper)[index].props('helperText')).toBe(said)
		expect(saveButton(wrapper).props('disabled')).toBe(true)

		await wrapper.vm.save()
		expect(post).not.toHaveBeenCalled()
	})

	it('takes the edges of every range', async () => {
		const wrapper = mountCard()
		await type(wrapper, 0, '7')
		await type(wrapper, 1, '20')
		await type(wrapper, 2, '100')
		await type(wrapper, 3, '1')

		expect(fields(wrapper).every((field) => field.props('error') === false)).toBe(true)
		expect(saveButton(wrapper).props('disabled')).toBe(false)
	})

	it('shows the server\'s reason when it refuses', async () => {
		post.mockRejectedValue({ response: { data: { error: 'halfLife out of range' } } })
		const wrapper = mountCard()

		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('halfLife out of range')
	})
})
