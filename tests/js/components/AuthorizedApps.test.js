/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AuthorizedApps from '../../../src/components/AuthorizedApps.vue'

const { get, del } = vi.hoisted(() => ({ get: vi.fn(), del: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, delete: del } }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

function app(overrides = {}) {
	return {
		id: 4,
		name: 'Tusky',
		website: 'https://tusky.app',
		scopes: ['read', 'write'],
		created_at: 1757000000,
		last_used_at: 1757800000,
		signed_in: true,
		...overrides,
	}
}

const rows = (wrapper) => wrapper.findAll('.apps__item')
function buttonByText(scope, text) {
	return scope.findAll('button').find((button) => button.text() === text)
}

describe('the authorized apps', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: [] })
		del.mockReset().mockResolvedValue({ data: {} })
		showError.mockReset()
	})

	it('asks for what this account has signed in to', async () => {
		mount(AuthorizedApps)
		await flushPromises()

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/authorized_apps'))
	})

	it('says so plainly when no app holds a key', async () => {
		const wrapper = mount(AuthorizedApps)
		await flushPromises()

		expect(wrapper.text()).toContain('No app has been signed in to this account')
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('shows the app, what it may do and when it was last used', async () => {
		get.mockResolvedValue({ data: [app()] })

		const wrapper = mount(AuthorizedApps)
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(1)
		expect(wrapper.text()).toContain('Tusky')
		expect(wrapper.text()).toContain('read, write')
		expect(wrapper.text()).toContain('Last used')
	})

	it('says when a sign-in runs out however much it is used', async () => {
		get.mockResolvedValue({ data: [app({ expires_at: 1788536000 })] })

		const wrapper = mount(AuthorizedApps)
		await flushPromises()

		expect(wrapper.find('.apps__expires').exists()).toBe(true)
		expect(wrapper.find('.apps__expires').text()).toContain('2026')
	})

	it('says nothing about running out where the instance never signs an app out', async () => {
		get.mockResolvedValue({ data: [app({ expires_at: 0 })] })

		const wrapper = mount(AuthorizedApps)
		await flushPromises()

		expect(wrapper.find('.apps__expires').exists()).toBe(false)
	})

	/**
	 * A unix timestamp read as milliseconds dated every app to January 1970,
	 * which is the kind of wrong that makes the page useless for the one thing
	 * it is for: telling which of these you recognise.
	 */
	it('reads the dates as the seconds the server sends', async () => {
		get.mockResolvedValue({ data: [app()] })

		const wrapper = mount(AuthorizedApps)
		await flushPromises()

		expect(wrapper.text()).not.toContain('1970')
		expect(wrapper.text()).toContain('2025')
	})

	/** A code that was never exchanged is not a sign-in and must not read as one. */
	it('marks an authorization the app never used', async () => {
		get.mockResolvedValue({ data: [app({ signed_in: false })] })

		const wrapper = mount(AuthorizedApps)
		await flushPromises()

		expect(wrapper.text()).toContain('never used it')
		expect(wrapper.text()).not.toContain('Signed in')
	})

	/** Signing an app out cannot be undone, so it is asked about first. */
	it('asks before it signs an app out', async () => {
		get.mockResolvedValue({ data: [app()] })

		const wrapper = mount(AuthorizedApps)
		await flushPromises()
		await buttonByText(wrapper, 'Sign this app out').trigger('click')

		expect(del).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('cannot be undone')
	})

	it('takes the authorization back once it is confirmed', async () => {
		get.mockResolvedValue({ data: [app()] })

		const wrapper = mount(AuthorizedApps)
		await flushPromises()
		await buttonByText(wrapper, 'Sign this app out').trigger('click')
		await buttonByText(wrapper, 'Sign it out').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(expect.stringContaining('/api/v1/authorized_apps/4'))
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('keeps the app when the server refuses', async () => {
		get.mockResolvedValue({ data: [app()] })
		del.mockRejectedValue(new Error('nope'))

		const wrapper = mount(AuthorizedApps)
		await flushPromises()
		await buttonByText(wrapper, 'Sign this app out').trigger('click')
		await buttonByText(wrapper, 'Sign it out').trigger('click')
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(1)
		expect(showError).toHaveBeenCalled()
	})

	it('survives an answer that is not a list', async () => {
		get.mockResolvedValue({ data: { error: 'not a list' } })

		const wrapper = mount(AuthorizedApps)
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(0)
	})
})
