/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import FilesCommentsSettings from '../../../src/components/FilesCommentsSettings.vue'
import { showError } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), patch: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const ROUTE = '/index.php/apps/social/api/v1/social/files_comments'

/**
 * @param {object|Error} answer what the read answers with, or throws
 * @return {Promise<object>} the mounted switch, once the read has settled
 */
async function mountSettings(answer = { enabled: true }) {
	axios.get.mockImplementation(() => (answer instanceof Error
		? Promise.reject(answer)
		: Promise.resolve({ data: answer })))
	axios.patch.mockResolvedValue({ data: {} })

	const wrapper = mount(FilesCommentsSettings)
	await flushPromises()

	return wrapper
}

const toggle = (wrapper) => wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })

describe('the Files comments switch', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('starts where the server says it is', async () => {
		expect(toggle(await mountSettings({ enabled: true })).props('modelValue')).toBe(true)
		expect(toggle(await mountSettings({ enabled: false })).props('modelValue')).toBe(false)
	})

	/** It is on unless turned off, so only a plain no is off. */
	it('is on for an answer that does not say no', async () => {
		expect(toggle(await mountSettings({})).props('modelValue')).toBe(true)
	})

	it('cannot be moved until it knows where it stands', async () => {
		axios.get.mockReturnValue(new Promise(() => {}))

		expect(toggle(mount(FilesCommentsSettings)).props('disabled')).toBe(true)
	})

	/** Whose comments leave the server is the thing to know before leaving it on. */
	it('says that only your own comments go out', async () => {
		const lede = (await mountSettings()).find('.files-comments-settings__lede').text()

		expect(lede).toContain('a comment you write there is posted as your reply')
		expect(lede).toContain('Comments from other people with access to the file stay on this server')
	})

	it('saves the new setting when it is moved', async () => {
		const wrapper = await mountSettings()

		toggle(wrapper).vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith(ROUTE, { enabled: false })
		expect(toggle(wrapper).props('modelValue')).toBe(false)
	})

	it('goes back where it was when the save fails, and says so', async () => {
		const wrapper = await mountSettings({ enabled: true })
		axios.patch.mockRejectedValue(new Error('nope'))

		toggle(wrapper).vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(toggle(wrapper).props('modelValue')).toBe(true)
		expect(showError).toHaveBeenCalledWith('Could not save that setting')
	})

	it('stays usable when it could not be read at all', async () => {
		const wrapper = await mountSettings(new Error('offline'))

		expect(toggle(wrapper).props('modelValue')).toBe(true)
		expect(toggle(wrapper).props('disabled')).toBe(false)
		expect(showError).not.toHaveBeenCalled()
	})
})
