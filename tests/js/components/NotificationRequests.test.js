/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NotificationRequests from '../../../src/components/NotificationRequests.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const bob = { id: '22', acct: 'bob@remote.tld', username: 'bob', display_name: 'Bob', avatar: '' }
const carol = { id: '33', acct: 'carol@remote.tld', username: 'carol', display_name: 'Carol', avatar: '' }

function request(account, overrides = {}) {
	return {
		id: account.id,
		account,
		notifications_count: '3',
		created_at: '2026-09-01T10:00:00Z',
		updated_at: '2026-09-02T10:00:00Z',
		...overrides,
	}
}

function mountView() {
	return mount(NotificationRequests, {
		global: {
			stubs: { ActorAvatar: true, RouterLink: RouterLinkStub },
		},
	})
}

const rows = (wrapper) => wrapper.findAll('.request')
function buttonByText(scope, text) {
	return scope.findAll('button').find((button) => button.text() === text)
}

describe('the filtered notifications page', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: [] })
		post.mockReset().mockResolvedValue({ data: {} })
		showError.mockReset()
	})

	/**
	 * Somebody with a policy stricter than the default lost mentions with no
	 * way to see that anything had been held, which is worse than not having
	 * the policy at all.
	 */
	it('reads what the policy is holding', async () => {
		mountView()
		await flushPromises()

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/notifications/requests'))
	})

	it('tells the page how many are left, after reading and after every decision', async () => {
		get.mockResolvedValue({ data: [{ id: 1, account: { acct: 'a@x.example', display_name: 'A' }, notifications_count: 2 }, { id: 2, account: { acct: 'b@x.example', display_name: 'B' }, notifications_count: 1 }] })
		post.mockResolvedValue({ data: {} })
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.emitted('changed')).toEqual([[2]])

		await buttonByText(rows(wrapper)[0], 'Show these').trigger('click')
		await flushPromises()

		expect(wrapper.emitted('changed')).toEqual([[2], [1]])
	})

	it('says so plainly when nothing is waiting', async () => {
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.text()).toContain('Nothing is waiting')
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('shows one row per sender, with how many they have sent', async () => {
		get.mockResolvedValue({ data: [request(bob), request(carol, { notifications_count: '1' })] })

		const wrapper = mountView()
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(2)
		expect(wrapper.text()).toContain('Bob')
		expect(wrapper.text()).toContain('3 notifications')
		expect(wrapper.text()).toContain('1 notification')
	})

	it('shows the first words of their most recent post', async () => {
		get.mockResolvedValue({
			data: [request(bob, { last_status: { content: '<p>Buy my thing</p>' } })],
		})

		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.text()).toContain('Buy my thing')
	})

	/** Accepting settles everything that account has sent and will send. */
	it('accepts one sender and takes the row away', async () => {
		get.mockResolvedValue({ data: [request(bob), request(carol)] })

		const wrapper = mountView()
		await flushPromises()
		await buttonByText(rows(wrapper)[0], 'Show these').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/api/v1/notifications/requests/22/accept'))
		expect(rows(wrapper)).toHaveLength(1)
	})

	it('dismisses one sender', async () => {
		get.mockResolvedValue({ data: [request(bob)] })

		const wrapper = mountView()
		await flushPromises()
		await buttonByText(rows(wrapper)[0], 'Dismiss').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/api/v1/notifications/requests/22/dismiss'))
	})

	/**
	 * One decision about the whole inbox is one request: a loop that failed
	 * halfway would leave the page disagreeing with the server about what was
	 * decided.
	 */
	it('decides about every sender in one request', async () => {
		get.mockResolvedValue({ data: [request(bob), request(carol)] })

		const wrapper = mountView()
		await flushPromises()
		await buttonByText(wrapper, 'Show all of them').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/api/v1/notifications/requests/accept'),
			{ id: ['22', '33'] },
		)
		expect(rows(wrapper)).toHaveLength(0)
	})

	/** A row that is still held must not look decided. */
	it('keeps the row when the server refuses', async () => {
		get.mockResolvedValue({ data: [request(bob)] })
		post.mockRejectedValue(new Error('nope'))

		const wrapper = mountView()
		await flushPromises()
		await buttonByText(rows(wrapper)[0], 'Show these').trigger('click')
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(1)
		expect(showError).toHaveBeenCalled()
	})

	it('survives an answer that is not a list', async () => {
		get.mockResolvedValue({ data: { error: 'not a list' } })

		const wrapper = mountView()
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(0)
		expect(wrapper.text()).toContain('Nothing is waiting')
	})
})
