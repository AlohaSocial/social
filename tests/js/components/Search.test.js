/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import Search, { SEARCH_REFILL_MS } from '../../../src/components/Search.vue'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const UserEntryStub = {
	name: 'UserEntry',
	props: ['item'],
	template: '<div class="user-entry-stub" />',
}
const TimelineEntryStub = {
	name: 'TimelineEntry',
	props: ['item', 'type'],
	template: '<li class="timeline-entry-stub" />',
}
const RouterLinkStub = {
	name: 'RouterLink',
	props: ['to'],
	template: '<a class="router-link-stub"><slot /></a>',
}
const NcEmptyContentStub = {
	name: 'NcEmptyContent',
	props: ['name', 'description'],
	template: '<div class="empty-stub"><span class="empty-name">{{ name }}</span><span class="empty-description">{{ description }}</span></div>',
}
const NcLoadingIconStub = { name: 'NcLoadingIcon', template: '<span class="loading-stub" />' }
const ComposerStub = { name: 'Composer', template: '<div class="composer-stub" />' }

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob' }
const carol = { id: 'https://cloud.example.org/users/carol', url: 'https://cloud.example.org/users/carol', acct: 'carol', username: 'carol', display_name: 'Carol' }

const status = (id) => ({ id, content: `<p>post ${id}</p>`, created_at: '2026-01-01T00:00:00Z', account: bob })

/**
 * The v2 search response: three flat lists, the way Mastodon answers.
 *
 * @param {object} results what the server found
 * @param {Array} [results.accounts] matching accounts
 * @param {Array} [results.statuses] matching statuses
 * @param {Array} [results.hashtags] matching hashtag names
 * @return {object} an axios-shaped response
 */
function response({ accounts = [], statuses = [], hashtags = [] } = {}) {
	return {
		data: {
			accounts,
			statuses,
			hashtags: hashtags.map((name) => ({ name, url: `https://cloud.example.org/timeline/tags/${name}`, history: [] })),
		},
	}
}

const SEARCH_URL = '/index.php/apps/social/api/v2/search'

let pinia
let accountStore
let timelineStore
let get

function mountSearch(term) {
	return mount(Search, {
		props: { term },
		global: {
			plugins: [pinia],
			stubs: {
				UserEntry: UserEntryStub,
				TimelineEntry: TimelineEntryStub,
				RouterLink: RouterLinkStub,
				NcEmptyContent: NcEmptyContentStub,
				NcLoadingIcon: NcLoadingIconStub,
				Composer: ComposerStub,
			},
		},
	})
}

describe('Search', () => {
	/**
	 * vue-router 5 leaves an absent optional param `undefined`, where 4 gave
	 * `''` — so `/search` with no term now hands this component nothing, and
	 * the prop's default is what keeps the search box empty rather than
	 * showing the string "undefined".
	 */
	it('opens with an empty box when the route carries no term', () => {
		const wrapper = mountSearch(undefined)

		expect(wrapper.vm.term).toBe('')
	})

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		accountStore = useAccountStore()
		timelineStore = useTimelineStore()
		useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org' })
		get = vi.spyOn(axios, 'get')
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('asks the server rather than filtering the posts already loaded', () => {
		get.mockReturnValue(new Promise(() => {}))
		const wrapper = mountSearch('nextcloud')

		expect(get).toHaveBeenCalledTimes(1)
		expect(get).toHaveBeenCalledWith(SEARCH_URL, { params: { q: 'nextcloud', limit: 20 } })
		expect(wrapper.find('.loading-stub').exists()).toBe(true)
	})

	it('lists the accounts, hashtags and posts the server found, and caches the accounts', async () => {
		get.mockResolvedValue(response({
			accounts: [bob, carol],
			hashtags: ['nextcloud', 'fediverse'],
			statuses: [status('1'), status('2')],
		}))
		const wrapper = mountSearch('nextcloud')
		await flushPromises()

		expect(wrapper.find('h1').text()).toBe('Search results for “nextcloud”')
		expect(wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item'))).toEqual([bob, carol])
		expect(wrapper.findAll('li.tag').map((tag) => tag.text())).toEqual(['#nextcloud', '#fediverse'])
		expect(wrapper.findAllComponents(RouterLinkStub).map((link) => link.props('to'))).toEqual([
			{ name: 'tags', params: { tag: 'nextcloud' } },
			{ name: 'tags', params: { tag: 'fediverse' } },
		])
		expect(wrapper.findAllComponents(TimelineEntryStub).map((entry) => entry.props('item').id)).toEqual(['1', '2'])

		expect(accountStore.getAccount('bob@remote.example')).toEqual(bob)
		expect(accountStore.getAccount('carol@cloud.example.org')).toEqual(carol)
	})

	describe('acting on a result', () => {
		const found = async () => {
			get.mockResolvedValue(response({ statuses: [status('1'), status('2')] }))
			const wrapper = mountSearch('nextcloud')
			await flushPromises()
			return wrapper
		}

		const entry = (wrapper, id) => wrapper.findAllComponents(TimelineEntryStub)
			.find((candidate) => candidate.props('item').id === id)

		it('shows a like, a boost and a bookmark on the result itself', async () => {
			// the results lived in local component data, where every mutation
			// in store/timeline.js — each guarded on the status being in
			// `state.statuses` — could not reach them: the request went out
			// and nothing on screen changed
			const wrapper = await found()

			timelineStore.likeStatus({ status: status('1') })
			timelineStore.boostStatus({ status: status('1') })
			timelineStore.bookmarkStatus({ status: status('1'), bookmarked: true })
			await nextTick()

			expect(entry(wrapper, '1').props('item')).toMatchObject({
				favourited: true,
				reblogged: true,
				bookmarked: true,
			})
			expect(entry(wrapper, '2').props('item').favourited).toBeUndefined()
		})

		it('takes a deleted result off the page', async () => {
			// removeStatus took it out of a list it was never in, so it stayed
			const wrapper = await found()

			timelineStore.removeStatus(status('1'))
			await nextTick()

			expect(wrapper.findAllComponents(TimelineEntryStub).map((candidate) => candidate.props('item').id)).toEqual(['2'])
		})

		it('has a composer for Reply to reach', async () => {
			// Reply emits `composer-reply` on the event bus, and the listener
			// is registered in the Composer's mounted(): with none on the page
			// pressing it did nothing whatsoever
			const wrapper = await found()
			const composer = wrapper.findComponent(ComposerStub)
			expect(composer.exists()).toBe(true)
			expect(composer.element.style.display).toBe('none')

			timelineStore.setComposerDisplayStatus(true)
			await nextTick()
			expect(composer.element.style.display).toBe('')
		})
	})

	it('leaves out the sections the server found nothing for', async () => {
		get.mockResolvedValue(response({ accounts: [bob] }))
		const wrapper = mountSearch('bob')
		await flushPromises()

		expect(wrapper.findAllComponents(UserEntryStub)).toHaveLength(1)
		expect(wrapper.find('li.tag').exists()).toBe(false)
		expect(wrapper.findComponent(TimelineEntryStub).exists()).toBe(false)
		expect(wrapper.findComponent(NcEmptyContentStub).exists()).toBe(false)
	})

	it('shows an empty state with the decoded term when nothing matches', async () => {
		get.mockResolvedValue(response())
		const wrapper = mountSearch('foo%20bar')
		await flushPromises()

		expect(wrapper.find('.empty-name').text()).toBe('No results found')
		expect(wrapper.find('.empty-description').text()).toContain('foo bar')
		expect(wrapper.find('.loading-stub').exists()).toBe(false)
	})

	it('shows a failure as an error with a retry, and does not block later searches', async () => {
		get.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mountSearch('boom')
		await flushPromises()

		expect(wrapper.find('.loading-stub').exists()).toBe(false)
		expect(wrapper.find('.social__search-error').text()).toContain('The search could not be run.')
		expect(wrapper.find('.social__search-error').attributes('role')).toBe('alert')

		get.mockResolvedValueOnce(response({ accounts: [bob] }))
		await wrapper.find('.social__search-error button').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item'))).toEqual([bob])
	})

	it('debounces a changing term into one request', async () => {
		vi.useFakeTimers()
		try {
			get.mockResolvedValue(response({ accounts: [bob] }))
			const wrapper = mountSearch('b')
			await flushPromises()
			expect(get).toHaveBeenCalledTimes(1)

			await wrapper.setProps({ term: 'bo' })
			await wrapper.setProps({ term: 'bob' })
			// re-filtering and re-sorting per keystroke was the old cost
			expect(get).toHaveBeenCalledTimes(1)

			vi.advanceTimersByTime(300)
			await flushPromises()
			expect(get).toHaveBeenCalledTimes(2)
			expect(get).toHaveBeenLastCalledWith(SEARCH_URL, { params: { q: 'bob', limit: 20 } })
		} finally {
			vi.useRealTimers()
		}
	})

	it('asks once more a little later while posts beyond this server are being read', async () => {
		vi.useFakeTimers()
		try {
			get.mockResolvedValueOnce({ ...response({ statuses: [status('1')] }), headers: { 'x-social-filling': '1' } })
				.mockResolvedValueOnce(response({ statuses: [status('1'), status('2')] }))
			const wrapper = mountSearch('open source')
			await flushPromises()
			expect(get).toHaveBeenCalledTimes(1)

			await vi.advanceTimersByTimeAsync(SEARCH_REFILL_MS)
			await flushPromises()
			expect(get).toHaveBeenCalledTimes(2)
			expect(wrapper.vm.statusIds).toEqual(['1', '2'])
		} finally {
			vi.useRealTimers()
		}
	})

	it('asks nothing at all for an empty term', async () => {
		const wrapper = mountSearch('   ')
		await flushPromises()

		expect(get).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcEmptyContentStub).exists()).toBe(true)
	})

	describe('results arriving', () => {
		/** @return {object} a promise with its resolve, to land responses out of order */
		const deferred = () => {
			let settle
			let reject
			const promise = new Promise((resolve, fail) => {
				settle = resolve
				reject = fail
			})
			return { promise, settle, reject }
		}

		it('brings each section in through a transition group', async () => {
			// the three lists appeared all at once, hard, while the timelines
			// around them animate every insertion
			get.mockResolvedValue(response({
				accounts: [bob, carol],
				hashtags: ['nextcloud'],
				statuses: [status('1')],
			}))
			const wrapper = mountSearch('nextcloud')
			await flushPromises()

			const groups = wrapper.findAll('transition-group-stub')
			expect(groups).toHaveLength(3)
			expect(groups.map((group) => group.attributes('name'))).toEqual(['result', 'result', 'result'])
			expect(groups[0].findAllComponents(UserEntryStub)).toHaveLength(2)
			expect(groups[1].findAll('li.tag')).toHaveLength(1)
			expect(groups[2].findAllComponents(TimelineEntryStub)).toHaveLength(1)
		})

		it('keeps the results a refined term still finds, rather than rebuilding the list', async () => {
			// keyed on the index, Vue answers [bob, carol] -> [carol] by
			// patching Bob's entry into Carol and dropping the last one, so
			// the result that stayed is a different element and animates as
			// though it had just arrived
			vi.useFakeTimers()
			try {
				get.mockResolvedValue(response({ accounts: [bob, carol] }))
				const wrapper = mountSearch('o')
				await flushPromises()
				const before = wrapper.findAll('.user-entry-stub').map((entry) => entry.element)

				get.mockResolvedValue(response({ accounts: [carol] }))
				await wrapper.setProps({ term: 'carol' })
				vi.advanceTimersByTime(300)
				await flushPromises()

				const after = wrapper.findAll('.user-entry-stub').map((entry) => entry.element)
				expect(after).toHaveLength(1)
				expect(after[0]).toBe(before[1])
			} finally {
				vi.useRealTimers()
			}
		})

		it('holds the previous results on screen while a refined term is being searched', async () => {
			// results -> spinner -> results, on every refinement, was a flicker
			vi.useFakeTimers()
			try {
				const slow = deferred()
				get.mockResolvedValueOnce(response({ accounts: [bob, carol] }))
				const wrapper = mountSearch('o')
				await flushPromises()

				get.mockReturnValueOnce(slow.promise)
				await wrapper.setProps({ term: 'carol' })
				vi.advanceTimersByTime(300)
				await flushPromises()

				expect(wrapper.find('.loading-stub').exists()).toBe(false)
				expect(wrapper.findAllComponents(UserEntryStub)).toHaveLength(2)
				expect(wrapper.find('.social__search').attributes('aria-busy')).toBe('true')

				slow.settle(response({ accounts: [carol] }))
				await flushPromises()
				expect(wrapper.find('.social__search').attributes('aria-busy')).toBe('false')
			} finally {
				vi.useRealTimers()
			}
		})

		it('puts results that no longer answer the typed term on screen without motion', async () => {
			// the results answer the term before the one being typed, which is
			// still in flight: they must not be animated in as the answer
			vi.useFakeTimers()
			try {
				const second = deferred()
				get.mockResolvedValueOnce(response({ accounts: [bob] })).mockReturnValueOnce(second.promise)
				const wrapper = mountSearch('bob')
				await flushPromises()
				expect(wrapper.find('transition-group-stub').attributes('css')).toBe('true')

				await wrapper.setProps({ term: 'bobby' })
				vi.advanceTimersByTime(300)
				await flushPromises()
				expect(wrapper.findAllComponents(UserEntryStub)).toHaveLength(1)
				expect(wrapper.find('transition-group-stub').attributes('css')).toBe('false')

				second.settle(response({ accounts: [bob, carol] }))
				await flushPromises()
				expect(wrapper.find('transition-group-stub').attributes('css')).toBe('true')
			} finally {
				vi.useRealTimers()
			}
		})

		it('drops a response for a term the reader has moved on from', async () => {
			// typing "ali" starts a webfinger lookup on a remote server;
			// "alice" is answered from the cache while it is still running, and
			// the slow answer then replaced alice's results with ali's and left
			// `renderedTerm` on a term the box no longer showed
			vi.useFakeTimers()
			try {
				const slow = deferred()
				get.mockReturnValueOnce(slow.promise).mockResolvedValueOnce(response({ accounts: [carol] }))
				const wrapper = mountSearch('ali')

				await wrapper.setProps({ term: 'alice' })
				vi.advanceTimersByTime(300)
				await flushPromises()
				expect(wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item'))).toEqual([carol])

				slow.settle(response({ accounts: [bob] }))
				await flushPromises()

				expect(wrapper.findAllComponents(UserEntryStub).map((entry) => entry.props('item'))).toEqual([carol])
				expect(wrapper.find('transition-group-stub').attributes('css')).toBe('true')
			} finally {
				vi.useRealTimers()
			}
		})

		it('does not report a failure the reader has already typed past', async () => {
			vi.useFakeTimers()
			try {
				const slow = deferred()
				get.mockReturnValueOnce(slow.promise).mockResolvedValueOnce(response({ accounts: [carol] }))
				const wrapper = mountSearch('ali')

				await wrapper.setProps({ term: 'alice' })
				vi.advanceTimersByTime(300)
				await flushPromises()

				slow.reject(new Error('the remote server never answered'))
				await flushPromises()

				expect(wrapper.find('.social__search-error').exists()).toBe(false)
				// `loading` belongs to the newer request, which has settled
				expect(wrapper.find('.social__search').attributes('aria-busy')).toBe('false')
			} finally {
				vi.useRealTimers()
			}
		})
	})
})
