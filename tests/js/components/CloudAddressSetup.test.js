/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import CloudAddressSetup from '../../../src/components/CloudAddressSetup.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))

const mounted = []

/**
 * @param {object} props what the page hands the form
 * @return {object} the mounted form
 */
function mountSetup(props = {}) {
	const wrapper = mount(CloudAddressSetup, { props: { proposed: 'https://cloud.example', proposedFrom: 'overwrite.cli.url', ...props } })
	mounted.push(wrapper)

	return wrapper
}

/**
 * @param {object} wrapper the form
 * @return {boolean} whether the submit button can be pressed
 */
function canSubmit(wrapper) {
	return wrapper.find('button[type="submit"]').attributes('disabled') === undefined
}

describe('CloudAddressSetup', () => {
	afterEach(() => {
		mounted.splice(0).forEach((wrapper) => wrapper.unmount())
		vi.mocked(axios.post).mockReset()
	})

	it('offers the derived address and says where it came from', () => {
		const wrapper = mountSetup()

		expect(wrapper.find('input.setup-input').element.value).toBe('https://cloud.example')
		expect(wrapper.text()).toContain('Suggested from overwrite.cli.url in config.php.')
		expect(canSubmit(wrapper)).toBe(true)
		expect(wrapper.text()).not.toContain('Use this address anyway')
	})

	it('holds back an address other servers cannot use until the administrator overrides it', async () => {
		const wrapper = mountSetup({ proposed: 'http://localhost/index.php', proposedFrom: 'request' })

		expect(wrapper.text()).toContain('It uses http.')
		expect(wrapper.text()).toContain('It names this machine itself')
		expect(wrapper.text()).toContain('It contains /index.php')
		expect(canSubmit(wrapper)).toBe(false)

		await wrapper.find('.cloud-setup__override input').setValue(true)

		expect(canSubmit(wrapper)).toBe(true)
	})

	it('never takes something that is not an address', async () => {
		const wrapper = mountSetup()

		await wrapper.find('input.setup-input').setValue('cloud.example')

		expect(wrapper.text()).toContain('This is not a web address.')
		expect(wrapper.find('.cloud-setup__override').exists()).toBe(false)
		expect(canSubmit(wrapper)).toBe(false)
	})

	it('sends the override with the address and reports success', async () => {
		vi.mocked(axios.post).mockResolvedValue({ data: { cloudAddress: 'http://localhost:8080' } })
		const wrapper = mountSetup({ proposed: 'http://localhost:8080' })

		await wrapper.find('.cloud-setup__override input').setValue(true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/config/cloudAddress', { cloudAddress: 'http://localhost:8080', allowUnsafe: true })
		expect(wrapper.emitted('saved')).toEqual([['http://localhost:8080']])
	})

	it('shows what the server refused, which has the last word', async () => {
		vi.mocked(axios.post).mockRejectedValue({ response: { status: 422, data: { problems: ['ip'] } } })
		const wrapper = mountSetup()

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.text()).toContain('It is a bare IP address.')
		expect(canSubmit(wrapper)).toBe(false)
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it('says when somebody else set the address first', async () => {
		vi.mocked(axios.post).mockRejectedValue({ response: { status: 409, data: { code: 'already-set' } } })
		const wrapper = mountSetup()

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.text()).toContain('The address has already been set. Reload the page.')
	})

	it('says when the address could not be saved at all', async () => {
		vi.mocked(axios.post).mockRejectedValue(new Error('network'))
		const wrapper = mountSetup()

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.text()).toContain('The address could not be saved. Try again.')
		expect(canSubmit(wrapper)).toBe(true)
	})
})
