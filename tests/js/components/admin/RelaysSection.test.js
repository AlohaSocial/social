/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RelaysSection from '../../../../src/components/admin/RelaysSection.vue'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, delete: del } }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('../../../../src/services/toast.js', () => ({ showError, showSuccess: vi.fn() }))

function relay(overrides = {}) {
	return {
		id: 3,
		actor_id: 'https://relay.example/actor',
		host: 'relay.example',
		inbox: 'https://relay.example/inbox',
		status: 'accepted',
		error: '',
		...overrides,
	}
}

const rows = (wrapper) => wrapper.findAll('.relays__item')
function buttonByText(scope, text) {
	return scope.findAll('button').find((button) => button.text() === text)
}

describe('the relays card', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: [] })
		post.mockReset().mockResolvedValue({ data: {} })
		del.mockReset().mockResolvedValue({ data: {} })
		showError.mockReset()
	})

	it('says plainly when this server is on no relay', async () => {
		const wrapper = mount(RelaysSection)
		await flushPromises()

		expect(wrapper.text()).toContain('not subscribed to any relay')
		expect(rows(wrapper)).toHaveLength(0)
	})

	/**
	 * A relay only carries public posts, and an administrator deciding whether
	 * to subscribe needs that said before they press anything.
	 */
	it('says what a relay does and does not carry', () => {
		const wrapper = mount(RelaysSection)

		expect(wrapper.text()).toContain('public posts and nothing else')
	})

	it('shows each relay with what came of the subscription', async () => {
		get.mockResolvedValue({
			data: [relay(), relay({ id: 4, host: 'other.example', status: 'pending' })],
		})

		const wrapper = mount(RelaysSection)
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(2)
		expect(wrapper.text()).toContain('Subscribed')
		expect(wrapper.text()).toContain('Waiting for an answer')
	})

	it('shows why a relay refused', async () => {
		get.mockResolvedValue({ data: [relay({ status: 'rejected', error: 'connection refused' })] })

		const wrapper = mount(RelaysSection)
		await flushPromises()

		expect(wrapper.text()).toContain('Refused')
		expect(wrapper.text()).toContain('connection refused')
	})

	it('subscribes with the address that was typed', async () => {
		const wrapper = mount(RelaysSection)
		await flushPromises()
		wrapper.vm.address = '  https://relay.example/actor  '
		await wrapper.vm.$nextTick()
		await buttonByText(wrapper, 'Subscribe').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/admin/relays'),
			{ address: 'https://relay.example/actor' },
		)
	})

	/**
	 * A relay that accepted while the request was in flight is already
	 * accepted, so the list is re-read rather than told what to show.
	 */
	it('re-reads the list after subscribing', async () => {
		const wrapper = mount(RelaysSection)
		await flushPromises()
		get.mockClear()
		wrapper.vm.address = 'https://relay.example/actor'
		await wrapper.vm.$nextTick()
		await buttonByText(wrapper, 'Subscribe').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalled()
		expect(wrapper.vm.address).toBe('')
	})

	it('shows the reason an address was refused', async () => {
		post.mockRejectedValue({ response: { data: { error: 'that address did not answer with an actor' } } })
		const wrapper = mount(RelaysSection)
		await flushPromises()
		wrapper.vm.address = 'https://nothing.example/actor'
		await wrapper.vm.$nextTick()
		await buttonByText(wrapper, 'Subscribe').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('that address did not answer with an actor')
	})

	it('unsubscribes and takes the row away', async () => {
		get.mockResolvedValue({ data: [relay()] })
		const wrapper = mount(RelaysSection)
		await flushPromises()
		await buttonByText(rows(wrapper)[0], 'Unsubscribe').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith(expect.stringContaining('/admin/relays/3'))
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('survives an answer that is not a list', async () => {
		get.mockResolvedValue({ data: { error: 'nope' } })
		const wrapper = mount(RelaysSection)
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(0)
	})
})
