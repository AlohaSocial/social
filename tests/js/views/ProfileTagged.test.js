/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ProfileTagged from '../../../src/views/ProfileTagged.vue'
import TimelineSwitcher from '../../../src/components/TimelineSwitcher.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

function mountView(account = 'bob@remote.example') {
	const pinia = createPinia()
	setActivePinia(pinia)

	return mount(ProfileTagged, {
		global: {
			plugins: [pinia],
			mocks: { $route: { name: 'profile.tagged', params: { account } } },
			stubs: {
				TimelineEntry: { name: 'TimelineEntry', props: ['item', 'type'], template: '<li class="entry-stub" />' },
				RouterLink: RouterLinkStub,
			},
		},
	})
}

describe('ProfileTagged', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: [] })
	})

	it('asks the server for the photos that account is named in', async () => {
		mountView('bob@remote.example')
		await flushPromises()

		const wanted = '/apps/social/api/v1.1/accounts/bob%40remote.example/tagged'
		expect(get).toHaveBeenCalledWith(expect.stringContaining(wanted), { params: { limit: 20 } })
	})

	it('draws one entry per photo it is given', async () => {
		get.mockResolvedValue({ data: [{ id: '1' }, { id: '2' }] })

		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.findAll('.entry-stub')).toHaveLength(2)
	})

	/**
	 * Which posts the reader may see is the server's decision; the page draws
	 * what it is handed and asks nothing of its own.
	 */
	it('says so plainly when there are none', async () => {
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.text()).toContain('No photos of them')
		expect(wrapper.findComponent({ name: 'QuietTimeline' }).exists()).toBe(true)
		expect(wrapper.findAll('.entry-stub')).toHaveLength(0)
	})

	it('shows Tagged as the place it is, on the same switcher the rest of the profile carries', async () => {
		const wrapper = mountView()
		await flushPromises()

		const switcher = wrapper.findComponent(TimelineSwitcher)
		expect(switcher.props('value')).toBe('tagged')
		expect(switcher.props('options').map((option) => option.label))
			.toEqual(['Posts', 'Photos', 'Videos', 'Tagged', 'Collections'])
	})

	it('offers a way back when the server could not answer', async () => {
		get.mockRejectedValue(new Error('nope'))

		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('.tagged__error').exists()).toBe(true)
		expect(wrapper.text()).toContain('Try again')
	})

	describe('paging', () => {
		const page = (from, count) => Array.from({ length: count }, (_, i) => ({ id: String(from - i) }))
		const next = (maxId) => ({ link: `</apps/social/api/v1.1/accounts/bob/tagged?limit=20&max_id=${maxId}>; rel="next"` })

		it('asks for the next page from the Link header cursor and appends it', async () => {
			get.mockResolvedValueOnce({ data: page(100, 20), headers: next('81') })
				.mockResolvedValueOnce({ data: page(80, 5), headers: {} })

			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.findAll('.entry-stub')).toHaveLength(20)

			await wrapper.find('.tagged__more button').trigger('click')
			await flushPromises()

			expect(get).toHaveBeenLastCalledWith(expect.any(String), { params: { limit: 20, max_id: '81' } })
			expect(wrapper.findAll('.entry-stub')).toHaveLength(25)
		})

		it('draws no post twice when the pages overlap', async () => {
			get.mockResolvedValueOnce({ data: page(100, 20), headers: next('81') })
				.mockResolvedValueOnce({ data: page(82, 5), headers: {} })

			const wrapper = mountView()
			await flushPromises()
			await wrapper.find('.tagged__more button').trigger('click')
			await flushPromises()

			const ids = wrapper.findAllComponents({ name: 'TimelineEntry' }).map((entry) => entry.props('item').id)
			expect(ids).toHaveLength(23)
			expect(new Set(ids).size).toBe(23)
		})

		/**
		 * The server leaves out the posts this reader may not see, so a page
		 * with fewer than twenty in it is not the end while it still says
		 * where the next one starts.
		 */
		it('keeps paging after a short page that still has a next link', async () => {
			get.mockResolvedValueOnce({ data: page(100, 3), headers: next('81') })

			const wrapper = mountView()
			await flushPromises()

			expect(wrapper.find('.tagged__more button').exists()).toBe(true)
		})

		it('stops once the server sends no next link, even after a full page', async () => {
			get.mockResolvedValueOnce({ data: page(100, 20), headers: {} })

			const wrapper = mountView()
			await flushPromises()

			expect(wrapper.find('.tagged__more').exists()).toBe(false)
		})

		it('loads the next page when the end of the list scrolls into view', async () => {
			let callback
			vi.stubGlobal('IntersectionObserver', class {
				constructor(cb) {
					callback = cb
				}

				observe() {}
				disconnect() {}
			})
			get.mockResolvedValueOnce({ data: page(100, 20), headers: next('81') })
				.mockResolvedValueOnce({ data: [], headers: {} })

			const wrapper = mountView()
			await flushPromises()
			callback([{ isIntersecting: true }])
			await flushPromises()

			expect(get).toHaveBeenCalledTimes(2)
			expect(wrapper.find('.tagged__more').exists()).toBe(false)
			vi.unstubAllGlobals()
		})
	})
})
