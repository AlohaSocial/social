/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import NotificationDeliverySettings from '../../../src/components/NotificationDeliverySettings.vue'
import { showError } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), patch: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const ROUTE = '/index.php/apps/social/api/v1/social/notification_delivery'
/** The pause a time field is left alone for before it is saved. */
const PAUSE = 600

const instant = {
	mode: 'instant',
	times: ['08:00', '18:00'],
	passthrough: { direct: true, mentions_from_followed: true },
	quiet: { from: '', to: '' },
}
const digest = { ...instant, mode: 'digest' }

/**
 * @param {object|Error} answer what the read answers with, or throws
 * @return {Promise<object>} the mounted section, once the read has settled
 */
async function mountSettings(answer = instant) {
	axios.get.mockImplementation(() => (answer instanceof Error
		? Promise.reject(answer)
		: Promise.resolve({ data: answer })))
	// the server answers the whole object with the change applied
	let current = answer instanceof Error ? instant : answer
	axios.patch.mockImplementation((url, changes) => {
		current = { ...current, ...changes }

		return Promise.resolve({ data: current })
	})

	const wrapper = mount(NotificationDeliverySettings)
	await flushPromises()

	return wrapper
}

const controls = (wrapper) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
const radio = (wrapper, value) => controls(wrapper).find((control) => control.props('type') === 'radio' && control.props('value') === value)
const toggle = (wrapper, label) => controls(wrapper).find((control) => control.props('type') === 'switch' && control.text() === label)
const timeInputs = (wrapper) => wrapper.findAll('.delivery-settings__daily input[type="time"]')

/**
 * @param {object} data what the server says is wrong
 * @return {Error} as axios throws it
 */
function refusal(data) {
	return Object.assign(new Error('Unprocessable'), { response: { status: 422, data } })
}

describe('the notification delivery section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('shows a loading line until the server has answered', () => {
		axios.get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(NotificationDeliverySettings)

		expect(wrapper.find('.delivery-settings__hint').text()).toBe('Loading …')
		expect(controls(wrapper)).toHaveLength(0)
	})

	it('starts where the server says it is', async () => {
		const wrapper = await mountSettings({ ...digest, quiet: { from: '22:00', to: '07:00' } })

		expect(axios.get).toHaveBeenCalledWith(ROUTE)
		expect(radio(wrapper, 'digest').props('modelValue')).toBe('digest')
		expect(timeInputs(wrapper).map((input) => input.element.value)).toEqual(['08:00'])
		expect(toggle(wrapper, 'Quiet hours').props('modelValue')).toBe(true)
		expect(wrapper.find('.delivery-settings__pair').exists()).toBe(true)
		expect(wrapper.findAll('.delivery-settings__pair input[type="time"]').map((input) => input.element.value))
			.toEqual(['22:00', '07:00'])
	})

	/** The times and the exceptions mean nothing while everything arrives at once. */
	it('keeps the times and the exceptions folded away while notifications arrive as they come', async () => {
		const wrapper = await mountSettings(instant)

		expect(radio(wrapper, 'instant').props('modelValue')).toBe('instant')
		expect(timeInputs(wrapper)).toHaveLength(0)
		expect(toggle(wrapper, 'Direct messages')).toBeUndefined()
		expect(toggle(wrapper, 'Quiet hours').props('modelValue')).toBe(false)
		expect(wrapper.find('.delivery-settings__pair').exists()).toBe(false)
	})

	it('reveals the times when the digest is picked, and saves the choice at once', async () => {
		const wrapper = await mountSettings(instant)

		radio(wrapper, 'digest').vm.$emit('update:modelValue', 'digest')
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith(ROUTE, { mode: 'digest' })
		expect(timeInputs(wrapper)).toHaveLength(1)
		expect(wrapper.text()).toContain('Phone and desktop get one summary, grouped by kind.')
		expect(toggle(wrapper, 'Direct messages').props('modelValue')).toBe(true)
		expect(toggle(wrapper, 'Mentions from people I follow').props('modelValue')).toBe(true)
	})

	/** One summary a day, at one time, with a name a screen reader can say. */
	it('asks for one time of day', async () => {
		const wrapper = await mountSettings(digest)

		const label = wrapper.find('.delivery-settings__daily label')
		expect(label.text()).toBe('At')
		expect(timeInputs(wrapper)[0].attributes('id')).toBe(label.attributes('for'))
		expect(wrapper.text()).not.toContain('Add a time')
	})

	/** A time arrives in pieces, so the save waits for the typing to stop and sends the list sorted. */
	it('saves a typed time after a pause, in order', async () => {
		vi.useFakeTimers()
		const wrapper = await mountSettings(digest)

		await timeInputs(wrapper)[0].setValue('20:00')
		expect(axios.patch).not.toHaveBeenCalled()

		vi.advanceTimersByTime(PAUSE)
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledTimes(1)
		expect(axios.patch).toHaveBeenCalledWith(ROUTE, { times: ['20:00'] })
		expect(timeInputs(wrapper).map((input) => input.element.value)).toEqual(['20:00'])
	})

	it('saves at once when the field is left', async () => {
		vi.useFakeTimers()
		const wrapper = await mountSettings(digest)

		await timeInputs(wrapper)[0].setValue('21:30')
		await timeInputs(wrapper)[0].trigger('blur')
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith(ROUTE, { times: ['21:30'] })
	})

	it('refuses an empty time here, with a reason, rather than asking the server', async () => {
		vi.useFakeTimers()
		const wrapper = await mountSettings(digest)

		await timeInputs(wrapper)[0].setValue('')
		vi.advanceTimersByTime(PAUSE)
		await flushPromises()
		expect(axios.patch).not.toHaveBeenCalled()
		expect(wrapper.find('.delivery-settings__error').text()).toBe('Each time needs hours and minutes, like 08:00.')

		await timeInputs(wrapper)[0].setValue('18:30')
		vi.advanceTimersByTime(PAUSE)
		await flushPromises()
		expect(wrapper.find('.delivery-settings__error').exists()).toBe(false)
		expect(axios.patch).toHaveBeenCalledWith(ROUTE, { times: ['18:30'] })
	})

	it('goes back to what the server has when it refuses, and says why', async () => {
		vi.useFakeTimers()
		const wrapper = await mountSettings(digest)
		axios.patch.mockRejectedValue(refusal({ error: 'At most four times a day' }))

		await timeInputs(wrapper)[0].setValue('20:00')
		vi.advanceTimersByTime(PAUSE)
		await flushPromises()

		expect(showError).toHaveBeenCalledWith({ text: 'Could not save that setting', details: 'At most four times a day' })
		expect(timeInputs(wrapper).map((input) => input.element.value)).toEqual(['08:00'])
	})

	it('falls back to its own words when a failure has none', async () => {
		const wrapper = await mountSettings(instant)
		axios.patch.mockRejectedValue(new Error('offline'))

		radio(wrapper, 'digest').vm.$emit('update:modelValue', 'digest')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith({ text: 'Could not save that setting', details: '' })
		expect(radio(wrapper, 'instant').props('modelValue')).toBe('instant')
		expect(timeInputs(wrapper)).toHaveLength(0)
	})

	it('sends the whole pair of exceptions when one is moved', async () => {
		const wrapper = await mountSettings(digest)

		toggle(wrapper, 'Direct messages').vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith(ROUTE, { passthrough: { direct: false, mentions_from_followed: true } })
		expect(toggle(wrapper, 'Direct messages').props('modelValue')).toBe(false)
	})

	it('starts quiet hours at a late evening and clears them when turned off', async () => {
		const wrapper = await mountSettings(instant)

		toggle(wrapper, 'Quiet hours').vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(axios.patch).toHaveBeenLastCalledWith(ROUTE, { quiet: { from: '22:00', to: '07:00' } })
		expect(wrapper.findAll('.delivery-settings__pair input[type="time"]').map((input) => input.element.value))
			.toEqual(['22:00', '07:00'])
		expect(wrapper.text()).toContain('Nothing but the exceptions above between these times; a summary when they end.')
		// quiet hours hold things back too, so the exceptions now mean something
		expect(toggle(wrapper, 'Direct messages')).toBeDefined()

		toggle(wrapper, 'Quiet hours').vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(axios.patch).toHaveBeenLastCalledWith(ROUTE, { quiet: { from: '', to: '' } })
		expect(wrapper.find('.delivery-settings__pair').exists()).toBe(false)
		expect(toggle(wrapper, 'Direct messages')).toBeUndefined()
	})

	it('saves a changed end of the quiet hours once both ends are times', async () => {
		vi.useFakeTimers()
		const wrapper = await mountSettings({ ...instant, quiet: { from: '22:00', to: '07:00' } })
		const [from, to] = wrapper.findAll('.delivery-settings__pair input[type="time"]')
		expect(wrapper.findAll('.delivery-settings__pair label').map((label) => label.text())).toEqual(['From', 'Until'])
		expect(from.attributes('id')).toBe(wrapper.findAll('.delivery-settings__pair label')[0].attributes('for'))

		await to.setValue('')
		vi.advanceTimersByTime(PAUSE)
		await flushPromises()
		expect(axios.patch).not.toHaveBeenCalled()
		expect(wrapper.find('.delivery-settings__error').text()).toBe('Quiet hours need both a start and an end, like 22:00 and 07:00.')

		await to.setValue('06:30')
		vi.advanceTimersByTime(PAUSE)
		await flushPromises()
		expect(axios.patch).toHaveBeenCalledWith(ROUTE, { quiet: { from: '22:00', to: '06:30' } })
		expect(wrapper.find('.delivery-settings__error').exists()).toBe(false)
	})

	/** An unreadable setting is not worth a toast on a page the reader may only be passing through. */
	it('shows the defaults and stays usable when it could not be read', async () => {
		const wrapper = await mountSettings(new Error('offline'))

		expect(radio(wrapper, 'instant').props('modelValue')).toBe('instant')
		expect(showError).not.toHaveBeenCalled()

		radio(wrapper, 'digest').vm.$emit('update:modelValue', 'digest')
		await flushPromises()
		expect(axios.patch).toHaveBeenCalledWith(ROUTE, { mode: 'digest' })
	})
})
