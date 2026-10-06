/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import ProfilePageIntegration from '../../../src/views/ProfilePageIntegration.vue'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const ProfileStatusCardStub = { name: 'ProfileStatusCard', props: ['status'], template: '<li class="profile-status-card-stub" />' }
const TimelineSwitcherStub = {
	props: ['options', 'value', 'label'],
	emits: ['update:value'],
	template: '<nav class="feed-switcher"><button v-for="option in options" :key="option.value" @click="$emit(\'update:value\', option.value)">{{ option.label }}</button></nav>',
}

const bob = { id: 'https://cloud.example.org/users/bob', acct: 'bob', username: 'bob', display_name: 'Bob', avatar: 'avatar.png', header: 'banner.png' }
const statuses = [
	{ id: '1', content: '<p>first</p>', account: bob },
	{ id: '2', content: '<p>second</p>', account: bob },
]
const homeStatuses = [
	{ id: '2', content: '<p>second from the home feed</p>', account: { ...bob, acct: 'alice' } },
	{ id: 'home-1', content: '<p>From someone followed</p>', account: { ...bob, acct: 'followed@example.org' } },
]

let get

function mountSection(userId) {
	return mount(ProfilePageIntegration, {
		props: { userId },
		global: { stubs: { ProfileStatusCard: ProfileStatusCardStub, TimelineSwitcher: TimelineSwitcherStub, NcButton: true } },
	})
}

describe('ProfilePageIntegration', () => {
	beforeEach(() => {
		get = vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
			data: url.endsWith('/timelines/home') ? homeStatuses : url.endsWith('/statuses') ? statuses : bob,
		}))
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads profile details and posts by URL-encoded account id', async () => {
		mountSection('bob@remote.example')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/bob%40remote.example')
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/bob%40remote.example/statuses', { params: { limit: 20 } })
	})

	it('renders the Aloha Social profile banner and one entry per post', async () => {
		const wrapper = mountSection('bob')
		await flushPromises()
		expect(wrapper.find('h2').text()).toBe('Aloha Social')
		expect(wrapper.find('.social-profile__banner').attributes('src')).toBe('banner.png')
		expect(wrapper.findAllComponents(ProfileStatusCardStub).map((entry) => entry.props('status'))).toEqual(statuses)
		expect(wrapper.find('.composer-stub').exists()).toBe(false)
	})

	it('places the banner in the native profile header behind the avatar', async () => {
		const header = document.createElement('div')
		header.className = 'profile__header'
		header.innerHTML = '<div class="profile__header__container"><span class="profile__header__container__avatar"></span></div>'
		document.body.appendChild(header)
		try {
			const wrapper = mountSection('bob')
			await flushPromises()
			expect(header.querySelector('.social-profile__header-banner img')?.getAttribute('src')).toBe('banner.png')
			expect(wrapper.find('.social-profile__banner').exists()).toBe(false)
			wrapper.unmount()
		} finally {
			header.remove()
		}
	})

	it('shows My Feed only on the signed-in user profile and opens it on request', async () => {
		const wrapper = mountSection('alice')
		await flushPromises()
		const feedButton = wrapper.findAll('.feed-switcher button').find((button) => button.text() === 'My Feed')
		expect(feedButton).toBeTruthy()
		await feedButton.trigger('click')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/timelines/home', { params: { limit: 20 } })
		expect(wrapper.find('.composer-stub').exists()).toBe(false)
		expect(wrapper.findAllComponents(ProfileStatusCardStub).map((entry) => entry.props('status').id)).toEqual(['2', 'home-1'])
	})

	it('loads local and global public timelines from their Aloha Social API scopes', async () => {
		const wrapper = mountSection('bob')
		await flushPromises()
		await wrapper.findAll('.feed-switcher button').find((button) => button.text() === 'Local').trigger('click')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/timelines/public', { params: { limit: 20, local: true } })
		await wrapper.findAll('.feed-switcher button').find((button) => button.text() === 'Global').trigger('click')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/timelines/public', { params: { limit: 20, local: false } })
	})

	it('does not contact the server without a user id', () => {
		const wrapper = mountSection('')
		expect(get).not.toHaveBeenCalled()
		expect(wrapper.find('h2').text()).toBe('Aloha Social')
	})

	it('keeps the section usable when posts cannot be loaded', async () => {
		get.mockRejectedValue(new Error('404'))
		const wrapper = mountSection('bob')
		await flushPromises()
		expect(wrapper.find('h2').text()).toBe('Aloha Social')
		expect(wrapper.findAllComponents(ProfileStatusCardStub)).toHaveLength(0)
	})
})
