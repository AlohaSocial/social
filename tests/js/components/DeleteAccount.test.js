/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DeleteAccount from '../../../src/components/DeleteAccount.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

function mountCard(serverData = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData(serverData)
	const wrapper = mount(DeleteAccount, { global: { plugins: [pinia] } })
	useAccountStore().setCredentials({ id: '1', username: 'alice', acct: 'alice@cloud.example' })

	return wrapper
}

function buttonByText(wrapper, text) {
	return wrapper.findAll('button').find((button) => button.text() === text)
}

describe('deleting your own account', () => {
	beforeEach(() => {
		post.mockReset().mockResolvedValue({ data: {} })
		showError.mockReset()
	})

	it('says what deleting takes with it before anything is offered', () => {
		const wrapper = mountCard()

		expect(wrapper.text()).toContain('It cannot be undone')
		expect(wrapper.text()).toContain('Your Nextcloud account is not touched')
	})

	/** It cannot be undone, so the button on its own is never enough. */
	it('asks for the handle rather than deleting on one press', async () => {
		const wrapper = mountCard()
		await flushPromises()
		await buttonByText(wrapper, 'Delete my Aloha Social account').trigger('click')

		expect(post).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('alice@cloud.example')
		expect(wrapper.find('input').exists()).toBe(true)
	})

	it('will not send an empty confirmation', async () => {
		const wrapper = mountCard()
		await flushPromises()
		await buttonByText(wrapper, 'Delete my Aloha Social account').trigger('click')

		expect(buttonByText(wrapper, 'Delete it for good').attributes('disabled')).toBeDefined()
	})

	it('sends the handle that was typed', async () => {
		const wrapper = mountCard()
		await flushPromises()
		await buttonByText(wrapper, 'Delete my Aloha Social account').trigger('click')
		wrapper.vm.typed = ' alice@cloud.example '
		await wrapper.vm.$nextTick()
		await buttonByText(wrapper, 'Delete it for good').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(`${API}/account/delete`, { confirm: 'alice@cloud.example' })
	})

	/** The server's message names the handle to type, which is the whole of the help. */
	it('shows the reason the server refused', async () => {
		post.mockRejectedValue({ response: { data: { error: 'type alice@cloud.example to confirm' } } })
		const wrapper = mountCard()
		await flushPromises()
		await buttonByText(wrapper, 'Delete my Aloha Social account').trigger('click')
		wrapper.vm.typed = 'bob'
		await wrapper.vm.$nextTick()
		await buttonByText(wrapper, 'Delete it for good').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('type alice@cloud.example to confirm')
		expect(wrapper.vm.deleting).toBe(false)
	})

	it('lets the reader back out', async () => {
		const wrapper = mountCard()
		await flushPromises()
		await buttonByText(wrapper, 'Delete my Aloha Social account').trigger('click')
		await buttonByText(wrapper, 'Keep my account').trigger('click')

		expect(post).not.toHaveBeenCalled()
		expect(wrapper.find('input').exists()).toBe(false)
	})

	/** An external user has nothing here but Social, and is told so. */
	it('tells an external user that their whole account goes, and sends them to the login page', async () => {
		post.mockResolvedValue({ data: { result: { deleted: true, userDeleted: true }, status: 1 } })
		const assign = vi.fn()
		const location = window.location
		Object.defineProperty(window, 'location', { configurable: true, value: { ...location, assign, reload: vi.fn() } })
		try {
			const wrapper = mountCard({ externalMedia: { quota: 10, used: 0 } })
			expect(wrapper.text()).toContain('is deleted too, and you are signed out')
			expect(wrapper.text()).not.toContain('Your Nextcloud account is not touched')

			await flushPromises()
			await buttonByText(wrapper, 'Delete my Aloha Social account').trigger('click')
			wrapper.vm.typed = 'alice'
			await wrapper.vm.$nextTick()
			await buttonByText(wrapper, 'Delete it for good').trigger('click')
			await flushPromises()

			expect(assign).toHaveBeenCalledWith('/index.php/apps/social/account-deleted')
		} finally {
			Object.defineProperty(window, 'location', { configurable: true, value: location })
		}
	})
})
