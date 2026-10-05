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
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.atproto-profile__avatar').attributes('src')).toBe('new.jpg')
	})
})
