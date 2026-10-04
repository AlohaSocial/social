/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global setInitialState */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import OStatus from '../../../src/views/OStatus.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

const NcAvatarStub = {
	name: 'NcAvatar',
	props: ['url', 'user', 'size', 'disableTooltip'],
	template: '<span class="nc-avatar-stub" />',
}
const ActorAvatarStub = {
	name: 'ActorAvatar',
	props: ['actor', 'size'],
	template: '<span class="actor-avatar-stub" />',
}

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob' }
const currentUser = { uid: 'alice', displayName: 'Alice' }

let pinia
let accountStore
let settingsStore
let pending
const fetchAccount = vi.fn(() => pending)

function setState(key, value) {
	setInitialState('social', key, value)
	window._nc_initial_state?.clear()
}

function makeStore() {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	settingsStore = useSettingsStore()
	vi.spyOn(accountStore, 'fetchAccountInfo').mockImplementation(fetchAccount)
	vi.spyOn(accountStore, 'fetchPublicAccountInfo').mockImplementation(fetchAccount)
	vi.spyOn(accountStore, 'followAccount').mockResolvedValue(undefined)

	return pinia
}

const mountView = () => mount(OStatus, { global: { plugins: [pinia], stubs: { NcAvatar: NcAvatarStub, ActorAvatar: ActorAvatarStub } } })

describe('OStatus', () => {
	beforeEach(() => {
		pending = new Promise(() => {})
		fetchAccount.mockClear()
		makeStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	describe('following a remote account from this instance', () => {
		const serverData = { public: false, cloudAddress: 'https://cloud.example.org', account: 'bob@remote.example', local: '', currentUser }

		beforeEach(() => {
			setState('serverData', serverData)
		})

		it('imports the server data and current user and looks up the remote account', () => {
			const wrapper = mountView()
			expect(settingsStore.serverData).toEqual(serverData)
			expect(wrapper.vm.currentUser).toEqual(currentUser)
			expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('bob@remote.example')
			expect(accountStore.fetchPublicAccountInfo).not.toHaveBeenCalled()
		})

		it('asks the logged-in user to confirm, naming the account before it is loaded', () => {
			const wrapper = mountView()
			const headings = wrapper.findAll('h2').map((heading) => heading.text())
			expect(headings).toEqual(['Follow on Aloha Social', 'bob@remote.example'])
			expect(wrapper.text()).toContain('Hello')
			expect(wrapper.text()).toContain('Alice')
			// the greeting avatar is the real ActorAvatar, fed the current user as a local actor
			const greeting = wrapper.findComponent(ActorAvatarStub)
			expect(greeting.props('actor')).toEqual({ username: 'alice', acct: 'alice' })
			expect(greeting.props('size')).toBe(16)
			expect(wrapper.text()).toContain('Please confirm that you want to follow this account:')
			expect(wrapper.find('form input[type="submit"]').element.value).toBe('Follow')
			expect(wrapper.text()).not.toContain('You are following this account')
		})

		it('shows the avatar of the remote account through the app proxy once loaded', async () => {
			pending = Promise.resolve(bob)
			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.findComponent(NcAvatarStub).props('url'))
				.toBe('/index.php/apps/social/api/v1/global/actor/avatar?id=https://remote.example/users/bob')
			expect(wrapper.findComponent(NcAvatarStub).props('size')).toBe(128)
		})

		it('shows the display name from the loaded Mastodon account', async () => {
			pending = Promise.resolve(bob)
			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.findAll('h2').map((heading) => heading.text())).toEqual(['Follow on Aloha Social', 'Bob'])
		})

		it('follows with the cloud id of the current user and the account handle on submit', async () => {
			pending = Promise.resolve(bob)
			const wrapper = mountView()
			await flushPromises()
			await wrapper.find('form').trigger('submit')
			expect(accountStore.followAccount).toHaveBeenCalledWith({ accountToFollow: 'bob@remote.example' })
		})
	})

	describe('following a local account from another instance', () => {
		const serverData = { public: true, cloudAddress: 'https://cloud.example.org', account: '', local: 'carol' }

		beforeEach(() => {
			setState('serverData', serverData)
		})

		it('looks up the local account publicly and explains the redirect', () => {
			const wrapper = mountView()
			expect(accountStore.fetchPublicAccountInfo).toHaveBeenCalledWith('carol')
			expect(accountStore.fetchAccountInfo).not.toHaveBeenCalled()
			expect(wrapper.text()).toContain('You are going to follow:')
			expect(wrapper.find('h2').text()).toBe('carol')
			expect(wrapper.findComponent(NcAvatarStub).props('user')).toBe('carol')
			expect(wrapper.find('input[type="text"]').attributes('placeholder')).toBe('name@domain of your federation account')
			expect(wrapper.find('input[type="submit"]').element.value).toBe('Continue')
			expect(wrapper.text()).toContain('We will redirect you to your homeserver to follow this account.')
		})

		it('resolves the remote follow link for the entered handle and redirects there', async () => {
			const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { result: { url: 'https://other.example/authorize_interaction?uri=carol@cloud.example.org' } } })
			// jsdom cannot navigate: intercept the assignment to window.location
			const descriptor = Object.getOwnPropertyDescriptor(window, 'location')
			const location = window.location
			const navigate = vi.fn()
			Object.defineProperty(window, 'location', { get: () => location, set: navigate, configurable: true })
			try {
				const wrapper = mountView()
				await wrapper.find('input[type="text"]').setValue('dave@other.example')
				await wrapper.find('form').trigger('submit')
				await flushPromises()

				expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/ostatus/link/carol/dave@other.example')
				expect(navigate).toHaveBeenCalledWith('https://other.example/authorize_interaction?uri=carol@cloud.example.org')
				expect(wrapper.find('.ostatus__error').exists()).toBe(false)
			} finally {
				Object.defineProperty(window, 'location', descriptor)
			}
		})

		/**
		 * The remote server names where to continue and the reader is sent
		 * there wholesale; anything but a web address would run here instead.
		 */
		it.each([
			['a script', 'javascript:alert(1)'],
			['a data address', 'data:text/html,<script>alert(1)</script>'],
			['no address at all', undefined],
		])('does not follow %s, and says so instead', async (_, url) => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: { result: { url } } })
			const descriptor = Object.getOwnPropertyDescriptor(window, 'location')
			const location = window.location
			const navigate = vi.fn()
			Object.defineProperty(window, 'location', { get: () => location, set: navigate, configurable: true })
			try {
				const wrapper = mountView()
				await wrapper.find('input[type="text"]').setValue('dave@other.example')
				await wrapper.find('form').trigger('submit')
				await flushPromises()

				expect(navigate).not.toHaveBeenCalled()
				expect(wrapper.find('.ostatus__error').text()).toBe('Could not find your server from that address. Check the name@domain and try again.')
			} finally {
				Object.defineProperty(window, 'location', descriptor)
			}
		})

		it('says so when the link cannot be resolved, and clears it on the next try', async () => {
			const get = vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('404'))
			const wrapper = mountView()
			await wrapper.find('input[type="text"]').setValue('nobody@nowhere.example')
			await wrapper.find('form').trigger('submit')
			await flushPromises()
			expect(wrapper.find('.ostatus__error').exists()).toBe(true)

			// the next attempt starts clean; it never resolves here
			get.mockReturnValueOnce(new Promise(() => {}))
			await wrapper.find('form').trigger('submit')
			expect(wrapper.find('.ostatus__error').exists()).toBe(false)
		})
	})

	it('shows a spinner while no account was resolved yet', async () => {
		setState('serverData', { public: true, cloudAddress: 'https://cloud.example.org', local: 'carol' })
		pending = Promise.resolve(undefined)
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.find('.icon-loading-dark').exists()).toBe(true)
		expect(wrapper.find('h2').exists()).toBe(false)
	})
})
