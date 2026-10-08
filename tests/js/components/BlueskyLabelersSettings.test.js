/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'
import BlueskyLabelersSettings from '../../../src/components/BlueskyLabelersSettings.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), error: vi.fn() } }))

const API = '/index.php/apps/social/api/v1/social/bluesky/labelers'

const moderation = {
	did: 'did:plc:ar7c4by46qjdydhdevvrndac',
	name: 'Bluesky Moderation Service',
	removable: false,
	labels: [
		{ value: 'porn', name: 'Adult Content', description: 'Explicit sexual images.', setting: 'hide' },
		{ value: 'spam', name: 'Spam', description: 'Unwanted, repeated, or unrelated actions.', setting: 'warn' },
	],
}
const xblock = {
	did: 'did:plc:xblock',
	name: 'XBlock',
	removable: true,
	labels: [
		{ value: 'twitter-screenshot', name: 'Twitter screenshot', description: '', setting: 'ignore' },
	],
}

/**
 * @param {object[]} labelers what the server lists first
 * @return {Promise<object>} the mounted part
 */
async function mountLabelers(labelers = [moderation]) {
	axios.get.mockResolvedValue({ data: { labelers } })
	const wrapper = mount(BlueskyLabelersSettings)
	await flushPromises()

	return wrapper
}

const labelerRows = (wrapper) => wrapper.findAll('.labelers__labeler')
/**
 * @param {object} wrapper the mounted part
 * @param {string} did whose label
 * @param {string} label the label value
 * @param {string} value ignore, warn or hide
 * @return {object|undefined} that one radio
 */
function radio(wrapper, did, label, value) {
	return wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		.find((control) => control.props('name') === `social-labeler-${did}-${label}` && control.props('value') === value)
}
const buttonByText = (wrapper, text) => wrapper.findAll('button').find((button) => button.text() === text)

describe('BlueskyLabelersSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('lists each labeler with its labels, each Show, Warn or Hide where it stands', async () => {
		const wrapper = await mountLabelers([moderation, xblock])

		expect(axios.get).toHaveBeenCalledWith(API)
		expect(labelerRows(wrapper).map((row) => row.find('.labelers__name').text())).toEqual(['Bluesky Moderation Service', 'XBlock'])
		expect(wrapper.findAll('legend').map((legend) => legend.text())).toEqual(['Adult Content', 'Spam', 'Twitter screenshot'])
		expect(wrapper.text()).toContain('Explicit sexual images.')
		expect(wrapper.find('fieldset').findAllComponents({ name: 'NcCheckboxRadioSwitch' }).map((one) => one.text())).toEqual(['Show', 'Warn', 'Hide'])
		expect(radio(wrapper, moderation.did, 'porn', 'hide').props('modelValue')).toBe('hide')
		expect(radio(wrapper, xblock.did, 'twitter-screenshot', 'ignore').props('modelValue')).toBe('ignore')
	})

	/** Bluesky's own moderation applies to everybody, as on Bluesky. */
	it('offers Remove only for a labeler that can be removed', async () => {
		const wrapper = await mountLabelers([moderation, xblock])

		expect(buttonByText(labelerRows(wrapper)[0], 'Remove')).toBeUndefined()
		expect(buttonByText(labelerRows(wrapper)[1], 'Remove')).toBeDefined()
	})

	it('names a labeler by its DID when it gives no name', async () => {
		const wrapper = await mountLabelers([{ ...xblock, name: '' }])

		expect(wrapper.find('.labelers__name').text()).toBe('did:plc:xblock')
	})

	it('tells its card when Bluesky is off on the server, and shows nothing', async () => {
		axios.get.mockRejectedValue({ response: { status: 404, data: { error: 'Bluesky is not enabled on this server' } } })
		const wrapper = mount(BlueskyLabelersSettings)
		await flushPromises()

		expect(wrapper.emitted('unavailable')).toHaveLength(1)
		expect(labelerRows(wrapper)).toHaveLength(0)
	})

	it('says so when the list cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mount(BlueskyLabelersSettings)
		await flushPromises()

		expect(wrapper.emitted('unavailable')).toBeUndefined()
		expect(wrapper.text()).toContain('Could not read your labelers right now.')
	})

	describe('adding a labeler', () => {
		it('subscribes by handle and shows the list the server answered', async () => {
			axios.post.mockResolvedValue({ data: { labelers: [moderation, xblock] } })
			const wrapper = await mountLabelers()

			await wrapper.find('.labelers__add input').setValue('@xblock.aendra.dev ')
			await wrapper.find('.labelers__add').trigger('submit')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(API, { labeler: 'xblock.aendra.dev' })
			expect(labelerRows(wrapper)).toHaveLength(2)
			expect(wrapper.find('.labelers__add input').element.value).toBe('')
		})

		it('shows why the server would not take it, beside the field', async () => {
			axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'alice.bsky.social is not a labeler' } } })
			const wrapper = await mountLabelers()

			await wrapper.find('.labelers__add input').setValue('alice.bsky.social')
			await wrapper.find('.labelers__add').trigger('submit')
			await flushPromises()

			expect(wrapper.find('.labelers__error').text()).toBe('alice.bsky.social is not a labeler')
			expect(wrapper.find('.labelers__add input').element.value).toBe('alice.bsky.social')
			expect(labelerRows(wrapper)).toHaveLength(1)
			expect(showError).not.toHaveBeenCalled()
		})

		it('has nothing to send while the field is empty', async () => {
			const wrapper = await mountLabelers()

			expect(buttonByText(wrapper, 'Add labeler').attributes('disabled')).toBeDefined()
		})
	})

	describe('a label\'s setting', () => {
		it('is saved as it is picked, and the answer is what shows', async () => {
			const changed = { ...moderation, labels: [{ ...moderation.labels[0], setting: 'warn' }, moderation.labels[1]] }
			axios.put.mockResolvedValue({ data: { labelers: [changed] } })
			const wrapper = await mountLabelers()

			radio(wrapper, moderation.did, 'porn', 'warn').vm.$emit('update:modelValue', 'warn')
			await flushPromises()

			expect(axios.put).toHaveBeenCalledWith(API + '/setting', { did: moderation.did, label: 'porn', setting: 'warn' })
			expect(radio(wrapper, moderation.did, 'porn', 'warn').props('modelValue')).toBe('warn')
		})

		it('goes back to what the server has when it refuses', async () => {
			axios.put.mockRejectedValue({ response: { status: 422, data: { error: 'setting must be ignore, warn or hide' } } })
			const wrapper = await mountLabelers()

			radio(wrapper, moderation.did, 'porn', 'ignore').vm.$emit('update:modelValue', 'ignore')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('setting must be ignore, warn or hide')
			expect(radio(wrapper, moderation.did, 'porn', 'hide').props('modelValue')).toBe('hide')
		})

		it('sends nothing for the choice already made', async () => {
			const wrapper = await mountLabelers()

			radio(wrapper, moderation.did, 'porn', 'hide').vm.$emit('update:modelValue', 'hide')
			await flushPromises()

			expect(axios.put).not.toHaveBeenCalled()
		})
	})

	describe('removing a labeler', () => {
		it('unsubscribes by DID and shows the list the server answered', async () => {
			axios.delete.mockResolvedValue({ data: { labelers: [moderation] } })
			const wrapper = await mountLabelers([moderation, xblock])

			await buttonByText(labelerRows(wrapper)[1], 'Remove').trigger('click')
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(API, { data: { did: 'did:plc:xblock' } })
			expect(labelerRows(wrapper)).toHaveLength(1)
		})

		it('keeps the labeler and says so when it fails', async () => {
			axios.delete.mockRejectedValue(new Error('offline'))
			const wrapper = await mountLabelers([moderation, xblock])

			await buttonByText(labelerRows(wrapper)[1], 'Remove').trigger('click')
			await flushPromises()

			expect(labelerRows(wrapper)).toHaveLength(2)
			expect(showError).toHaveBeenCalledWith('Could not remove that labeler')
		})
	})
})
