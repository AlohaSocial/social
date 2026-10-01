/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import TimelineList from '../../../src/components/TimelineList.vue'
import EmptyContent from '../../../src/components/EmptyContent.vue'
import { createInterestTracker } from '../../../src/services/interestTracker.js'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/timelinePush.js', () => ({
	onTimelinePush: vi.fn(() => false),
	offTimelinePush: vi.fn(),
}))
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getCurrentUser: () => ({ uid: 'alice', displayName: 'Alice', isAdmin: false }),
}))
vi.mock('../../../src/services/interestTracker.js', async (importOriginal) => ({
	...(await importOriginal()),
	createInterestTracker: vi.fn(),
}))

class FakeIntersectionObserver {
	constructor(callback) {
		this.callback = callback
		this.observe = vi.fn()
		this.unobserve = vi.fn()
		this.disconnect = vi.fn()
	}
}

/** An entry with the parts of a post the list listens for. */
const TimelineEntryStub = {
	name: 'TimelineEntry',
	props: ['item', 'type'],
	template: `<li class="timeline-entry" :data-status-id="item.id">
		<div class="post-message">
			<a class="out" href="https://news.example/story">a story</a>
			<a class="tag" href="/apps/social/timeline/tags/film">#film</a>
			<a class="mention" href="https://remote.example/@bob">@bob</a>
		</div>
		<div class="post-attachments"><button class="picture">picture</button></div>
		<video class="video" />
	</li>`,
}

const ON = { enabled: true, learning: true, paused: false, noticeAcknowledged: true }

function post(id, day) {
	return {
		id,
		created_at: `2026-09-${day}T10:00:00Z`,
		content: '<p>text</p>',
		reblog: null,
		tags: [{ name: 'film' }],
		account: { id: '2', acct: 'bob@remote.example', username: 'bob' },
	}
}

let fake
let store

/** The grid Photos and Videos are drawn as: one link per post. */
const ProfileMediaGridStub = {
	name: 'ProfileMediaGrid',
	props: ['posts', 'account', 'loading'],
	template: `<ul><li v-for="p in posts" :key="p.id">
		<a class="media-grid__link" :data-status-id="p.id" href="#"><img class="tile" alt=""></a>
	</li></ul>`,
}

function mountList({ type = 'interests', timeline = [], interests = ON, route, responses = [[]], display = 'list', params = {} } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', interests })
	useAccountStore()
	store = useTimelineStore()
	const dispatch = vi.fn()
	for (const response of responses) {
		dispatch.mockResolvedValueOnce(response)
	}
	dispatch.mockResolvedValue([])
	vi.spyOn(store, 'fetchTimeline').mockImplementation(dispatch)
	store.$patch({
		type,
		params,
		statuses: Object.fromEntries(timeline.map((entry) => [entry.id, entry])),
		timeline: timeline.map((entry) => entry.id),
	})

	const wrapper = mount(TimelineList, {
		attachTo: document.body,
		props: { type, display },
		global: {
			plugins: [pinia],
			mocks: { $route: route ?? { name: 'timeline', params: { type } } },
			stubs: { TimelineEntry: TimelineEntryStub, ProfileMediaGrid: ProfileMediaGridStub },
		},
	})

	return { wrapper, dispatch }
}

describe('TimelineList and For you', () => {
	let wrapper

	beforeEach(() => {
		vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver)
		vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
		fake = { sync: vi.fn(), record: vi.fn(), destroy: vi.fn() }
		createInterestTracker.mockReset().mockReturnValue(fake)
		window.localStorage.clear()
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
		vi.useRealTimers()
		vi.unstubAllGlobals()
	})

	describe('the ranked feed', () => {
		// ranked: the oldest post is not the last one
		const ranked = [post('30', '10'), post('10', '12'), post('20', '11')]

		it('keeps the server\'s order and pages from the last post on it', async () => {
			let dispatch
			({ wrapper, dispatch } = mountList({ timeline: ranked }))
			await flushPromises()

			expect(wrapper.findAll('[data-status-id]').map((one) => one.attributes('data-status-id'))).toEqual(['30', '10', '20'])

			dispatch.mockClear()
			await wrapper.vm.infiniteHandler()

			expect(dispatch).toHaveBeenCalledWith({ max_id: '20' })
		})

		it('never asks for newer posts: a ranking has no top to catch up on', async () => {
			let dispatch
			({ wrapper, dispatch } = mountList({ timeline: ranked }))
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(60000)
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
		})

		it('draws no "up to date" line, which only means something in time order', async () => {
			window.localStorage.setItem('social:seen:' + JSON.stringify(['interests', '', {}]), JSON.stringify({ id: '15', at: Date.now() }))
			;({ wrapper } = mountList({ timeline: ranked }))
			await flushPromises()

			expect(wrapper.find('.timeline-caughtup').exists()).toBe(false)
		})

		it('pages the For you scope of Photos from where it ended, as the feed itself', async () => {
			let dispatch
			({ wrapper, dispatch } = mountList({ type: 'photos', display: 'grid', params: { scope: 'interests' }, timeline: ranked }))
			await flushPromises()
			dispatch.mockClear()

			await wrapper.vm.infiniteHandler()

			expect(dispatch).toHaveBeenCalledWith({ max_id: '20' })
		})

		it('explains how the feed learns when it is empty, and where to teach it', async () => {
			({ wrapper } = mountList())
			await flushPromises()

			const item = wrapper.findComponent(EmptyContent).props('item')
			expect(item.description).toContain('learns which hashtags you care about')
			expect(item.action.to).toEqual({ name: 'settings', hash: '#interests' })
		})
	})

	describe('the tracking', () => {
		it.each([
			['home', 'home'],
			['timeline', 'local'],
			['federated', 'federated'],
			['tags', 'tag'],
			['interests', 'interests'],
		])('counts reading on %s as %s', async (type, context) => {
			({ wrapper } = mountList({ type, timeline: [post('1', '10')] }))
			await flushPromises()

			expect(createInterestTracker).toHaveBeenCalledWith(expect.objectContaining({ context }))
		})

		it.each(['photos', 'videos'])('counts only a tile opened on the %s grid, where every tile is on screen at once', async (type) => {
			({ wrapper } = mountList({ type, display: 'grid', timeline: [post('1', '10'), post('2', '11')] }))
			await flushPromises()

			expect(createInterestTracker).toHaveBeenCalledWith(expect.objectContaining({ context: type }))
			expect(fake.sync).not.toHaveBeenCalled()

			const tile = wrapper.findAll('.media-grid__link')[1].element
			tile.addEventListener('click', (event) => event.preventDefault())
			wrapper.findAll('.tile')[1].element.click()

			expect(fake.record.mock.calls.map(([status, kind]) => [status.id, kind])).toEqual([['1', 'media']])
		})

		it('counts a thread as the detail view', async () => {
			({ wrapper } = mountList({ type: 'home', route: { name: 'single-post', params: { id: '1' } } }))
			await flushPromises()

			expect(createInterestTracker).toHaveBeenCalledWith(expect.objectContaining({ context: 'detail' }))
		})

		it('hands it every entry with its post', async () => {
			const one = post('1', '10')
			;({ wrapper } = mountList({ type: 'home', timeline: [one] }))
			await flushPromises()

			const pairs = fake.sync.mock.calls.at(-1)[0]
			expect(pairs.map(([element, status]) => [element.dataset.statusId, status.id])).toEqual([['1', '1']])
		})

		it.each([
			['the feature is off', { ...ON, enabled: false }],
			['the reader opted out', { ...ON, learning: false }],
			['learning is paused', { ...ON, paused: true }],
		])('counts nothing when %s', async (_, interests) => {
			({ wrapper } = mountList({ type: 'home', interests }))
			await flushPromises()

			expect(createInterestTracker).not.toHaveBeenCalled()
		})

		it('counts nothing on a timeline that teaches nothing', async () => {
			({ wrapper } = mountList({ type: 'bookmarks' }))
			await flushPromises()

			expect(createInterestTracker).not.toHaveBeenCalled()
		})

		it('records a picture opened, a video played and a link followed, and not a hashtag or a mention', async () => {
			({ wrapper } = mountList({ type: 'home', timeline: [post('1', '10')] }))
			await flushPromises()

			await wrapper.find('.picture').trigger('click')
			wrapper.find('.video').element.dispatchEvent(new Event('play'))
			const out = wrapper.find('.out').element
			out.addEventListener('click', (event) => event.preventDefault())
			out.click()
			for (const selector of ['.tag', '.mention']) {
				const link = wrapper.find(selector).element
				link.addEventListener('click', (event) => event.preventDefault())
				link.click()
			}

			expect(fake.record.mock.calls.map(([status, kind]) => [status.id, kind])).toEqual([
				['1', 'media'],
				['1', 'media'],
				['1', 'link'],
			])
		})

		it('finishes the tracker when learning is turned off, and when the list goes', async () => {
			({ wrapper } = mountList({ type: 'home' }))
			await flushPromises()

			useSettingsStore().setServerDataEntry({ key: 'interests', value: { ...ON, learning: false } })
			await flushPromises()
			expect(fake.destroy).toHaveBeenCalledTimes(1)

			useSettingsStore().setServerDataEntry({ key: 'interests', value: ON })
			await flushPromises()
			expect(createInterestTracker).toHaveBeenCalledTimes(2)

			wrapper.unmount()
			wrapper = null
			expect(fake.destroy).toHaveBeenCalledTimes(2)
		})

		it('starts over on another timeline', async () => {
			({ wrapper } = mountList({ type: 'home' }))
			await flushPromises()

			store.$patch({ type: 'federated' })
			await wrapper.setProps({ type: 'federated' })
			await flushPromises()

			expect(fake.destroy).toHaveBeenCalled()
			expect(createInterestTracker).toHaveBeenLastCalledWith(expect.objectContaining({ context: 'federated' }))
		})
	})
})
