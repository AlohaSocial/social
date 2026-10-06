/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import AtprotoProfile from '../../../src/views/AtprotoProfile.vue'

const stubs = {
	AtprotoFollowButton: { template: '<button class="follow-stub" />' },
	Composer: { template: '<div class="composer-stub" />' },
	NcAvatar: { name: 'NcAvatar', props: ['url'], template: '<div class="avatardiv"><img class="avatar-img" :src="url"></div>' },
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcModal: { name: 'NcModal', props: ['name'], template: '<div class="modal-stub"><slot /></div>' },
	ProfileStatusCard: { template: '<li class="status-stub" />' },
}

function profile(over = {}) {
	return {
		handle: 'bob.example',
		did: 'did:plc:bob',
		postsCount: 5,
		followsCount: 2,
		followersCount: 3,
		...over,
	}
}

/** the button that opens the profile editor, by the label it carries */
async function openEditor(wrapper) {
	const button = wrapper.findAll('button').find((entry) => entry.text() === 'Edit profile')
	expect(button).toBeTruthy()
	await button.trigger('click')
	await flushPromises()
}

describe('AtprotoProfile', () => {
	afterEach(() => vi.restoreAllMocks())

	it('can retry a transient profile lookup without leaving the error state', async () => {
		const get = vi.spyOn(axios, 'get')
			.mockRejectedValueOnce({ response: { data: { message: 'temporary AppView failure' } } })
			.mockResolvedValueOnce({ data: { profile: profile({ banner: 'banner.jpg' }), statuses: [] } })
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example' },
			global: { stubs },
		})

		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toContain('temporary AppView failure')
		await wrapper.find('[role="alert"] button').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(wrapper.find('.user-profile').exists()).toBe(true)
		expect(wrapper.find('.user-profile__banner').classes()).toContain('user-profile__banner--visible')
		expect(wrapper.find('a[href="https://bsky.app/profile/bob.example"]').exists()).toBe(true)
	})

	it('draws the card the Fediverse profile draws, with the same pieces', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { profile: profile({ displayName: 'Bob', description: 'Hello there' }), statuses: [] },
		})
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example' },
			global: { stubs },
		})

		await flushPromises()

		expect(wrapper.find('.user-profile').exists()).toBe(true)
		expect(wrapper.find('h2').text()).toBe('Bob @bob.example')
		expect(wrapper.find('.user-profile__pronouns').text()).toBe('@bob.example')
		expect(wrapper.find('.user-profile__note').text()).toBe('Hello there')
		const counts = wrapper.findAll('.user-profile__sections .user-profile__count').map((entry) => entry.text())
		expect(counts).toHaveLength(3)
		expect(counts[0]).toContain('5')
	})

	it('keeps the profile editor behind a modal so the header stays a header', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { profile: profile({ displayName: 'Bob' }), statuses: [], viewerCanEdit: true, viewerCanFollow: true },
		})
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example' },
			global: { stubs },
		})

		await flushPromises()
		expect(wrapper.find('.modal-stub').exists()).toBe(false)
		expect(wrapper.find('input#social-atproto-display-name').exists()).toBe(false)

		await openEditor(wrapper)

		expect(wrapper.find('.modal-stub').exists()).toBe(true)
		expect(wrapper.find('input#social-atproto-display-name').exists()).toBe(true)
		expect(wrapper.find('textarea#social-atproto-bio').exists()).toBe(true)
		expect(wrapper.find('#social-atproto-bio-count').text()).toContain('300')
	})

	it('offers no follow button on the profile the reader owns', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { profile: profile(), statuses: [], viewerCanEdit: true, viewerCanFollow: true },
		})
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example' },
			global: { stubs },
		})

		await flushPromises()

		expect(wrapper.find('.follow-stub').exists()).toBe(false)
		expect(wrapper.findAll('button').some((button) => button.text() === 'Edit profile')).toBe(true)
	})

	it('draws its own composer, in the place the surrounding profile has one', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { profile: profile(), statuses: [], viewerCanEdit: true, viewerCanFollow: true },
		})
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example', embedded: true },
			global: { stubs },
		})

		await flushPromises()

		expect(wrapper.find('.composer-stub').exists()).toBe(true)
		// embedded: the column and its padding belong to the page around it
		expect(wrapper.classes()).not.toContain('atproto-profile--page')
	})

	it('offers no composer on a Bluesky profile the reader does not own', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { profile: profile(), statuses: [], viewerCanEdit: false, viewerCanFollow: true },
		})
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example' },
			global: { stubs },
		})

		await flushPromises()

		expect(wrapper.find('.composer-stub').exists()).toBe(false)
	})

	it('refreshes profile media after saving native metadata', async () => {
		const get = vi.spyOn(axios, 'get')
			.mockResolvedValueOnce({ data: { profile: profile({ avatar: 'old.jpg', viewerCanEdit: true }), statuses: [], viewerCanEdit: true, viewerCanFollow: true } })
			.mockResolvedValueOnce({ data: { profile: profile({ avatar: 'new.jpg' }), statuses: [], viewerCanEdit: true, viewerCanFollow: true } })
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { profile: { avatar: 'new.jpg' } } })
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example' },
			global: { stubs },
		})

		await flushPromises()
		await openEditor(wrapper)
		const save = wrapper.findAll('button').find((button) => button.text() === 'Save')
		expect(save).toBeTruthy()
		await save.trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.avatar-img').attributes('src')).toBe('new.jpg')
		expect(wrapper.find('.modal-stub').exists()).toBe(false)
	})

	it('rejects profile images larger than the native limit before saving', () => {
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example' },
			global: { stubs },
		})
		const target = { files: [{ size: 1 * 1024 * 1024 + 1 }], value: 'selected' }

		wrapper.vm.selectProfileImage({ target }, 'avatar')

		expect(target.value).toBe('')
		expect(wrapper.vm.profileImages.avatar).toBeNull()
		expect(wrapper.vm.profileError).toContain('1 MiB')
	})
})
