/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import AccountSettings from '../../../src/components/AccountSettings.vue'
import { confirmPassword } from '../../../src/services/externalApi.js'
import { showError, showSuccess } from '../../../src/services/toast.js'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { delete: vi.fn(), get: vi.fn(), patch: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/externalApi.js', () => ({ confirmPassword: vi.fn().mockResolvedValue(undefined) }))
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

/**
 * @param {object} serverData what the page was told about this instance
 * @return {object} the mounted form
 */
function mountSettings(serverData = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData(serverData)

	return mount(AccountSettings, {
		global: {
			plugins: [pinia],
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

/** @param {object} wrapper the mounted card */
const switches = (wrapper) => wrapper.findAll('.account-settings__switch input')
function switchFor(wrapper, label) {
	return wrapper.findAll('.account-settings__switch')
		.find((row) => row.text().includes(label))
}
const saveButton = (wrapper) => wrapper.find('.account-settings__actions button')

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

	it('has nothing to save until something changes', async () => {
		const wrapper = mountSettings()
		await flushPromises()

		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it('sends only what was changed', async () => {
		const wrapper = mountSettings()
		await flushPromises()
		axios.patch.mockResolvedValue({ data: credentials({ locked: true }) })

		await switchFor(wrapper, 'Approve who follows you').find('input').setValue(true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		// the display name and the three other flags are untouched, so a
		// backend that owns the name is never asked to write it back
		expect(axios.patch).toHaveBeenCalledWith(`${API}/accounts/update_credentials`, { locked: true })
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
			await wrapper.find('form').trigger('submit')
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
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith(
			`${API}/accounts/update_credentials`,
			{ source: { privacy: 'public' } },
		)
	})

	it('says so when the settings have been saved', async () => {
		const wrapper = mountSettings()
		await flushPromises()
		axios.patch.mockResolvedValue({ data: credentials({ bot: true }) })

		await switchFor(wrapper, 'This is an automated account').find('input').setValue(true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(showSuccess).toHaveBeenCalledWith('Your account settings have been saved')
	})

	/** The store holds the answer, so the composer's default follows the form. */
	it('leaves the saved account where everything else reads it', async () => {
		const wrapper = mountSettings()
		await flushPromises()
		axios.patch.mockResolvedValue({ data: credentials({ source: { privacy: 'unlisted' } }) })

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'unlisted', text: 'Unlisted' })
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(useAccountStore().defaultPostVisibility).toBe('unlisted')
	})

	it('says nothing and asks for nothing while the account has not come', () => {
		const wrapper = mountSettings()

		expect(wrapper.find('.account-settings__loading').exists()).toBe(true)
		expect(wrapper.find('.account-settings__actions').exists()).toBe(false)
	})

	/**
	 * The same account as Bluesky sees it. Nothing here is a setting: the
	 * identity exists because this server offers one, and the one thing the
	 * person can take away is the phrase that proves it is theirs.
	 */
	describe('the Bluesky identity', () => {
		const OFFERED = { bluesky: { enabled: true, host: 'cloud.example.org' } }
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
				: Promise.resolve({ data: credentials() })))
		}

		const block = (wrapper) => wrapper.find('.account-settings__bluesky')
		const buttonByText = (wrapper, text) => wrapper.findAll('button').find((button) => button.text() === text)
		const buttonByLabel = (wrapper, label) => wrapper.findAll('button').find((button) => button.attributes('aria-label') === label)

		beforeEach(() => {
			vi.mocked(confirmPassword).mockResolvedValue(undefined)
		})

		it('is not drawn on a server that offers none', async () => {
			const wrapper = mountSettings()
			await flushPromises()

			expect(block(wrapper).exists()).toBe(false)
			expect(axios.get).not.toHaveBeenCalledWith(IDENTITY)
		})

		it('asks for the identity, and shows the handle and DID with the reason they exist', async () => {
			serverHas()
			const wrapper = mountSettings(OFFERED)
			await flushPromises()

			expect(axios.get).toHaveBeenCalledWith(IDENTITY)
			expect(block(wrapper).text()).toContain('This account is also reachable on Bluesky, because this server offers one.')
			const link = block(wrapper).find('a.account-settings__code')
			expect(link.text()).toBe('@alice.cloud.example.org')
			expect(link.attributes('href')).toBe('https://bsky.app/profile/alice.cloud.example.org')
			expect(link.attributes('target')).toBe('_blank')
			expect(link.attributes('rel')).toBe('noopener')
			expect(block(wrapper).find('code').text()).toBe('did:plc:abc123')
		})

		it('copies the handle and the DID', async () => {
			serverHas()
			const writeText = vi.fn().mockResolvedValue(undefined)
			Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
			const wrapper = mountSettings(OFFERED)
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
			axios.get.mockImplementation((url) => (url === IDENTITY
				? Promise.reject(new Error('offline'))
				: Promise.resolve({ data: credentials() })))
			const wrapper = mountSettings(OFFERED)
			await flushPromises()

			expect(block(wrapper).text()).toContain('Could not read your Bluesky identity right now.')
			expect(buttonByText(wrapper, 'Create recovery phrase')).toBeUndefined()
		})

		describe('showing posts on Bluesky', () => {
			const STATE = '/index.php/apps/social/api/v1/social/bluesky/state'
			const pauseSwitch = (wrapper) => wrapper.find('.account-settings__bluesky-switch input')

			it('is on while the account is live there, and says nothing more', async () => {
				serverHas(identity({ active: true }))
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				expect(block(wrapper).text()).toContain('Show my posts on Bluesky')
				expect(pauseSwitch(wrapper).element.checked).toBe(true)
				expect(block(wrapper).text()).not.toContain('paused on Bluesky')
			})

			it('pauses the account, and follows what the server answered', async () => {
				serverHas(identity({ active: true }))
				axios.post.mockResolvedValue({ data: identity({ active: false }) })
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				await pauseSwitch(wrapper).setValue(false)
				await flushPromises()

				expect(axios.post).toHaveBeenCalledWith(STATE, { active: false })
				expect(pauseSwitch(wrapper).element.checked).toBe(false)
				expect(block(wrapper).text()).toContain('This account is paused on Bluesky: nothing new is published there')
				// the handle stays: the address is still theirs
				expect(block(wrapper).find('a.account-settings__code').text()).toBe('@alice.cloud.example.org')
			})

			it('switches back on the same way', async () => {
				serverHas(identity({ active: false }))
				axios.post.mockResolvedValue({ data: identity({ active: true }) })
				const wrapper = mountSettings(OFFERED)
				await flushPromises()
				expect(pauseSwitch(wrapper).element.checked).toBe(false)

				await pauseSwitch(wrapper).setValue(true)
				await flushPromises()

				expect(axios.post).toHaveBeenCalledWith(STATE, { active: true })
				expect(block(wrapper).text()).not.toContain('paused on Bluesky')
			})

			it('stays where the server left it when the change is refused', async () => {
				serverHas(identity({ active: true }))
				axios.post.mockRejectedValue(new Error('offline'))
				const wrapper = mountSettings(OFFERED)
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
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				expect(buttonByText(wrapper, 'Create recovery phrase')).toBeDefined()
				expect(block(wrapper).text()).toContain('shown once')
			})

			/** Shown once means a second look is a new phrase, and the old one dies. */
			it('offers a new one once there is one, and says the old one stops working', async () => {
				serverHas(identity({ recovery_key: true }))
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				expect(buttonByText(wrapper, 'Create a new recovery phrase')).toBeDefined()
				expect(block(wrapper).text()).toContain('stops working')
			})

			it('asks for the password, then shows the twelve words once', async () => {
				serverHas()
				axios.post.mockResolvedValue({ data: { ...identity({ recovery_key: true }), phrase: PHRASE } })
				const wrapper = mountSettings(OFFERED)
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
				const wrapper = mountSettings(OFFERED)
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
				const wrapper = mountSettings(OFFERED)
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
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				await buttonByText(wrapper, 'Create recovery phrase').trigger('click')
				await flushPromises()

				expect(showError).toHaveBeenCalledWith('Confirm your password again and retry.')
				expect(wrapper.find('.dialog-stub').exists()).toBe(false)
			})

			it('says so when the phrase could not be made', async () => {
				serverHas()
				axios.post.mockRejectedValue(new Error('offline'))
				const wrapper = mountSettings(OFFERED)
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
					return Promise.resolve({ data: credentials() })
				})
			}

			const section = (wrapper) => wrapper.find('.account-settings__app-passwords')
			const rows = (wrapper) => wrapper.findAll('.account-settings__app-password')
			const nameField = (wrapper) => section(wrapper).find('input')

			it('lists them, with when each was made and last used', async () => {
				serverHasPasswords()
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				expect(axios.get).toHaveBeenCalledWith(APP_PASSWORDS)
				expect(section(wrapper).text()).toContain('App passwords for Bluesky apps')
				expect(section(wrapper).text()).toContain(`choose this server as your hosting provider: ${window.location.origin}.`)
				expect(rows(wrapper)).toHaveLength(2)
				expect(rows(wrapper)[0].text()).toContain('Graysky on my phone')
				expect(rows(wrapper)[0].text()).toContain('Made ')
				expect(rows(wrapper)[0].text()).toContain('Last used 2 hours ago')
				expect(rows(wrapper)[1].text()).toContain('Tablet')
				expect(rows(wrapper)[1].text()).toContain('Never used')
				// the password itself is never listed
				expect(section(wrapper).find('.account-settings__new-password').exists()).toBe(false)
			})

			it('is not drawn when the server has no such route', async () => {
				serverHasPasswords(identity(), Promise.reject({ response: { status: 404 } }))
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				expect(block(wrapper).exists()).toBe(true)
				expect(section(wrapper).exists()).toBe(false)
			})

			it('says so when they could not be read', async () => {
				serverHasPasswords(identity(), Promise.reject(new Error('offline')))
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				expect(section(wrapper).text()).toContain('Could not read your app passwords right now.')
				expect(rows(wrapper)).toHaveLength(0)
			})

			it('is neither drawn nor asked for while the account is paused on Bluesky', async () => {
				serverHasPasswords(identity({ active: false }))
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				expect(section(wrapper).exists()).toBe(false)
				expect(axios.get).not.toHaveBeenCalledWith(APP_PASSWORDS)
			})

			it('is asked for once the account is switched back on', async () => {
				serverHasPasswords(identity({ active: false }))
				axios.post.mockResolvedValue({ data: identity({ active: true }) })
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				await wrapper.find('.account-settings__bluesky-switch input').setValue(true)
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
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				await nameField(wrapper).setValue('  Bluesky ')
				await buttonByText(wrapper, 'Make an app password').trigger('click')
				await flushPromises()

				expect(confirmPassword).toHaveBeenCalled()
				expect(axios.post).toHaveBeenCalledWith(APP_PASSWORDS, { name: 'Bluesky' })
				const box = section(wrapper).find('.account-settings__new-password')
				expect(box.text()).toContain('New app password for Bluesky')
				expect(box.find('code').text()).toBe(PASSWORD)
				expect(box.text()).toContain('Copy it now: it is not shown again.')
				expect(rows(wrapper)).toHaveLength(3)
				expect(nameField(wrapper).element.value).toBe('')

				await buttonByLabel(wrapper, 'Copy the app password').trigger('click')
				await flushPromises()
				expect(writeText).toHaveBeenCalledWith(PASSWORD)

				await buttonByText(wrapper, 'Done').trigger('click')
				expect(section(wrapper).find('.account-settings__new-password').exists()).toBe(false)
				expect(section(wrapper).text()).not.toContain(PASSWORD)
			})

			it('makes one on Enter without saving the account form', async () => {
				serverHasPasswords()
				axios.post.mockResolvedValue({ data: { id: 3, name: 'Bluesky', password: PASSWORD, app_passwords: [PHONE, TABLET] } })
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				await nameField(wrapper).setValue('Bluesky')
				await nameField(wrapper).trigger('keydown', { key: 'Enter' })
				await flushPromises()

				expect(axios.post).toHaveBeenCalledWith(APP_PASSWORDS, { name: 'Bluesky' })
				expect(axios.patch).not.toHaveBeenCalled()
			})

			it('makes nothing when the password dialog is dismissed', async () => {
				serverHasPasswords()
				vi.mocked(confirmPassword).mockRejectedValueOnce(new Error('dismissed'))
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				await nameField(wrapper).setValue('Bluesky')
				await buttonByText(wrapper, 'Make an app password').trigger('click')
				await flushPromises()

				expect(axios.post).not.toHaveBeenCalled()
				expect(section(wrapper).find('.account-settings__new-password').exists()).toBe(false)
			})

			it('cannot be made without a name', async () => {
				serverHasPasswords()
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				await nameField(wrapper).setValue('   ')

				expect(buttonByText(wrapper, 'Make an app password').attributes('disabled')).toBeDefined()
			})

			it('says what was wrong with the name under the field', async () => {
				serverHasPasswords()
				axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'There is an app password with that name' } } })
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				await nameField(wrapper).setValue('Tablet')
				await buttonByText(wrapper, 'Make an app password').trigger('click')
				await flushPromises()

				expect(section(wrapper).find('.account-settings__app-password-create').text())
					.toContain('There is an app password with that name')
				expect(showError).not.toHaveBeenCalled()
				expect(section(wrapper).find('.account-settings__new-password').exists()).toBe(false)
				expect(nameField(wrapper).element.value).toBe('Tablet')
			})

			it('asks for the password again when the server says the confirmation ran out', async () => {
				serverHasPasswords()
				axios.post.mockRejectedValue({ response: { status: 403 } })
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				await nameField(wrapper).setValue('Bluesky')
				await buttonByText(wrapper, 'Make an app password').trigger('click')
				await flushPromises()

				expect(showError).toHaveBeenCalledWith('Confirm your password again and retry.')
			})

			it('revokes one after asking, and follows the list the server answered', async () => {
				serverHasPasswords()
				axios.delete.mockResolvedValue({ data: { app_passwords: [TABLET] } })
				const wrapper = mountSettings(OFFERED)
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
				const wrapper = mountSettings(OFFERED)
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
				const wrapper = mountSettings(OFFERED)
				await flushPromises()

				await rows(wrapper)[0].findAll('button').find((button) => button.text() === 'Revoke').trigger('click')
				await buttonByText(wrapper, 'Revoke it').trigger('click')
				await flushPromises()

				expect(showError).toHaveBeenCalledWith('Could not revoke that app password')
				expect(rows(wrapper)).toHaveLength(2)
			})
		})
	})
})
