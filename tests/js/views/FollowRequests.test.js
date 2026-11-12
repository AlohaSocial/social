/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'

import FollowRequests from '../../../src/views/FollowRequests.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const bob = { id: '22', acct: 'bob@remote.tld', username: 'bob', display_name: 'Bob', avatar: 'https://remote.tld/bob.png' }
const carol = { id: '33', acct: 'carol@remote.tld', username: 'carol', display_name: 'Carol', avatar: 'https://remote.tld/carol.png' }

async function mountView(requests = [bob, carol], headers = {}) {
	if (requests instanceof Error) {
		axios.get.mockRejectedValueOnce(requests)
	} else {
		axios.get.mockResolvedValueOnce({ data: requests, headers })
	}
	const wrapper = mount(FollowRequests, {
		global: {
			stubs: {
				ActorAvatar: true,
				NcEmptyContent: {
					props: ['name'],
					template: '<div class="empty-content">{{ name }}<slot name="description" /><slot /></div>',
				},
				RouterLink: RouterLinkStub,
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('FollowRequests', () => {
	afterEach(() => {
		vi.clearAllMocks()
	})

	it('lists the accounts waiting for approval', async () => {
		const wrapper = await mountView()

		expect(axios.get).toHaveBeenCalledWith(API + '/follow_requests', { params: { limit: 40 } })
		const entries = wrapper.findAll('.follow-request')
		expect(entries).toHaveLength(2)
		expect(entries[0].text()).toContain('Bob')
		expect(entries[0].text()).toContain('bob@remote.tld')
	})

	it('shows the empty state when nothing is pending', async () => {
		const wrapper = await mountView([])

		expect(wrapper.find('.empty-content').exists()).toBe(true)
		expect(wrapper.findAll('.follow-request')).toHaveLength(0)
	})

	it('offers a retry after the first request fails without claiming the list is empty', async () => {
		const wrapper = await mountView(new Error('offline'))
		expect(wrapper.find('.empty-content').exists()).toBe(false)
		expect(wrapper.get('[role="alert"]').text()).toContain('Failed to load follow requests')

		axios.get.mockResolvedValueOnce({ data: [bob], headers: {} })
		await wrapper.get('[role="alert"] button').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(wrapper.get('.follow-request').text()).toContain('Bob')
	})

	it('sends the reader to the switch the empty state talks about', async () => {
		const wrapper = await mountView([])

		expect(wrapper.find('.empty-content').text()).toContain('When your account is locked')
		expect(wrapper.findComponent(RouterLinkStub).props('to'))
			.toEqual({ name: 'settings', hash: '#account' })
	})

	it('accepts a request and removes it from the list', async () => {
		const wrapper = await mountView()
		axios.post.mockResolvedValueOnce({ data: {} })

		await wrapper.findAll('.follow-request__actions button')[0].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(API + '/follow_requests/22/authorize')
		expect(wrapper.findAll('.follow-request')).toHaveLength(1)
		expect(wrapper.find('.follow-request').text()).toContain('Carol')
	})

	it('rejects a request and removes it from the list', async () => {
		const wrapper = await mountView()
		axios.post.mockResolvedValueOnce({ data: {} })

		await wrapper.findAll('.follow-request__actions button')[1].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(API + '/follow_requests/22/reject')
		expect(wrapper.findAll('.follow-request')).toHaveLength(1)
	})

	it('keeps the entry and reports the error when the decision fails', async () => {
		const wrapper = await mountView()
		axios.post.mockRejectedValueOnce(new Error('nope'))

		await wrapper.findAll('.follow-request__actions button')[0].trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.findAll('.follow-request')).toHaveLength(2)
	})

	describe('more than one page', () => {
		const CURSOR = '1767268800-' + 'a'.repeat(32)
		const NEXT = { link: `<https://cloud.example/apps/social/api/v1/follow_requests?limit=40&max_id=${CURSOR}>; rel="next", <https://cloud.example/apps/social/api/v1/follow_requests?limit=40&min_id=1767268801-${'b'.repeat(32)}>; rel="prev"` }
		const dave = { id: '44', acct: 'dave@remote.tld', username: 'dave', display_name: 'Dave', avatar: '' }

		it('offers no further page when the server names none', async () => {
			const wrapper = await mountView([bob, carol], {
				link: `<https://cloud.example/apps/social/api/v1/follow_requests?min_id=${CURSOR}>; rel="prev"`,
			})

			expect(wrapper.find('.follow-requests__more').exists()).toBe(false)
		})

		it('reaches the requests beyond the first page, from the cursor the server sent', async () => {
			const wrapper = await mountView([bob, carol], NEXT)
			axios.get.mockResolvedValueOnce({ data: [dave], headers: {} })

			await wrapper.find('.follow-requests__more button').trigger('click')
			await flushPromises()

			expect(axios.get).toHaveBeenLastCalledWith(
				API + '/follow_requests',
				{ params: { limit: 40, max_id: CURSOR } },
			)
			expect(wrapper.findAll('.follow-request').map((row) => row.text()))
				.toEqual([expect.stringContaining('Bob'), expect.stringContaining('Carol'), expect.stringContaining('Dave')])
			expect(wrapper.find('.follow-requests__more').exists()).toBe(false)
		})

		it('keeps the cursor it was given when a request on the page is answered first', async () => {
			// the cursor belongs to the row the page ended on, which may be the
			// very one answered: it still names the place the next page starts
			const wrapper = await mountView([bob, carol], NEXT)
			axios.post.mockResolvedValueOnce({ data: {} })
			await wrapper.findAll('.follow-request__actions button')[3].trigger('click')
			await flushPromises()
			axios.get.mockResolvedValueOnce({ data: [dave], headers: {} })

			await wrapper.find('.follow-requests__more button').trigger('click')
			await flushPromises()

			expect(axios.get).toHaveBeenLastCalledWith(
				API + '/follow_requests',
				{ params: { limit: 40, max_id: CURSOR } },
			)
			expect(wrapper.findAll('.follow-request')).toHaveLength(2)
		})
	})

	describe('answering a request', () => {
		it('renders the list through a transition group, so a row can collapse out of it', async () => {
			// the rows were plain siblings: an answered one blinked away and
			// the ones below jumped into the space it left
			const wrapper = await mountView()
			const group = wrapper.find('transition-group-stub')

			expect(group.exists()).toBe(true)
			expect(group.attributes('name')).toBe('collapse')
			expect(group.findAll('.follow-request')).toHaveLength(2)
		})

		it('keys the rows on the account, so the rows that stay are the very same rows', async () => {
			// keyed on the index, Vue answers a removal by patching Bob's row
			// into Carol and dropping the last one: the wrong row collapses and
			// Carol's row is rebuilt underneath the reader
			const wrapper = await mountView()
			const before = wrapper.findAll('.follow-request').map((row) => row.element)
			axios.post.mockResolvedValueOnce({ data: {} })

			await wrapper.findAll('.follow-request__actions button')[0].trigger('click')
			await flushPromises()

			const after = wrapper.findAll('.follow-request').map((row) => row.element)
			expect(after).toHaveLength(1)
			expect(after[0]).toBe(before[1])
			expect(after[0].textContent).toContain('Carol')
		})

		it('brings the empty state in through a transition once the last one is answered', async () => {
			const wrapper = await mountView([bob])
			expect(wrapper.find('.empty-content').exists()).toBe(false)
			axios.post.mockResolvedValueOnce({ data: {} })

			await wrapper.findAll('.follow-request__actions button')[0].trigger('click')
			await flushPromises()

			expect(wrapper.findAll('.follow-request')).toHaveLength(0)
			const empty = wrapper.find('transition-stub')
			expect(empty.attributes('name')).toBe('empty')
			expect(empty.find('.empty-content').exists()).toBe(true)
		})
	})
})
