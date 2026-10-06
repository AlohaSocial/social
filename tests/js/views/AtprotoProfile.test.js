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
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcTextArea: { template: '<textarea />' },
	NcTextField: { template: '<input />' },
	ProfileStatusCard: { template: '<li class="status-stub" />' },
}

/** the button that opens the profile editor, by the label it carries */
async function openEditor(wrapper) {
	const button = wrapper.findAll('button').find((entry) => entry.text() === 'Edit Bluesky profile')
	expect(button).toBeTruthy()
	await button.trigger('click')
}

describe('AtprotoProfile', () => {
	afterEach(() => vi.restoreAllMocks())

	it('can retry a transient profile lookup without leaving the error state', async () => {
		const get = vi.spyOn(axios, 'get')
			.mockRejectedValueOnce({ response: { data: { message: 'temporary AppView failure' } } })
			.mockResolvedValueOnce({ data: { profile: { handle: 'bob.example', banner: 'banner.jpg' }, statuses: [] } })
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
		expect(wrapper.find('h2').text()).toBe('@bob.example')
		expect(wrapper.find('.atproto-profile__banner').attributes('src')).toBe('banner.jpg')
		expect(wrapper.find('a[href="https://bsky.app/profile/bob.example"]').exists()).toBe(true)
	})

	it('draws the handle under the display name rather than instead of it', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { profile: { handle: 'bob.example', displayName: 'Bob' }, statuses: [] },
		})
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example' },
			global: { stubs },
		})

		await flushPromises()

		expect(wrapper.find('h2').text()).toBe('Bob')
		expect(wrapper.find('.atproto-profile__handle').text()).toBe('@bob.example')
	})

	it('keeps the profile editor behind a toggle so the header stays a header', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { profile: { handle: 'bob.example' }, statuses: [], viewerCanEdit: true, viewerCanFollow: true },
		})
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example' },
			global: { stubs },
		})

		await flushPromises()
		expect(wrapper.find('form').exists()).toBe(false)

		await openEditor(wrapper)

		expect(wrapper.find('form').exists()).toBe(true)
	})

	it('leaves the composer to the surrounding profile when embedded', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { profile: { handle: 'bob.example' }, statuses: [], viewerCanEdit: true, viewerCanFollow: true },
		})
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example', embedded: true },
			global: { stubs },
		})

		await flushPromises()

		expect(wrapper.find('.composer-stub').exists()).toBe(false)
	})

	it('refreshes profile media after saving native metadata', async () => {
		const get = vi.spyOn(axios, 'get')
			.mockResolvedValueOnce({ data: { profile: { handle: 'bob.example', avatar: 'old.jpg' }, statuses: [], viewerCanEdit: true, viewerCanFollow: true } })
			.mockResolvedValueOnce({ data: { profile: { handle: 'bob.example', avatar: 'new.jpg' }, statuses: [], viewerCanEdit: true, viewerCanFollow: true } })
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { profile: { handle: 'bob.example', avatar: 'new.jpg' } } })
		const wrapper = mount(AtprotoProfile, {
			props: { handle: 'bob.example' },
			global: { stubs },
		})

		await flushPromises()
		await openEditor(wrapper)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.atproto-profile__avatar').attributes('src')).toBe('new.jpg')
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
