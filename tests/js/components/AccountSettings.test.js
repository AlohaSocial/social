/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import AccountSettings from '../../../src/components/AccountSettings.vue'
import { showError } from '../../../src/services/toast.js'
import { useAccountStore } from '../../../src/store/account.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), patch: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

/** What `verify_credentials` answers with: an account, with `source` on it. */
function credentials(overrides = {}) {
	return {
		id: '1',
		username: 'alice',
		acct: 'alice',
		display_name: 'Alice Appleby',
		url: 'https://cloud.example.org/@alice',
		locked: false,
		discoverable: true,
		indexable: true,
		bot: false,
		source: { privacy: 'private' },
		...overrides,
	}
}

function mountSettings() {
	const pinia = createPinia()
	setActivePinia(pinia)

	return mount(AccountSettings, { global: { plugins: [pinia] } })
}

/** @param {object} wrapper the mounted card */
const switches = (wrapper) => wrapper.findAll('.account-settings__switch input')
function switchFor(wrapper, label) {
	return wrapper.findAll('.account-settings__switch')
		.find((row) => row.text().includes(label))
}

describe('AccountSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: credentials() })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('asks the server for the account it is about', async () => {
		mountSettings()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${API}/accounts/verify_credentials`)
	})

	it('shows what the account currently says', async () => {
		axios.get.mockResolvedValue({ data: credentials({ locked: true, indexable: false }) })
		const wrapper = mountSettings()
		await flushPromises()

		expect(switchFor(wrapper, 'Approve who follows you').find('input').element.checked).toBe(true)
		expect(switchFor(wrapper, 'Suggest this account to others').find('input').element.checked).toBe(true)
		expect(switchFor(wrapper, 'Let search find your public posts').find('input').element.checked).toBe(false)
		expect(switchFor(wrapper, 'This is an automated account').find('input').element.checked).toBe(false)
	})

	it('offers the four switches the route writes', async () => {
		const wrapper = mountSettings()
		await flushPromises()

		expect(switches(wrapper)).toHaveLength(4)
	})

	/** `private` on the wire is the audience this app calls followers-only. */
	it('shows the default audience in this app’s words', async () => {
		const wrapper = mountSettings()
		await flushPromises()

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue'))
			.toMatchObject({ id: 'followers' })
	})

	it('sends nothing until something changes', async () => {
		mountSettings()
		await flushPromises()

		expect(axios.patch).not.toHaveBeenCalled()
	})

	/**
	 * Every other setting on the page saves as it is changed, and a Save
	 * button on this one alone was a form that looked saved and was not.
	 */
	it('saves a switch as soon as it is flipped, sending only that', async () => {
		const wrapper = mountSettings()
		await flushPromises()
		axios.patch.mockResolvedValue({ data: credentials({ locked: true }) })

		await switchFor(wrapper, 'Approve who follows you').find('input').setValue(true)
		await flushPromises()

		// the display name and the three other flags are untouched, so a
		// backend that owns the name is never asked to write it back
		expect(axios.patch).toHaveBeenCalledTimes(1)
		expect(axios.patch).toHaveBeenCalledWith(`${API}/accounts/update_credentials`, { locked: true })
		expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
	})

	it('saves a change made while another is on its way once that one answers', async () => {
		const wrapper = mountSettings()
		await flushPromises()
		let answer
		axios.patch
			.mockImplementationOnce(() => new Promise((resolve) => { answer = resolve }))
			.mockResolvedValueOnce({ data: credentials({ locked: true, bot: true }) })

		await switchFor(wrapper, 'Approve who follows you').find('input').setValue(true)
		await switchFor(wrapper, 'This is an automated account').find('input').setValue(true)
		expect(axios.patch).toHaveBeenCalledTimes(1)

		answer({ data: credentials({ locked: true }) })
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledTimes(2)
		expect(axios.patch).toHaveBeenLastCalledWith(`${API}/accounts/update_credentials`, { bot: true })
	})

	it('puts a refused change back, so the switch shows what the server holds', async () => {
		const wrapper = mountSettings()
		await flushPromises()
		axios.patch.mockRejectedValue(new Error('refused'))

		await switchFor(wrapper, 'Approve who follows you').find('input').setValue(true)
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.vm.draft.locked).toBe(false)
		expect(axios.patch).toHaveBeenCalledTimes(1)
	})

	/**
	 * The display name belongs to the Nextcloud account and the actor copies
	 * it, so a field here was a remote control for a setting that lives
	 * elsewhere -- and one that did nothing at all on an account whose backend
	 * owns the name, which is every LDAP or SAML instance.
	 */
	describe('the display name', () => {
		it('is shown rather than offered for editing', async () => {
			axios.get.mockResolvedValue({ data: credentials({ display_name: 'Alice Appleby' }) })
			const wrapper = mountSettings()
			await flushPromises()

			expect(wrapper.find('.account-settings__name input').exists()).toBe(false)
			expect(wrapper.text()).toContain('You post as Alice Appleby.')
		})

		it('points at the settings that actually own it', async () => {
			const wrapper = mountSettings()
			await flushPromises()

			const link = wrapper.find('.account-settings__link')
			expect(link.text()).toContain('Change your name in your Nextcloud settings')
			expect(link.attributes('href')).toContain('/settings/user')
		})

		it('falls back to the handle where Nextcloud holds no name', async () => {
			axios.get.mockResolvedValue({ data: credentials({ display_name: '', username: 'alice' }) })
			const wrapper = mountSettings()
			await flushPromises()

			expect(wrapper.text()).toContain('You post as alice.')
		})

		it('is never sent, even when everything else is', async () => {
			const wrapper = mountSettings()
			await flushPromises()
			axios.patch.mockResolvedValue({ data: credentials({ locked: true }) })

			await switchFor(wrapper, 'Approve who follows you').find('input').setValue(true)
			await flushPromises()

			const [, body] = axios.patch.mock.calls[0]
			expect(body).not.toHaveProperty('display_name')
		})
	})

	it('sends the default audience under source, in the name the wire uses', async () => {
		const wrapper = mountSettings()
		await flushPromises()
		axios.patch.mockResolvedValue({ data: credentials({ source: { privacy: 'public' } }) })

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'public', text: 'Public' })
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith(
			`${API}/accounts/update_credentials`,
			{ source: { privacy: 'public' } },
		)
	})

	/** The store holds the answer, so the composer's default follows the form. */
	it('leaves the saved account where everything else reads it', async () => {
		const wrapper = mountSettings()
		await flushPromises()
		axios.patch.mockResolvedValue({ data: credentials({ source: { privacy: 'unlisted' } }) })

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'unlisted', text: 'Unlisted' })
		await flushPromises()

		expect(useAccountStore().defaultPostVisibility).toBe('unlisted')
	})

	it('says nothing and asks for nothing while the account has not come', () => {
		const wrapper = mountSettings()

		expect(wrapper.find('.account-settings__loading').exists()).toBe(true)
		expect(wrapper.find('.account-settings__switch').exists()).toBe(false)
	})
})
