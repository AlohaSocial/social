/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import DirectMessagesSettings from '../../../src/components/DirectMessagesSettings.vue'
import { showError } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), patch: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const ROUTE = '/index.php/apps/social/api/v1/social/direct_messages'

/**
 * @param {object|Error} answer what the read answers with, or throws
 * @return {Promise<object>} the mounted choice, once the read has settled
 */
async function mountSettings(answer = { from: 'following' }) {
	axios.get.mockImplementation(() => (answer instanceof Error
		? Promise.reject(answer)
		: Promise.resolve({ data: answer })))
	axios.patch.mockImplementation((url, body) => Promise.resolve({ data: body }))

	const wrapper = mount(DirectMessagesSettings)
	await flushPromises()

	return wrapper
}

const radios = (wrapper) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
const chosen = (wrapper) => radios(wrapper)[0].props('modelValue')

/**
 * @param {object} wrapper the mounted choice
 * @param {string} value the answer picked
 */
async function choose(wrapper, value) {
	radios(wrapper).find((radio) => radio.props('value') === value).vm.$emit('update:modelValue', value)
	await flushPromises()
}

describe('who may send direct messages', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers everybody, the people followed and nobody', async () => {
		const wrapper = await mountSettings()

		expect(radios(wrapper).map((radio) => radio.props('value'))).toEqual(['all', 'following', 'none'])
		expect(wrapper.text()).toContain('Everybody')
		expect(wrapper.text()).toContain('People you follow')
		expect(wrapper.text()).toContain('Nobody')
	})

	it('starts where the server says it is', async () => {
		expect(chosen(await mountSettings({ from: 'all' }))).toBe('all')
		expect(chosen(await mountSettings({ from: 'none' }))).toBe('none')
		expect(axios.get).toHaveBeenCalledWith(ROUTE)
	})

	it('keeps the people followed for an answer it does not know', async () => {
		expect(chosen(await mountSettings({ from: 'friends' }))).toBe('following')
	})

	it('cannot be changed until it knows where it stands', async () => {
		axios.get.mockReturnValue(new Promise(() => {}))

		expect(radios(mount(DirectMessagesSettings))[0].props('disabled')).toBe(true)
	})

	it('saves the choice and takes what the server answers', async () => {
		const wrapper = await mountSettings()
		axios.patch.mockResolvedValue({ data: { from: 'none' } })

		await choose(wrapper, 'none')

		expect(axios.patch).toHaveBeenCalledWith(ROUTE, { from: 'none' })
		expect(chosen(wrapper)).toBe('none')
	})

	it('sends nothing for the choice already made', async () => {
		const wrapper = await mountSettings({ from: 'all' })

		await choose(wrapper, 'all')

		expect(axios.patch).not.toHaveBeenCalled()
	})

	it('goes back where it was when the save fails, and says so', async () => {
		const wrapper = await mountSettings({ from: 'all' })
		axios.patch.mockRejectedValue(new Error('nope'))

		await choose(wrapper, 'following')

		expect(chosen(wrapper)).toBe('all')
		expect(showError).toHaveBeenCalledWith('Could not save that setting')
	})

	it('stays usable when it could not be read at all', async () => {
		const wrapper = await mountSettings(new Error('offline'))

		expect(radios(wrapper)[0].props('disabled')).toBe(false)
		expect(showError).not.toHaveBeenCalled()
	})
})
