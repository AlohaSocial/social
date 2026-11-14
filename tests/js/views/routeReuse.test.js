/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The router keeps a view when only its parameter changes, so a reader who
 * moves from A to B before A has answered has two loads in flight. Whatever
 * order they finish in, the page must end up showing B (#2332).
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import CollectionPage from '../../../src/views/CollectionPage.vue'
import PlacePage from '../../../src/views/PlacePage.vue'
import ProfileCollections from '../../../src/views/ProfileCollections.vue'
import ProfileTagged from '../../../src/views/ProfileTagged.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/**
 * Every request the view makes waits here until the test settles it.
 *
 * @type {Array<{url: string, resolve: Function, reject: Function}>}
 */
let pending = []

/**
 * @param {string} fragment part of the URL
 * @return {{url: string, resolve: Function, reject: Function}} the one request asked for it
 */
function request(fragment) {
	const found = pending.filter((one) => one.url.includes(fragment))
	expect(found, `one request for ${fragment}`).toHaveLength(1)

	return found[0]
}

const grid = { name: 'ProfileMediaGrid', props: ['posts', 'account', 'loading'], template: '<div class="grid-stub">{{ posts.map((one) => one.id).join(",") }}</div>' }
const empty = { template: '<div class="empty-stub" />', props: ['name', 'description'] }

/**
 * @param {object} component the view
 * @param {string} account the handle the route starts on
 * @return {{wrapper: object, route: object}}
 */
function mountAccountView(component, account) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const route = reactive({ name: 'profile', params: { account } })
	const wrapper = mount(component, {
		global: {
			plugins: [pinia],
			mocks: { $route: route },
			stubs: {
				RouterLink: RouterLinkStub,
				NcEmptyContent: empty,
				TimelineEntry: { name: 'TimelineEntry', props: ['item', 'type'], template: '<li class="entry-stub">{{ item.id }}</li>' },
			},
		},
	})

	return { wrapper, route }
}

/**
 * @param {object} component the view
 * @param {string} id the id the route starts on
 * @return {object} the wrapper
 */
function mountIdView(component, id) {
	const pinia = createPinia()
	setActivePinia(pinia)

	return mount(component, {
		props: { id },
		global: {
			plugins: [pinia],
			mocks: { $router: { push: vi.fn() } },
			stubs: { RouterLink: RouterLinkStub, ActorAvatar: true, ProfileMediaGrid: grid, NcEmptyContent: empty },
		},
	})
}

describe('views the router reuses', () => {
	beforeEach(() => {
		pending = []
		get.mockReset().mockImplementation((url) => new Promise((resolve, reject) => {
			pending.push({ url, resolve, reject })
		}))
		showError.mockReset()
	})

	describe('ProfileCollections', () => {
		it('keeps B when A answers last', async () => {
			const { wrapper, route } = mountAccountView(ProfileCollections, 'alice')
			route.params.account = 'bob'
			await flushPromises()

			request('/accounts/bob/').resolve({ data: [{ id: '2', title: 'Bob\'s', visibility: 'public', size: 0, posts: [] }] })
			await flushPromises()
			request('/accounts/alice/').resolve({ data: [{ id: '1', title: 'Alice\'s', visibility: 'public', size: 0, posts: [] }] })
			await flushPromises()

			expect(wrapper.findAll('.collections__card')).toHaveLength(1)
			expect(wrapper.text()).toContain('Bob\'s')
			expect(wrapper.text()).not.toContain('Alice\'s')
		})

		it('does not let A\'s failure replace B or its spinner', async () => {
			const { wrapper, route } = mountAccountView(ProfileCollections, 'alice')
			route.params.account = 'bob'
			await flushPromises()

			request('/accounts/alice/').reject(new Error('gone'))
			await flushPromises()
			expect(wrapper.find('.collections__error').exists()).toBe(false)
			expect(wrapper.find('.collections__loading').exists()).toBe(true)

			request('/accounts/bob/').resolve({ data: [{ id: '2', title: 'Bob\'s', visibility: 'public', size: 0, posts: [] }] })
			await flushPromises()
			expect(wrapper.text()).toContain('Bob\'s')
		})
	})

	describe('ProfileTagged', () => {
		it('keeps B when A answers last', async () => {
			const { wrapper, route } = mountAccountView(ProfileTagged, 'alice')
			route.params.account = 'bob'
			await flushPromises()

			request('/accounts/bob/').resolve({ data: [{ id: 'b1' }] })
			await flushPromises()
			request('/accounts/alice/').resolve({ data: [{ id: 'a1' }, { id: 'a2' }] })
			await flushPromises()

			expect(wrapper.findAll('.entry-stub').map((one) => one.text())).toEqual(['b1'])
		})

		it('does not let A\'s failure replace B', async () => {
			const { wrapper, route } = mountAccountView(ProfileTagged, 'alice')
			route.params.account = 'bob'
			await flushPromises()

			request('/accounts/bob/').resolve({ data: [{ id: 'b1' }] })
			await flushPromises()
			request('/accounts/alice/').reject(new Error('gone'))
			await flushPromises()

			expect(wrapper.find('.tagged__error').exists()).toBe(false)
			expect(wrapper.findAll('.entry-stub')).toHaveLength(1)
		})
	})

	describe('PlacePage', () => {
		it('keeps B and B\'s posts when A answers last', async () => {
			const wrapper = mountIdView(PlacePage, '1')
			await wrapper.setProps({ id: '2' })
			await flushPromises()

			request('/places/2').resolve({ data: { id: '2', name: 'Bremen' } })
			await flushPromises()
			request('/places/2/statuses').resolve({ data: [{ id: 'b1', media_attachments: [] }] })
			await flushPromises()
			request('/places/1').resolve({ data: { id: '1', name: 'Aachen' } })
			await flushPromises()

			expect(wrapper.find('.place__title').text()).toBe('Bremen')
			expect(wrapper.find('.grid-stub').text()).toBe('b1')
			// A never went on to ask for its posts
			expect(pending.filter((one) => one.url.includes('/places/1/statuses'))).toHaveLength(0)
			expect(wrapper.find('.place__loading').exists()).toBe(false)
		})

		it('does not let A\'s failure show an error over B', async () => {
			const wrapper = mountIdView(PlacePage, '1')
			await wrapper.setProps({ id: '2' })
			await flushPromises()

			request('/places/2').resolve({ data: { id: '2', name: 'Bremen' } })
			await flushPromises()
			request('/places/2/statuses').resolve({ data: [] })
			await flushPromises()
			request('/places/1').reject({ response: { status: 404 } })
			await flushPromises()

			expect(wrapper.find('.place__error').exists()).toBe(false)
			expect(wrapper.find('.place__title').text()).toBe('Bremen')
		})
	})

	describe('CollectionPage', () => {
		it('keeps B and B\'s posts when A answers last', async () => {
			const wrapper = mountIdView(CollectionPage, '1')
			await wrapper.setProps({ id: '2' })
			await flushPromises()

			request('/collections/2').resolve({ data: { id: '2', title: 'Bremen', visibility: 'public', size: 1 } })
			await flushPromises()
			request('/collections/2/items').resolve({ data: [{ id: 'b1', media_attachments: [] }] })
			await flushPromises()
			request('/collections/1').resolve({ data: { id: '1', title: 'Aachen', visibility: 'public', size: 1 } })
			await flushPromises()

			expect(wrapper.find('.collection__title').text()).toContain('Bremen')
			expect(wrapper.find('.grid-stub').text()).toBe('b1')
			expect(wrapper.find('.collection__loading').exists()).toBe(false)
		})

		/**
		 * The load asks for the collection, then for its posts: if the route
		 * moved in between, the second request used to be built from the new
		 * id and drawn under the old title.
		 */
		it('never draws one collection\'s posts under another\'s title', async () => {
			const wrapper = mountIdView(CollectionPage, '1')
			await flushPromises()

			request('/collections/1').resolve({ data: { id: '1', title: 'Aachen', visibility: 'public', size: 1 } })
			await flushPromises()
			// A is now asking for its posts, by its own id
			const itemsOfA = request('/collections/1/items')

			await wrapper.setProps({ id: '2' })
			await flushPromises()
			itemsOfA.resolve({ data: [{ id: 'a1', media_attachments: [] }] })
			await flushPromises()

			expect(pending.filter((one) => one.url.includes('/collections/2/items'))).toHaveLength(0)
			expect(wrapper.find('.collection__loading').exists()).toBe(true)

			request('/collections/2').resolve({ data: { id: '2', title: 'Bremen', visibility: 'public', size: 1 } })
			await flushPromises()
			request('/collections/2/items').resolve({ data: [{ id: 'b1', media_attachments: [] }] })
			await flushPromises()

			expect(wrapper.find('.collection__title').text()).toContain('Bremen')
			expect(wrapper.find('.grid-stub').text()).toBe('b1')
		})

		it('does not let A\'s failure show an error or a toast over B', async () => {
			const wrapper = mountIdView(CollectionPage, '1')
			await flushPromises()
			request('/collections/1').resolve({ data: { id: '1', title: 'Aachen', visibility: 'public', size: 1 } })
			await flushPromises()
			const itemsOfA = request('/collections/1/items')

			await wrapper.setProps({ id: '2' })
			await flushPromises()
			request('/collections/2').resolve({ data: { id: '2', title: 'Bremen', visibility: 'public', size: 0 } })
			await flushPromises()
			request('/collections/2/items').resolve({ data: [] })
			await flushPromises()
			itemsOfA.reject(new Error('gone'))
			await flushPromises()

			expect(showError).not.toHaveBeenCalled()
			expect(wrapper.find('.collection__error').exists()).toBe(false)
			expect(wrapper.find('.collection__title').text()).toContain('Bremen')
		})
	})
})
