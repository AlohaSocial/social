/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import BlueskySettings from '../../../src/components/BlueskySettings.vue'
import { confirmPassword } from '../../../src/services/externalApi.js'
import { showError, showSuccess } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({
	default: { delete: vi.fn(), get: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/externalApi.js', () => ({ confirmPassword: vi.fn().mockResolvedValue(undefined) }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const IDENTITY = '/index.php/apps/social/api/v1/social/bluesky/identity'
const RECOVERY = '/index.php/apps/social/api/v1/social/bluesky/recovery'
const CONTROL = '/index.php/apps/social/api/v1/social/bluesky/control'
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

const SERVER_KEY = 'did:key:zQ3shServer'
const RECOVERY_KEY = 'did:key:zQ3shRecovery'

/**
 * @param {object} overrides what differs from a DID this server alone controls
 * @return {object} what the control route answers
 */
function control(overrides = {}) {
	return {
		did: 'did:plc:abc123',
		state: 'active',
		rotation_keys: [{ key: SERVER_KEY, role: 'server' }],
		last_operation: { cid: 'bafyhead', created_at: 1760000000, signed_by: { key: SERVER_KEY, role: 'server' } },
		undo: null,
		...overrides,
	}
}

/**
 * @param {object} answer what the identity route answers
 * @param {object|Error} controls what the control route answers, or how it fails
 */
function serverHas(answer = identity(), controls = control()) {
	axios.get.mockImplementation((url) => {
		if (url === IDENTITY) {
			return Promise.resolve({ data: answer })
		}
		if (url === CONTROL) {
			return controls instanceof Error ? Promise.reject(controls) : Promise.resolve({ data: controls })
		}
		return Promise.reject(new Error(`unexpected ${url}`))
	})
}

/** @return {object} the mounted section */
function mountSettings() {
	return mount(BlueskySettings, {
		global: {
			stubs: {
				RouterLink: RouterLinkStub,
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

	it('shows and copies the hosting provider for Bluesky sign-in', async () => {
		serverHas()
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = mountSettings()
		await flushPromises()

		const signIn = wrapper.find('.bluesky-settings__pds-sign-in')
		expect(signIn.text()).toContain('Sign in to the Bluesky app')
		expect(signIn.text()).toContain('@alice.cloud.example.org')
		expect(signIn.text()).toContain(window.location.origin)
		expect(signIn.text()).toContain('with an app password you make below')
		expect(signIn.text()).toContain('Never enter your Nextcloud password')

		await buttonByLabel(wrapper, 'Copy the hosting provider address').trigger('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith(window.location.origin)
	})

	it('puts the sign-in help and the app passwords first, ahead of recovery and handles', async () => {
		serverHas()
		const wrapper = mountSettings()
		await flushPromises()

		const order = [...wrapper.element.querySelectorAll('.bluesky-settings__pds-sign-in, .bluesky-settings__app-passwords, .bluesky-settings__recovery, .bluesky-settings__control, .bluesky-settings__custom-handle')]
			.map((element) => element.className.replace('bluesky-settings__', ''))
		expect(order.slice(0, 2)).toEqual(['pds-sign-in', 'app-passwords'])
		expect(order).toContain('recovery')
	})

	it('says so when the identity could not be read', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mountSettings()
		await flushPromises()

		expect(wrapper.text()).toContain('Could not read your Bluesky identity right now.')
		expect(buttonByText(wrapper, 'Create recovery phrase')).toBeUndefined()
	})

	describe('being on Bluesky', () => {
		const STATE = '/index.php/apps/social/api/v1/social/bluesky/state'
		const presenceSwitch = (wrapper) => wrapper.find('.bluesky-settings__switch input')
		const offDialog = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialog' })

		it('is on while the person is there, and says what off would mean', async () => {
			serverHas(identity({ active: true }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(wrapper.text()).toContain('Be on Bluesky')
			expect(presenceSwitch(wrapper).element.checked).toBe(true)
			expect(wrapper.text()).toContain('Switched off, your Bluesky profile and posts are no longer shown there')
			expect(wrapper.text()).not.toContain('You are not on Bluesky')
		})

		it('switches off, and follows what the server answered', async () => {
			serverHas(identity({ active: true, recovery_key: true }))
			axios.post.mockResolvedValue({ data: identity({ active: false, state: 'deactivated', recovery_key: true }) })
			const wrapper = mountSettings()
			await flushPromises()

			await presenceSwitch(wrapper).setValue(false)
			await flushPromises()
			offDialog(wrapper).vm.$emit('confirm')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(STATE, { active: false })
			expect(presenceSwitch(wrapper).element.checked).toBe(false)
			expect(wrapper.text()).toContain('You are not on Bluesky: your profile and posts are not shown there')
			expect(wrapper.text()).toContain('Bluesky apps are signed out of your account')
			expect(wrapper.text()).toContain('your likes, replies and messages to them stay here')
			expect(wrapper.text()).toContain('posts written while off stay off Bluesky')
			// the handle stays: the address is still theirs
			expect(wrapper.find('a.bluesky-settings__code').text()).toBe('@alice.cloud.example.org')
			// what only means something while on Bluesky is not offered
			expect(wrapper.text()).not.toContain('Publish my blocks of Bluesky accounts')
			expect(buttonByText(wrapper, 'Create a new recovery phrase')).toBeUndefined()
		})

		it('asks before switching off, and says what it costs', async () => {
			serverHas(identity({ active: true }))
			const wrapper = mountSettings()
			await flushPromises()

			await presenceSwitch(wrapper).setValue(false)
			await flushPromises()

			expect(axios.post).not.toHaveBeenCalled()
			expect(offDialog(wrapper).props()).toMatchObject({
				name: 'Leave Bluesky?',
				message: 'Every Bluesky app signed in to your account is signed out, and your profile and posts are no longer shown on Bluesky. Posts you write while you are off never reach Bluesky, not even after you switch back on.',
				confirmLabel: 'Switch off',
			})
			expect(wrapper.find('.dialog-stub').text()).toContain('Every Bluesky app signed in to your account is signed out')
		})

		it('stays on when switching off is not confirmed', async () => {
			serverHas(identity({ active: true }))
			const wrapper = mountSettings()
			await flushPromises()

			await presenceSwitch(wrapper).setValue(false)
			await flushPromises()
			const [cancel] = offDialog(wrapper).findComponent({ name: 'NcDialog' }).props('buttons')
			cancel.callback()
			await flushPromises()

			expect(offDialog(wrapper).exists()).toBe(false)
			expect(axios.post).not.toHaveBeenCalled()
			expect(presenceSwitch(wrapper).element.checked).toBe(true)
			expect(wrapper.text()).not.toContain('You are not on Bluesky')
		})

		it('is neither on nor off while the directory has not taken the identity', async () => {
			serverHas(identity({ active: false, state: 'pending' }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(presenceSwitch(wrapper).exists()).toBe(false)
			expect(wrapper.text()).toContain('Your Bluesky identity is not registered with the directory yet')
			expect(wrapper.text()).not.toContain('You are not on Bluesky')
			expect(wrapper.text()).not.toContain('Publish my blocks of Bluesky accounts')
			expect(buttonByText(wrapper, 'Create recovery phrase')).toBeUndefined()
			expect(wrapper.find('code').text()).toBe('did:plc:abc123')
		})

		it('switches back on the same way', async () => {
			serverHas(identity({ active: false, state: 'deactivated' }))
			axios.post.mockResolvedValue({ data: identity({ active: true }) })
			const wrapper = mountSettings()
			await flushPromises()
			expect(presenceSwitch(wrapper).element.checked).toBe(false)

			await presenceSwitch(wrapper).setValue(true)
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(STATE, { active: true })
			expect(presenceSwitch(wrapper).element.checked).toBe(true)
			expect(wrapper.text()).not.toContain('You are not on Bluesky')
			expect(wrapper.text()).toContain('Publish my blocks of Bluesky accounts')
		})

		it('stays where the server left it when the change is refused', async () => {
			serverHas(identity({ active: true }))
			axios.post.mockRejectedValue(new Error('offline'))
			const wrapper = mountSettings()
			await flushPromises()

			await presenceSwitch(wrapper).setValue(false)
			await flushPromises()
			offDialog(wrapper).vm.$emit('confirm')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not switch your presence on Bluesky')
			expect(presenceSwitch(wrapper).element.checked).toBe(true)
		})
	})

	/** Who can change where the DID points, as the directory has it, in words. */
	describe('who controls the identifier', () => {
		it('lists the keys by what they are to the person, the highest first', async () => {
			serverHas(identity({ recovery_key: true }), control({
				rotation_keys: [{ key: SERVER_KEY, role: 'server' }, { key: RECOVERY_KEY, role: 'recovery' }, { key: 'did:key:zQ3shOther', role: 'unknown' }],
			}))
			const wrapper = mountSettings()
			await flushPromises()

			const keys = wrapper.findAll('.bluesky-settings__control-keys li').map((item) => item.text())
			expect(keys).toEqual(['This server', 'Your recovery phrase', 'A key this server does not know did:key:zQ3shOther'])
			expect(wrapper.text()).not.toContain('can still be undone')
		})

		it('says until when and by which key the latest change can be undone', async () => {
			serverHas(identity({ recovery_key: true }), control({
				rotation_keys: [{ key: RECOVERY_KEY, role: 'recovery' }],
				last_operation: { cid: 'bafymove', created_at: 1760000000, signed_by: { key: RECOVERY_KEY, role: 'recovery' } },
				undo: { until: 1760000000 + 72 * 3600, by: [{ key: SERVER_KEY, role: 'server' }] },
			}))
			const wrapper = mountSettings()
			await flushPromises()

			expect(wrapper.text()).toMatch(/it can still be undone by: This server\./)
		})

		it('says so when the directory could not be asked, and does not ask for a pending identity', async () => {
			serverHas(identity(), new Error('503'))
			const wrapper = mountSettings()
			await flushPromises()
			expect(wrapper.text()).toContain('could not be asked right now')

			axios.get.mockClear()
			serverHas(identity({ state: 'pending' }))
			mountSettings()
			await flushPromises()
			expect(axios.get).not.toHaveBeenCalledWith(CONTROL)
		})

		it('asks again once a new phrase is made', async () => {
			serverHas()
			axios.post.mockResolvedValue({ data: { ...identity({ recovery_key: true }), phrase: PHRASE } })
			const wrapper = mountSettings()
			await flushPromises()
			axios.get.mockClear()

			await buttonByText(wrapper, 'Create recovery phrase').trigger('click')
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith(CONTROL)
		})
	})

	/** The account as files, for moving it without this server: a download after the password. */
	describe('the account download', () => {
		const realLocation = window.location
		let assign

		beforeEach(() => {
			assign = vi.fn()
			Object.defineProperty(window, 'location', { configurable: true, value: { ...realLocation, assign } })
		})

		afterEach(() => {
			Object.defineProperty(window, 'location', { configurable: true, value: realLocation })
		})

		it('asks for the password, then downloads the archive', async () => {
			serverHas()
			const wrapper = mountSettings()
			await flushPromises()

			expect(wrapper.text()).toContain('with a guide to moving the account even if this server is gone')
			await buttonByText(wrapper, 'Download your Bluesky account').trigger('click')
			await flushPromises()

			expect(confirmPassword).toHaveBeenCalled()
			expect(assign).toHaveBeenCalledWith('/index.php/apps/social/api/v1/social/bluesky/archive')
		})

		// the same archive is on the Migration page, a page of its own
		it('links to the Migration page for the full archive', async () => {
			serverHas()
			const wrapper = mountSettings()
			await flushPromises()

			const link = wrapper.findAllComponents(RouterLinkStub).find((candidate) => candidate.text() === 'Open Migration')
			expect(link.props('to')).toEqual({ name: 'migration', hash: '#copy' })
			expect(wrapper.text()).toContain('The archive from the Migration page holds the same files.')
			expect(wrapper.text()).not.toContain('Settings → Migration')
		})

		it('is offered while the account is switched off, and does nothing when the password dialog is dismissed', async () => {
			serverHas(identity({ active: false, state: 'deactivated' }))
			vi.mocked(confirmPassword).mockRejectedValueOnce(new Error('dismissed'))
			const wrapper = mountSettings()
			await flushPromises()

			await buttonByText(wrapper, 'Download your Bluesky account').trigger('click')
			await flushPromises()

			expect(assign).not.toHaveBeenCalled()
		})
	})

	describe('the recovery phrase', () => {
		it('says what the phrase is and what the server\'s key means for it', async () => {
			for (const hasOne of [false, true]) {
				serverHas(identity({ recovery_key: hasOne }))
				const wrapper = mountSettings()
				await flushPromises()

				expect(wrapper.text()).toContain('the recovery tool below does, and when they are made you also see the key they stand for')
				expect(wrapper.text()).toContain('for 72 hours the server can undo a change made with the phrase, a move to another server included')
			}
		})

		/** The tool that uses the words without this server: a file to keep, and the page here. */
		it('offers the recovery tool to download and to open', async () => {
			serverHas()
			const wrapper = mountSettings()
			await flushPromises()

			const download = wrapper.findAll('a').find((link) => link.text() === 'Download the recovery tool')
			expect(download.attributes('href')).toBe('/index.php/apps/social/bluesky-recovery/download')
			expect(download.attributes('download')).toBe('bluesky-recovery-tool.html')
			const open = wrapper.findAll('a').find((link) => link.text() === 'Open it here')
			expect(open.attributes('href')).toBe('/index.php/apps/social/bluesky-recovery')
		})

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

		/** The words are this app's own encoding; the key beside them is what goat and the reference PDS read. */
		it('shows the key the words stand for in the forms other tools take, and forgets it with the words', async () => {
			serverHas()
			const key = { did_key: 'did:key:zQ3shX448VC3xo912wY7zzdUFdMqGifmFTv9GomCodivqkgkt', multibase: 'z3vLe2mABz9GSzoARHpVNW8P1rnySJ8rmfg3AKSpyVfHE526', hex: '950f8acc1852f1a2562b2851457a04b1ba22541dd0f3b95ab9302d2894f29397' }
			axios.post.mockResolvedValue({ data: { ...identity({ recovery_key: true }), phrase: PHRASE, key } })
			const writeText = vi.fn().mockResolvedValue(undefined)
			Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
			const wrapper = mountSettings()
			await flushPromises()

			await buttonByText(wrapper, 'Create recovery phrase').trigger('click')
			await flushPromises()

			const dialog = wrapper.find('.dialog-stub')
			expect(dialog.text()).toContain('Keep the twelve words: they are the whole key.')
			expect(dialog.findAll('code').map((code) => code.text())).toEqual([PHRASE, key.multibase, key.hex, key.did_key])
			await buttonByLabel(wrapper, 'Copy the private key in multibase').trigger('click')
			expect(writeText).toHaveBeenCalledWith(key.multibase)

			wrapper.findComponent({ name: 'NcDialog' }).props('buttons')[1].callback()
			await flushPromises()
			expect(wrapper.vm.recoveryKey).toBe(null)
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

	/**
	 * What a Bluesky app signs in with: a password of its own, shown once,
	 * so the Nextcloud password is never typed into it.
	 */
	describe('app passwords for Bluesky apps', () => {
		const APP_PASSWORDS = '/index.php/apps/social/api/v1/social/bluesky/app-passwords'
		const PASSWORD = 'abcd-efgh-ijkl-mnop'
		const NOW = Math.floor(Date.now() / 1000)
		const PHONE = { id: 1, name: 'Graysky on my phone', creation: NOW - 7 * 24 * 3600, last_used: NOW - 2 * 3600 }
		const TABLET = { id: 2, name: 'Tablet', creation: NOW - 24 * 3600, last_used: 0 }

		/**
		 * @param {object} answer what the identity route answers
		 * @param {Promise<object>} passwords what the app-passwords route answers
		 */
		function serverHasPasswords(answer = identity(), passwords = Promise.resolve({ data: { app_passwords: [PHONE, TABLET] } })) {
			axios.get.mockImplementation((url) => {
				if (url === IDENTITY) {
					return Promise.resolve({ data: answer })
				}
				if (url === APP_PASSWORDS) {
					return passwords
				}
				return Promise.reject(new Error(`unexpected ${url}`))
			})
		}

		const section = (wrapper) => wrapper.find('.bluesky-settings__app-passwords')
		const rows = (wrapper) => wrapper.findAll('.bluesky-settings__app-password')
		const nameField = (wrapper) => section(wrapper).find('input')

		it('lists them, with when each was made and last used', async () => {
			serverHasPasswords()
			const wrapper = mountSettings()
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith(APP_PASSWORDS)
			expect(section(wrapper).text()).toContain('App passwords for Bluesky apps')
			expect(section(wrapper).text()).toContain('Make a separate app password for each client')
			expect(section(wrapper).text()).toContain('Each password works only with your Bluesky account here.')
			expect(rows(wrapper)).toHaveLength(2)
			expect(rows(wrapper)[0].text()).toContain('Graysky on my phone')
			expect(rows(wrapper)[0].text()).toContain('Made ')
			expect(rows(wrapper)[0].text()).toContain('Last used 2 hours ago')
			expect(rows(wrapper)[1].text()).toContain('Tablet')
			expect(rows(wrapper)[1].text()).toContain('Never used')
			// the password itself is never listed
			expect(section(wrapper).find('.bluesky-settings__new-password').exists()).toBe(false)
		})

		it('is not drawn when the server has no such route', async () => {
			serverHasPasswords(identity(), Promise.reject({ response: { status: 404 } }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(wrapper.find('.bluesky-settings').exists()).toBe(true)
			expect(section(wrapper).exists()).toBe(false)
		})

		it('says so when they could not be read', async () => {
			serverHasPasswords(identity(), Promise.reject(new Error('offline')))
			const wrapper = mountSettings()
			await flushPromises()

			expect(section(wrapper).text()).toContain('Could not read your app passwords right now.')
			expect(rows(wrapper)).toHaveLength(0)
		})

		it('is neither drawn nor asked for while the account is paused on Bluesky', async () => {
			serverHasPasswords(identity({ active: false }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(section(wrapper).exists()).toBe(false)
			expect(axios.get).not.toHaveBeenCalledWith(APP_PASSWORDS)
		})

		it('is asked for once the account is switched back on', async () => {
			serverHasPasswords(identity({ active: false }))
			axios.post.mockResolvedValue({ data: identity({ active: true }) })
			const wrapper = mountSettings()
			await flushPromises()

			await wrapper.find('.bluesky-settings__switch input').setValue(true)
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith(APP_PASSWORDS)
			expect(rows(wrapper)).toHaveLength(2)
		})

		it('asks for the password, then shows the new one once, to copy', async () => {
			serverHasPasswords()
			axios.post.mockResolvedValue({
				data: { id: 3, name: 'Bluesky', password: PASSWORD, app_passwords: [PHONE, TABLET, { id: 3, name: 'Bluesky', creation: NOW, last_used: 0 }] },
			})
			const writeText = vi.fn().mockResolvedValue(undefined)
			Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
			const wrapper = mountSettings()
			await flushPromises()

			await nameField(wrapper).setValue('  Bluesky ')
			await buttonByText(wrapper, 'Make an app password').trigger('click')
			await flushPromises()

			expect(confirmPassword).toHaveBeenCalled()
			expect(axios.post).toHaveBeenCalledWith(APP_PASSWORDS, { name: 'Bluesky', privileged: false })
			const box = section(wrapper).find('.bluesky-settings__new-password')
			expect(box.text()).toContain('New app password for Bluesky')
			expect(box.find('code').text()).toBe(PASSWORD)
			expect(box.text()).toContain('Copy it now: it is not shown again.')
			expect(rows(wrapper)).toHaveLength(3)
			expect(nameField(wrapper).element.value).toBe('')

			await buttonByLabel(wrapper, 'Copy the app password').trigger('click')
			await flushPromises()
			expect(writeText).toHaveBeenCalledWith(PASSWORD)

			await buttonByText(wrapper, 'Done').trigger('click')
			expect(section(wrapper).find('.bluesky-settings__new-password').exists()).toBe(false)
			expect(section(wrapper).text()).not.toContain(PASSWORD)
		})

		it('makes a privileged one for the direct messages, and says which ones are', async () => {
			serverHasPasswords()
			axios.post.mockResolvedValue({ data: { id: 3, name: 'Chat', password: PASSWORD, privileged: true, app_passwords: [PHONE, TABLET, { id: 3, name: 'Chat', creation: NOW, last_used: 0, privileged: true }] } })
			const wrapper = mountSettings()
			await flushPromises()

			await nameField(wrapper).setValue('Chat')
			wrapper.findComponent({ name: 'BlueskySettings' }).vm.appPasswordPrivileged = true
			await buttonByText(wrapper, 'Make an app password').trigger('click')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(APP_PASSWORDS, { name: 'Chat', privileged: true })
			expect(rows(wrapper)[2].text()).toContain('Can read and send direct messages')
			expect(rows(wrapper)[0].text()).not.toContain('direct messages')
		})

		it('makes one on Enter', async () => {
			serverHasPasswords()
			axios.post.mockResolvedValue({ data: { id: 3, name: 'Bluesky', password: PASSWORD, app_passwords: [PHONE, TABLET] } })
			const wrapper = mountSettings()
			await flushPromises()

			await nameField(wrapper).setValue('Bluesky')
			await nameField(wrapper).trigger('keydown', { key: 'Enter' })
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(APP_PASSWORDS, { name: 'Bluesky', privileged: false })
		})

		it('makes nothing when the password dialog is dismissed', async () => {
			serverHasPasswords()
			vi.mocked(confirmPassword).mockRejectedValueOnce(new Error('dismissed'))
			const wrapper = mountSettings()
			await flushPromises()

			await nameField(wrapper).setValue('Bluesky')
			await buttonByText(wrapper, 'Make an app password').trigger('click')
			await flushPromises()

			expect(axios.post).not.toHaveBeenCalled()
			expect(section(wrapper).find('.bluesky-settings__new-password').exists()).toBe(false)
		})

		it('cannot be made without a name', async () => {
			serverHasPasswords()
			const wrapper = mountSettings()
			await flushPromises()

			await nameField(wrapper).setValue('   ')

			expect(buttonByText(wrapper, 'Make an app password').attributes('disabled')).toBeDefined()
		})

		it('says what was wrong with the name under the field', async () => {
			serverHasPasswords()
			axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'There is an app password with that name', code: 'app-password-name-taken' } } })
			const wrapper = mountSettings()
			await flushPromises()

			await nameField(wrapper).setValue('Tablet')
			await buttonByText(wrapper, 'Make an app password').trigger('click')
			await flushPromises()

			const create = section(wrapper).find('.bluesky-settings__app-password-create')
			expect(create.find('[role="alert"]').text()).toBe('You have an app password with that name already. Pick another name.')
			expect(create.find('.server-error__details code').text()).toBe('There is an app password with that name')
			expect(showError).not.toHaveBeenCalled()
			expect(section(wrapper).find('.bluesky-settings__new-password').exists()).toBe(false)
			expect(nameField(wrapper).element.value).toBe('Tablet')
		})

		it('asks for the password again when the server says the confirmation ran out', async () => {
			serverHasPasswords()
			axios.post.mockRejectedValue({ response: { status: 403 } })
			const wrapper = mountSettings()
			await flushPromises()

			await nameField(wrapper).setValue('Bluesky')
			await buttonByText(wrapper, 'Make an app password').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Confirm your password again and retry.')
		})

		it('revokes one after asking, and follows the list the server answered', async () => {
			serverHasPasswords()
			axios.delete.mockResolvedValue({ data: { app_passwords: [TABLET] } })
			const wrapper = mountSettings()
			await flushPromises()

			await rows(wrapper)[0].findAll('button').find((button) => button.text() === 'Revoke').trigger('click')
			expect(axios.delete).not.toHaveBeenCalled()
			expect(rows(wrapper)[0].text()).toContain('signed out')

			await buttonByText(wrapper, 'Revoke it').trigger('click')
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(`${APP_PASSWORDS}/1`)
			expect(rows(wrapper)).toHaveLength(1)
			expect(rows(wrapper)[0].text()).toContain('Tablet')
		})

		it('keeps one when the revocation is called off', async () => {
			serverHasPasswords()
			const wrapper = mountSettings()
			await flushPromises()

			await rows(wrapper)[0].findAll('button').find((button) => button.text() === 'Revoke').trigger('click')
			await buttonByText(wrapper, 'Keep it').trigger('click')

			expect(axios.delete).not.toHaveBeenCalled()
			expect(buttonByText(wrapper, 'Revoke it')).toBeUndefined()
			expect(rows(wrapper)).toHaveLength(2)
		})

		it('says so when one could not be revoked', async () => {
			serverHasPasswords()
			axios.delete.mockRejectedValue(new Error('offline'))
			const wrapper = mountSettings()
			await flushPromises()

			await rows(wrapper)[0].findAll('button').find((button) => button.text() === 'Revoke').trigger('click')
			await buttonByText(wrapper, 'Revoke it').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not revoke that app password')
			expect(rows(wrapper)).toHaveLength(2)
		})
	})

	describe('apps signed in with OAuth', () => {
		const SESSIONS = '/index.php/apps/social/api/v1/social/bluesky/oauth-sessions'
		const NOW = Math.floor(Date.now() / 1000)
		const BSKY = {
			id: 7,
			client_id: 'https://oauth.example.org/client-metadata.json',
			client: 'OAuth client',
			scopes: ['atproto', 'transition:generic', 'transition:email'],
			created: NOW - 3 * 24 * 3600,
			last_used: NOW - 2 * 3600,
		}
		const LOCAL = {
			id: 8,
			client_id: 'http://localhost?redirect_uri=http%3A%2F%2F127.0.0.1%2Fcallback',
			client: 'localhost',
			scopes: ['atproto', 'some:other'],
			created: NOW - 24 * 3600,
			last_used: 0,
		}

		/**
		 * @param {object} answer what the identity route answers
		 * @param {Promise<object>} sessions what the sessions route answers
		 */
		function serverHasSessions(answer = identity(), sessions = Promise.resolve({ data: { sessions: [BSKY, LOCAL] } })) {
			axios.get.mockImplementation((url) => {
				if (url === IDENTITY) {
					return Promise.resolve({ data: answer })
				}
				if (url === SESSIONS) {
					return sessions
				}
				if (url.endsWith('/app-passwords')) {
					return Promise.resolve({ data: { app_passwords: [] } })
				}
				return Promise.reject(new Error(`unexpected ${url}`))
			})
		}

		const section = (wrapper) => wrapper.find('.bluesky-settings__oauth-sessions')
		const rows = (wrapper) => wrapper.findAll('.bluesky-settings__oauth-session')
		const signOutButton = (row) => row.findAll('button').find((button) => button.text() === 'Sign out')

		it('lists them by host, with the client_id, the dates and what each may do', async () => {
			serverHasSessions()
			const wrapper = mountSettings()
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith(SESSIONS)
			expect(section(wrapper).text()).toContain('Apps signed in with OAuth')
			expect(section(wrapper).text()).toContain('AT Protocol OAuth flow here. Signing one out stops it at once.')
			expect(rows(wrapper)).toHaveLength(2)

			const [bsky, local] = rows(wrapper)
			expect(bsky.find('.bluesky-settings__app-password-name').text()).toBe('OAuth client')
			// the one thing about the app that is checked stays readable
			expect(bsky.find('.bluesky-settings__oauth-client-id').text()).toBe('https://oauth.example.org/client-metadata.json')
			expect(bsky.text()).toContain('Signed in ')
			expect(bsky.text()).toContain('Last used 2 hours ago')
			expect(bsky.findAll('.bluesky-settings__oauth-grants li').map((li) => li.text()))
				.toEqual(['Post, like, follow and read as you', 'See your e-mail address'])

			expect(local.find('.bluesky-settings__oauth-client-id').text())
				.toBe('http://localhost?redirect_uri=http%3A%2F%2F127.0.0.1%2Fcallback')
			expect(local.text()).toContain('Never used')
			expect(local.findAll('.bluesky-settings__oauth-grants li').map((li) => li.text()))
				.toEqual(['Know who you are'])
		})

		it('says so when no app has signed in', async () => {
			serverHasSessions(identity(), Promise.resolve({ data: { sessions: [] } }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(section(wrapper).text()).toContain('No app has signed in this way.')
			expect(rows(wrapper)).toHaveLength(0)
		})

		it('is not drawn when the server has no such route', async () => {
			serverHasSessions(identity(), Promise.reject({ response: { status: 404 } }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(wrapper.find('.bluesky-settings').exists()).toBe(true)
			expect(section(wrapper).exists()).toBe(false)
		})

		it('says so when they could not be read', async () => {
			serverHasSessions(identity(), Promise.reject(new Error('offline')))
			const wrapper = mountSettings()
			await flushPromises()

			expect(section(wrapper).text()).toContain('Could not read your signed-in apps right now.')
			expect(rows(wrapper)).toHaveLength(0)
		})

		it('is neither drawn nor asked for while the account is paused on Bluesky', async () => {
			serverHasSessions(identity({ active: false }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(section(wrapper).exists()).toBe(false)
			expect(axios.get).not.toHaveBeenCalledWith(SESSIONS)
		})

		it('signs one out after asking, and follows the list the server answered', async () => {
			serverHasSessions()
			axios.delete.mockResolvedValue({ data: { sessions: [LOCAL] } })
			const wrapper = mountSettings()
			await flushPromises()

			await signOutButton(rows(wrapper)[0]).trigger('click')
			expect(axios.delete).not.toHaveBeenCalled()
			expect(rows(wrapper)[0].text()).toContain('This app is signed out and has to ask you again to sign in.')

			await buttonByText(wrapper, 'Sign it out').trigger('click')
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(`${SESSIONS}/7`)
			expect(rows(wrapper)).toHaveLength(1)
			expect(rows(wrapper)[0].text()).toContain('localhost')
			expect(buttonByText(wrapper, 'Sign it out')).toBeUndefined()
		})

		it('keeps one signed in when the sign-out is called off', async () => {
			serverHasSessions()
			const wrapper = mountSettings()
			await flushPromises()

			await signOutButton(rows(wrapper)[0]).trigger('click')
			await buttonByText(wrapper, 'Keep it').trigger('click')

			expect(axios.delete).not.toHaveBeenCalled()
			expect(buttonByText(wrapper, 'Sign it out')).toBeUndefined()
			expect(rows(wrapper)).toHaveLength(2)
		})

		it('says so when one could not be signed out', async () => {
			serverHasSessions()
			axios.delete.mockRejectedValue(new Error('offline'))
			const wrapper = mountSettings()
			await flushPromises()

			await signOutButton(rows(wrapper)[0]).trigger('click')
			await buttonByText(wrapper, 'Sign it out').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not sign the app out')
			expect(rows(wrapper)).toHaveLength(2)
		})
	})

	describe('your own domain as your handle', () => {
		const HANDLE = '/index.php/apps/social/api/v1/social/bluesky/handle'

		/** @param {object} overrides what differs from an identity on its assigned handle */
		function handleIdentity(overrides = {}) {
			return identity({
				active: true,
				assigned_handle: 'alice.cloud.example.org',
				custom_handle: '',
				custom_handle_broken: false,
				...overrides,
			})
		}

		const OWN = { handle: 'alice.example.org', custom_handle: 'alice.example.org', url: 'https://bsky.app/profile/alice.example.org' }

		const section = (wrapper) => wrapper.find('.bluesky-settings__custom-handle')
		const domainField = (wrapper) => section(wrapper).find('input')
		const steps = (wrapper) => section(wrapper).find('.bluesky-settings__custom-handle-steps')
		const codes = (wrapper) => steps(wrapper).findAll('code').map((code) => code.text())

		it('says what it is for, with the assigned handle that keeps working', async () => {
			serverHas(handleIdentity())
			const wrapper = mountSettings()
			await flushPromises()

			expect(section(wrapper).text()).toContain('Your own domain as your handle')
			expect(section(wrapper).text()).toContain('If you own a domain, it can be your Bluesky handle. Your followers stay with you; alice.cloud.example.org keeps working too.')
			expect(steps(wrapper).exists()).toBe(false)
		})

		it('spells out the record and the file for the domain as typed, with the DID', async () => {
			serverHas(handleIdentity())
			const writeText = vi.fn().mockResolvedValue(undefined)
			Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
			const wrapper = mountSettings()
			await flushPromises()

			await domainField(wrapper).setValue('  @Alice.Example.ORG ')

			expect(steps(wrapper).text()).toContain('Add one of these, then check it:')
			expect(steps(wrapper).text()).toContain('A DNS TXT record')
			expect(steps(wrapper).text()).toContain('Or a file on the domain’s web server')
			expect(codes(wrapper)).toEqual([
				'_atproto.alice.example.org',
				'did=did:plc:abc123',
				'https://alice.example.org/.well-known/atproto-did',
				'did:plc:abc123',
			])

			await domainField(wrapper).setValue('bob.example.net')
			expect(codes(wrapper)[0]).toBe('_atproto.bob.example.net')

			await buttonByLabel(wrapper, 'Copy the record name').trigger('click')
			await flushPromises()
			expect(writeText).toHaveBeenCalledWith('_atproto.bob.example.net')
			await buttonByLabel(wrapper, 'Copy the record value').trigger('click')
			await flushPromises()
			expect(writeText).toHaveBeenCalledWith('did=did:plc:abc123')
			await buttonByLabel(wrapper, 'Copy the file address').trigger('click')
			await flushPromises()
			expect(writeText).toHaveBeenCalledWith('https://bob.example.net/.well-known/atproto-did')
			await buttonByLabel(wrapper, 'Copy the file content').trigger('click')
			await flushPromises()
			expect(writeText).toHaveBeenCalledWith('did:plc:abc123')
		})

		it('cannot be checked without a domain', async () => {
			serverHas(handleIdentity())
			const wrapper = mountSettings()
			await flushPromises()

			expect(buttonByText(wrapper, 'Check and use it').attributes('disabled')).toBeDefined()
			await domainField(wrapper).setValue(' @ ')
			expect(buttonByText(wrapper, 'Check and use it').attributes('disabled')).toBeDefined()
			expect(steps(wrapper).exists()).toBe(false)
		})

		it('asks for the password, then sets the domain and shows it everywhere', async () => {
			serverHas(handleIdentity())
			axios.post.mockResolvedValue({ data: handleIdentity(OWN) })
			const wrapper = mountSettings()
			await flushPromises()

			await domainField(wrapper).setValue('@Alice.Example.org')
			await buttonByText(wrapper, 'Check and use it').trigger('click')
			await flushPromises()

			expect(confirmPassword).toHaveBeenCalled()
			expect(axios.post).toHaveBeenCalledWith(HANDLE, { handle: 'alice.example.org' })
			expect(showSuccess).toHaveBeenCalledWith('Your handle is now alice.example.org')
			expect(wrapper.find('a.bluesky-settings__code').text()).toBe('@alice.example.org')
			expect(section(wrapper).text()).toContain('Your handle is alice.example.org.')
			expect(section(wrapper).find('input').exists()).toBe(false)
		})

		it('sends nothing when the password dialog is dismissed', async () => {
			serverHas(handleIdentity())
			vi.mocked(confirmPassword).mockRejectedValueOnce(new Error('dismissed'))
			const wrapper = mountSettings()
			await flushPromises()

			await domainField(wrapper).setValue('alice.example.org')
			await buttonByText(wrapper, 'Check and use it').trigger('click')
			await flushPromises()

			expect(axios.post).not.toHaveBeenCalled()
			expect(domainField(wrapper).element.value).toBe('alice.example.org')
		})

		it('says what was wrong with the domain under the field', async () => {
			serverHas(handleIdentity())
			axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'Neither _atproto.alice.example.org nor https://alice.example.org/.well-known/atproto-did names did:plc:abc123 yet', code: 'handle-not-verified' } } })
			const wrapper = mountSettings()
			await flushPromises()

			await domainField(wrapper).setValue('alice.example.org')
			await buttonByText(wrapper, 'Check and use it').trigger('click')
			await flushPromises()

			expect(section(wrapper).find('[role="alert"]').text()).toBe('That domain does not point at your account yet. Add the record shown below, wait a few minutes for it to spread, and try again.')
			expect(section(wrapper).find('.server-error__details code').text()).toContain('_atproto.alice.example.org')
			expect(showError).not.toHaveBeenCalled()
			expect(showSuccess).not.toHaveBeenCalled()
			expect(domainField(wrapper).element.value).toBe('alice.example.org')

			// typing again clears what the server said
			await domainField(wrapper).setValue('alice.example.com')
			expect(section(wrapper).find('[role="alert"]').exists()).toBe(false)
		})

		it('asks for the password again when the server says the confirmation ran out', async () => {
			serverHas(handleIdentity())
			axios.post.mockRejectedValue({ response: { status: 403 } })
			const wrapper = mountSettings()
			await flushPromises()

			await domainField(wrapper).setValue('alice.example.org')
			await buttonByText(wrapper, 'Check and use it').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Confirm your password again and retry.')
		})

		it('says so when the handle could not be set', async () => {
			serverHas(handleIdentity())
			axios.post.mockRejectedValue(new Error('offline'))
			const wrapper = mountSettings()
			await flushPromises()

			await domainField(wrapper).setValue('alice.example.org')
			await buttonByText(wrapper, 'Check and use it').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not set the handle')
		})

		it('sets it on Enter', async () => {
			serverHas(handleIdentity())
			axios.post.mockResolvedValue({ data: handleIdentity(OWN) })
			const wrapper = mountSettings()
			await flushPromises()

			await domainField(wrapper).setValue('alice.example.org')
			await domainField(wrapper).trigger('keydown', { key: 'Enter' })
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(HANDLE, { handle: 'alice.example.org' })
		})

		it('shows the domain in use, and nothing more while it still names the account', async () => {
			serverHas(handleIdentity(OWN))
			const wrapper = mountSettings()
			await flushPromises()

			expect(section(wrapper).text()).toContain('Your handle is alice.example.org.')
			expect(section(wrapper).text()).not.toContain('no longer names your account')
			expect(buttonByText(wrapper, 'Use alice.cloud.example.org again')).toBeDefined()
			expect(section(wrapper).find('input').exists()).toBe(false)
		})

		it('warns when the domain stopped naming the account', async () => {
			serverHas(handleIdentity({ ...OWN, custom_handle_broken: true }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(section(wrapper).text()).toContain('alice.example.org no longer names your account. Bluesky shows it as invalid until the DNS record or file is back.')
		})

		it('goes back to the assigned handle after asking, and follows what the server answered', async () => {
			serverHas(handleIdentity(OWN))
			axios.delete.mockResolvedValue({ data: handleIdentity() })
			const wrapper = mountSettings()
			await flushPromises()

			await buttonByText(wrapper, 'Use alice.cloud.example.org again').trigger('click')
			expect(axios.delete).not.toHaveBeenCalled()
			expect(section(wrapper).text()).toContain('Bluesky shows alice.cloud.example.org as your handle again.')

			await buttonByText(wrapper, 'Use it again').trigger('click')
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(HANDLE)
			expect(wrapper.find('a.bluesky-settings__code').text()).toBe('@alice.cloud.example.org')
			expect(section(wrapper).text()).not.toContain('Your handle is alice.example.org.')
			expect(domainField(wrapper).exists()).toBe(true)
		})

		it('keeps the domain when going back is called off', async () => {
			serverHas(handleIdentity(OWN))
			const wrapper = mountSettings()
			await flushPromises()

			await buttonByText(wrapper, 'Use alice.cloud.example.org again').trigger('click')
			await buttonByText(wrapper, 'Keep my domain').trigger('click')

			expect(axios.delete).not.toHaveBeenCalled()
			expect(buttonByText(wrapper, 'Use it again')).toBeUndefined()
			expect(section(wrapper).text()).toContain('Your handle is alice.example.org.')
		})

		it('says so when the handle could not be changed back', async () => {
			serverHas(handleIdentity(OWN))
			axios.delete.mockRejectedValue(new Error('offline'))
			const wrapper = mountSettings()
			await flushPromises()

			await buttonByText(wrapper, 'Use alice.cloud.example.org again').trigger('click')
			await buttonByText(wrapper, 'Use it again').trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Could not change the handle')
			expect(section(wrapper).text()).toContain('Your handle is alice.example.org.')
		})

		it('is not drawn while the account is paused on Bluesky', async () => {
			serverHas(handleIdentity({ active: false }))
			const wrapper = mountSettings()
			await flushPromises()

			expect(wrapper.find('.bluesky-settings').exists()).toBe(true)
			expect(section(wrapper).exists()).toBe(false)
		})
	})

	describe('publishing blocks', () => {
		it('is off until the person turns it on, and says what it means either way', async () => {
			const { default: axios } = await import('@nextcloud/axios')
			serverHas()
			axios.post.mockResolvedValue({ data: { publish_blocks: true } })
			const wrapper = mountSettings()
			await flushPromises()
			const settings = wrapper.findComponent({ name: 'BlueskySettings' })

			expect(settings.vm.publishBlocks).toBe(false)
			expect(wrapper.text()).toContain('Your blocks stay on this server')

			await settings.vm.setPublishBlocks(true)
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/api/v1/social/bluesky/publish-blocks'), { publish: true })
			expect(wrapper.text()).toContain('Bluesky blocks are public')
		})
	})
})
