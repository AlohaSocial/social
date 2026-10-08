/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import BlueskySettings from '../../../src/components/BlueskySettings.vue'
import { confirmPassword } from '../../../src/services/externalApi.js'
import { showError } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/externalApi.js', () => ({ confirmPassword: vi.fn().mockResolvedValue(undefined) }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const IDENTITY = '/index.php/apps/social/api/v1/social/bluesky/identity'
const RECOVERY = '/index.php/apps/social/api/v1/social/bluesky/recovery'
const PHRASE = 'abandon ability able about above absent absorb abstract absurd abuse access accident'

function identity(overrides = {}) {
	return {
		handle: 'alice.cloud.example.org',
		did: 'did:plc:abc123',
		url: 'https://bsky.app/profile/alice.cloud.example.org',
		state: 'active',
		recovery_key: false,
		...overrides,
	}
}

/** @param {object} answer what the identity route answers */
function serverHas(answer = identity()) {
	axios.get.mockImplementation((url) => (url === IDENTITY
		? Promise.resolve({ data: answer })
		: Promise.reject(new Error(`unexpected ${url}`))))
}

/** @return {object} the mounted section */
function mountSettings() {
	return mount(BlueskySettings, {
		global: {
			stubs: {
				NcDialog: {
					name: 'NcDialog',
					props: ['open', 'name', 'buttons'],
					emits: ['update:open'],
					template: '<div v-if="open" class="dialog-stub"><slot /></div>',
				},
			},
		},
	})
}

const buttonByText = (wrapper, text) => wrapper.findAll('button').find((button) => button.text() === text)
const buttonByLabel = (wrapper, label) => wrapper.findAll('button').find((button) => button.attributes('aria-label') === label)

/**
 * The same account as Bluesky sees it. Nothing here is a setting: the
 * identity exists because this server offers one, and the one thing the
 * person can take away is the phrase that proves it is theirs.
 */
describe('BlueskySettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.mocked(confirmPassword).mockResolvedValue(undefined)
	})

	it('asks for the identity, and shows the handle and DID', async () => {
		serverHas()
		const wrapper = mountSettings()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(IDENTITY)
		const link = wrapper.find('a.bluesky-settings__code')
		expect(link.text()).toBe('@alice.cloud.example.org')
		expect(link.attributes('href')).toBe('https://bsky.app/profile/alice.cloud.example.org')
		expect(link.attributes('target')).toBe('_blank')
		expect(link.attributes('rel')).toBe('noopener')
		expect(wrapper.find('code').text()).toBe('did:plc:abc123')
	})

	it('copies the handle and the DID', async () => {
		serverHas()
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = mountSettings()
		await flushPromises()

		await buttonByLabel(wrapper, 'Copy the Bluesky handle').trigger('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith('@alice.cloud.example.org')
		expect(buttonByLabel(wrapper, 'Copied')).toBeDefined()

		await buttonByLabel(wrapper, 'Copy the DID').trigger('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith('did:plc:abc123')
	})

	it('says so when the identity could not be read', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mountSettings()
		await flushPromises()

		expect(wrapper.text()).toContain('Could not read your Bluesky identity right now.')
		expect(buttonByText(wrapper, 'Create recovery phrase')).toBeUndefined()
	})

	describe('showing posts on Bluesky', () => {
		const STATE = '/index.php/apps/social/api/v1/social/bluesky/state'
		const pauseSwitch = (wrapper) => wrapper.find('.bluesky-settings__switch input')

		it('is on while the account is live there, and says nothing more', async () => {
			serverHas(identity({ active: true }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(wrapper.text()).toContain('Show my posts on Bluesky')
			expect(pauseSwitch(wrapper).element.checked).toBe(true)
			expect(wrapper.text()).not.toContain('paused on Bluesky')
		})

		it('pauses the account, and follows what the server answered', async () => {
			serverHas(identity({ active: true }))
			axios.post.mockResolvedValue({ data: identity({ active: false }) })
			const wrapper = mountSettings()
			await flushPromises()

			await pauseSwitch(wrapper).setValue(false)
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(STATE, { active: false })
			expect(pauseSwitch(wrapper).element.checked).toBe(false)
			expect(wrapper.text()).toContain('This account is paused on Bluesky: nothing new is published there')
			// the handle stays: the address is still theirs
			expect(wrapper.find('a.bluesky-settings__code').text()).toBe('@alice.cloud.example.org')
		})

		it('switches back on the same way', async () => {
			serverHas(identity({ active: false }))
			axios.post.mockResolvedValue({ data: identity({ active: true }) })
			const wrapper = mountSettings()
			await flushPromises()
			expect(pauseSwitch(wrapper).element.checked).toBe(false)

			await pauseSwitch(wrapper).setValue(true)
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(STATE, { active: true })
			expect(wrapper.text()).not.toContain('paused on Bluesky')
		})

		it('stays where the server left it when the change is refused', async () => {
			serverHas(identity({ active: true }))
			axios.post.mockRejectedValue(new Error('offline'))
			const wrapper = mountSettings()
			await flushPromises()

			await pauseSwitch(wrapper).setValue(false)
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not change whether your posts show on Bluesky')
			expect(pauseSwitch(wrapper).element.checked).toBe(true)
		})
	})

	describe('the recovery phrase', () => {
		it('offers to create one while there is none', async () => {
			serverHas(identity({ recovery_key: false }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(buttonByText(wrapper, 'Create recovery phrase')).toBeDefined()
			expect(wrapper.text()).toContain('shown once')
		})

		/** Shown once means a second look is a new phrase, and the old one dies. */
		it('offers a new one once there is one, and says the old one stops working', async () => {
			serverHas(identity({ recovery_key: true }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(buttonByText(wrapper, 'Create a new recovery phrase')).toBeDefined()
			expect(wrapper.text()).toContain('stops working')
		})

		it('asks for the password, then shows the twelve words once', async () => {
			serverHas()
			axios.post.mockResolvedValue({ data: { ...identity({ recovery_key: true }), phrase: PHRASE } })
			const wrapper = mountSettings()
			await flushPromises()

			await buttonByText(wrapper, 'Create recovery phrase').trigger('click')
			await flushPromises()

			expect(confirmPassword).toHaveBeenCalled()
			expect(axios.post).toHaveBeenCalledWith(RECOVERY)
			const dialog = wrapper.find('.dialog-stub')
			expect(dialog.find('code').text()).toBe(PHRASE)
			expect(dialog.text()).toContain('shown once and never again')
			// the button follows the identity the answer carried
			expect(buttonByText(wrapper, 'Create a new recovery phrase')).toBeDefined()
		})

		it('does nothing when the password dialog is dismissed', async () => {
			serverHas()
			vi.mocked(confirmPassword).mockRejectedValueOnce(new Error('dismissed'))
			const wrapper = mountSettings()
			await flushPromises()

			await buttonByText(wrapper, 'Create recovery phrase').trigger('click')
			await flushPromises()

			expect(axios.post).not.toHaveBeenCalled()
			expect(wrapper.find('.dialog-stub').exists()).toBe(false)
		})

		it('copies the words from the dialog and forgets them when it closes', async () => {
			serverHas()
			axios.post.mockResolvedValue({ data: { ...identity({ recovery_key: true }), phrase: PHRASE } })
			const writeText = vi.fn().mockResolvedValue(undefined)
			Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
			const wrapper = mountSettings()
			await flushPromises()
			await buttonByText(wrapper, 'Create recovery phrase').trigger('click')
			await flushPromises()

			const [copy, done] = wrapper.findComponent({ name: 'NcDialog' }).props('buttons')
			expect(copy.label).toBe('Copy the words')
			await copy.callback()
			expect(writeText).toHaveBeenCalledWith(PHRASE)

			done.callback()
			await flushPromises()
			expect(wrapper.find('.dialog-stub').exists()).toBe(false)
			expect(wrapper.vm.phrase).toBe('')
		})

		it('asks for the password again when the server says the confirmation ran out', async () => {
			serverHas()
			axios.post.mockRejectedValue({ response: { status: 403, data: { message: 'Password confirmation is required' } } })
			const wrapper = mountSettings()
			await flushPromises()

			await buttonByText(wrapper, 'Create recovery phrase').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Confirm your password again and retry.')
			expect(wrapper.find('.dialog-stub').exists()).toBe(false)
		})

		it('says so when the phrase could not be made', async () => {
			serverHas()
			axios.post.mockRejectedValue(new Error('offline'))
			const wrapper = mountSettings()
			await flushPromises()

			await buttonByText(wrapper, 'Create recovery phrase').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not create a recovery phrase')
		})
	})
})
