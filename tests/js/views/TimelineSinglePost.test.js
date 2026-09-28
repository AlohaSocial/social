/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global setInitialState */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, reactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import TimelineSinglePost from '../../../src/views/TimelineSinglePost.vue'
import eventBus from '../../../src/services/eventBus.js'
import { signalNow } from '../../../src/services/interestTracker.js'
import { useAccountStore } from '../../../src/store/account.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('../../../src/services/interestTracker.js', async (importOriginal) => ({
	...(await importOriginal()),
	signalNow: vi.fn(),
}))

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const ComposerStub = {
	name: 'Composer',
	props: { inReplyTo: { type: Object, default: null } },
	template: '<div class="composer-stub" />',
}
const TimelineListStub = {
	name: 'TimelineList',
	props: { type: String, showParents: Boolean, reverseOrder: Boolean },
	template: '<ul class="timeline-list-stub"><li data-social-status="reply-1" /></ul>',
}
const TimelineEntryStub = {
	name: 'TimelineEntry',
	props: ['item', 'type', 'element'],
	template: '<div class="timeline-entry-stub" />',
}
const NcEmptyContentStub = {
	name: 'NcEmptyContent',
	props: ['name', 'description'],
	template: '<div class="empty-stub"><span class="empty-name">{{ name }}</span></div>',
}

const bob = { id: 'https://remote.example/users/bob', url: 'https://remote.example/users/bob', acct: 'bob@remote.example', username: 'bob' }
const status = { id: '123', uri: 'https://remote.example/users/bob/statuses/123', content: '<p>Hello</p>', created_at: '2026-01-01T00:00:00Z', account: bob }
const fromServer = { ...status, content: '<p>Server copy</p>' }
const parent = { id: '120', uri: 'https://remote.example/users/bob/statuses/120', content: '<p>Parent</p>', created_at: '2026-01-01T00:00:00Z', account: bob }
const grandParent = { ...parent, id: '119', uri: 'https://remote.example/users/bob/statuses/119' }

let pinia
let accountStore
let store
const fetchAccount = vi.fn(async () => bob)

function setState(key, value) {
	setInitialState('social', key, value)
	window._nc_initial_state?.clear()
}

function makeStore(serverData = {}) {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	store = useTimelineStore()
	vi.spyOn(accountStore, 'fetchAccountInfo').mockImplementation(fetchAccount)
	vi.spyOn(accountStore, 'fetchPublicAccountInfo').mockImplementation(fetchAccount)
	vi.spyOn(store, 'changeTimelineType')
	useSettingsStore().setServerData({ public: false, cloudAddress: 'https://cloud.example.org', ...serverData })

	return pinia
}

// every view registers an event bus listener, so unmount them after each test
const mounted = []

const $router = { back: vi.fn(), push: vi.fn() }

function mountView(route = reactive({ name: 'single-post', params: { account: 'bob', id: '123' } })) {
	const wrapper = mount(TimelineSinglePost, {
		attachTo: document.body,
		global: {
			plugins: [pinia],
			mocks: { $route: route, $router },
			stubs: {
				Composer: ComposerStub,
				TimelineList: TimelineListStub,
				TimelineEntry: TimelineEntryStub,
				NcEmptyContent: NcEmptyContentStub,
			},
		},
	})
	mounted.push(wrapper)
	return wrapper
}

describe('TimelineSinglePost', () => {
	beforeEach(() => {
		// the view derives the account from the browser URL
		window.history.replaceState({}, '', '/apps/social/@bob/123')
		setState('item', fromServer)
		makeStore()
		fetchAccount.mockClear()
		$router.back.mockClear()
		$router.push.mockClear()
	})

	describe('the way back', () => {
		const backButton = (wrapper) => wrapper.find('.thread__back')

		it('goes back to wherever the reader came from', async () => {
			// the router writes `back` into the history state whenever it
			// navigates inside the app
			window.history.replaceState({ back: '/apps/social/timeline/home' }, '', '/apps/social/@bob/123')
			const wrapper = mountView()

			await backButton(wrapper).trigger('click')

			expect($router.back).toHaveBeenCalled()
			expect($router.push).not.toHaveBeenCalled()
		})

		it('goes to the home timeline when the post was opened from a link', async () => {
			// there is nothing behind this page inside the app, and going back
			// would leave it — which is not what a button inside it should do
			window.history.replaceState({}, '', '/apps/social/@bob/123')
			const wrapper = mountView()

			await backButton(wrapper).trigger('click')

			expect($router.back).not.toHaveBeenCalled()
			expect($router.push).toHaveBeenCalledWith({ name: 'timeline', params: { type: 'home' } })
		})

		it('is the first thing on the page, and says what it does', () => {
			const wrapper = mountView()
			const button = backButton(wrapper)

			expect(button.exists()).toBe(true)
			expect(button.text()).toBe('Back')
			expect(button.attributes('aria-label')).toBe('Back')
		})
	})

	afterEach(() => {
		vi.restoreAllMocks()
		mounted.splice(0).forEach((wrapper) => wrapper.unmount())
	})

	it('switches the store to the single post context of the routed post', () => {
		store.addToStatuses(status)
		mountView()
		expect(store.changeTimelineType).toHaveBeenCalledWith({
			type: 'single-post',
			params: { account: 'bob', id: '123', type: 'single-post', singlePost: '123' },
		})
		expect(store.type).toBe('single-post')
		expect(store.params.singlePost).toBe('123')
	})

	it('prefers the already loaded post over the server-rendered copy', () => {
		store.addToStatuses(status)
		const wrapper = mountView()
		expect(wrapper.findComponent(TimelineEntryStub).props('item')).toEqual(status)
		expect(store.getSinglePost.content).toBe('<p>Hello</p>')
	})

	it('falls back to the post from the initial state when it is not in the store yet', () => {
		const wrapper = mountView()
		expect(store.getSinglePost).toEqual(fromServer)
		expect(wrapper.findComponent(TimelineEntryStub).props('item')).toEqual(fromServer)
	})

	it('opens a post addressed by its ActivityPub id under the id the app uses', () => {
		// what a link from the rest of the fediverse looks like: the last
		// segment is the post's ActivityPub id, not the numeric one the client
		// API — and everything below this view — speaks
		const tail = '12345678901234567890'
		setState('item', { ...fromServer, uri: `https://cloud.example.org/index.php/apps/social/@bob/${tail}` })

		const wrapper = mountView(reactive({ name: 'single-post', params: { account: 'bob', id: tail } }))

		expect(store.changeTimelineType).toHaveBeenCalledWith({
			type: 'single-post',
			params: { account: 'bob', id: '123', type: 'single-post', singlePost: '123' },
		})
		expect(wrapper.findComponent(TimelineEntryStub).props('item').id).toBe('123')
	})

	it('does not answer one post with another the server happened to render', async () => {
		// the page is rendered once and the reader goes on reading: opening a
		// second post used to be answered with the first one again whenever the
		// store had not loaded the second
		const wrapper = mountView(reactive({ name: 'single-post', params: { account: 'bob', id: '456' } }))
		await flushPromises()

		expect(store.getSinglePost).toBeUndefined()
		expect(wrapper.find('.empty-name').text()).toBe('This post is not available')
	})

	it('renders the main post as a block with the ancestors above and the replies below', () => {
		store.addToStatuses(status)
		const wrapper = mountView()
		const entry = wrapper.findComponent(TimelineEntryStub)
		expect(entry.props('type')).toBe('single-post')
		expect(entry.props('element')).toBe('div')
		expect(entry.classes()).toContain('main-post')

		const lists = wrapper.findAllComponents(TimelineListStub)
		expect(lists).toHaveLength(2)
		expect(lists[0].props('showParents')).toBe(true)
		expect(lists[0].props('reverseOrder')).toBe(true)
		expect(lists[1].props('showParents')).toBe(false)
		expect(lists[1].classes()).toContain('descendants')
	})

	it('loads the author taken from the URL', async () => {
		mountView()
		expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('bob')
		expect(accountStore.fetchPublicAccountInfo).not.toHaveBeenCalled()
		await flushPromises()
		expect(fetchAccount).toHaveBeenCalledTimes(1)
	})

	it('uses the public author lookup on the public page', () => {
		makeStore({ public: true })
		mountView()
		expect(accountStore.fetchPublicAccountInfo).toHaveBeenCalledWith('bob')
		expect(accountStore.fetchAccountInfo).not.toHaveBeenCalled()
	})

	describe('a post nothing has loaded', () => {
		/**
		 * A link somebody sent, a reload, or a tile on Discover — whose posts
		 * belong to that view and never reach the store. `/context` answers
		 * with what is around a post and never with the post, so the page had
		 * nothing to draw and said the post did not exist.
		 */
		it('asks the server for it rather than saying it is gone', async () => {
			setState('item', undefined)
			window._nc_initial_state?.clear()
			document.getElementById('initial-state-social-item')?.remove()
			const fetchStatus = vi.spyOn(store, 'fetchStatus').mockImplementation(async (id) => {
				store.addToStatuses({ ...status, id })

				return status
			})

			const wrapper = mountView()
			await flushPromises()

			expect(fetchStatus).toHaveBeenCalledWith('123')
			expect(wrapper.findComponent(TimelineEntryStub).exists()).toBe(true)
		})

		it('asks for nothing when the post is already known', async () => {
			store.addToStatuses(status)
			const fetchStatus = vi.spyOn(store, 'fetchStatus')

			mountView()
			await flushPromises()

			expect(fetchStatus).not.toHaveBeenCalled()
		})

		it('still says a post is gone when the server does not have it either', async () => {
			setState('item', undefined)
			window._nc_initial_state?.clear()
			document.getElementById('initial-state-social-item')?.remove()
			vi.spyOn(store, 'fetchStatus').mockResolvedValue(null)

			const wrapper = mountView()
			await flushPromises()

			expect(wrapper.find('.empty-name').text()).toBe('This post is not available')
		})
	})

	describe('the reply box', () => {
		/**
		 * It used to be a composer at the top of the page, hidden until a
		 * reply button asked for it — so the page a reader opens to read a
		 * conversation had nowhere to say anything, and the way to find out
		 * was to press reply and watch the page jump.
		 */
		it('is under the post, pointed at it, without being asked for', () => {
			const wrapper = mountView()
			const composer = wrapper.findComponent(ComposerStub)

			expect(composer.exists()).toBe(true)
			expect(composer.props('inReplyTo').id).toBe('123')
			expect(composer.element.closest('.main-post__under')).not.toBeNull()
		})

		it('is not offered to a reader who is not logged in', () => {
			makeStore({ public: true })
			const wrapper = mountView()

			expect(wrapper.findComponent(ComposerStub).exists()).toBe(false)
		})
	})

	describe('the replies this page does not have', () => {
		const note = (wrapper) => wrapper.find('.thread__hidden')

		it('says nothing until the replies have been asked for and answered', async () => {
			store.addToStatuses({ ...status, replies_count: 3 })
			const wrapper = mountView()
			await flushPromises()

			expect(note(wrapper).exists()).toBe(false)
		})

		it('counts what the post says against what the thread shows', async () => {
			store.addToStatuses({ ...status, replies_count: 3 })
			const wrapper = mountView()
			await flushPromises()
			wrapper.findAllComponents(TimelineListStub).at(-1).vm.$emit('settled')
			store.addToTimeline({ ancestors: [], descendants: [{ ...parent, id: '200', in_reply_to_id: '123' }] })
			await nextTick()

			expect(note(wrapper).text()).toContain('2 replies are not shown here')
		})

		it('says nothing when the thread holds every reply the post claims', async () => {
			store.addToStatuses({ ...status, replies_count: 1 })
			const wrapper = mountView()
			await flushPromises()
			wrapper.findAllComponents(TimelineListStub).at(-1).vm.$emit('settled')
			store.addToTimeline({ ancestors: [], descendants: [{ ...parent, id: '200', in_reply_to_id: '123' }] })
			await nextTick()

			expect(note(wrapper).exists()).toBe(false)
		})

		it('does not count a reply to a reply against the post\'s own total', async () => {
			store.addToStatuses({ ...status, replies_count: 1 })
			const wrapper = mountView()
			await flushPromises()
			wrapper.findAllComponents(TimelineListStub).at(-1).vm.$emit('settled')
			// one direct reply and one reply to that reply: the post's count is 1
			store.addToTimeline({
				ancestors: [],
				descendants: [
					{ ...parent, id: '200', in_reply_to_id: '123' },
					{ ...parent, id: '201', in_reply_to_id: '200' },
				],
			})
			await nextTick()

			expect(note(wrapper).exists()).toBe(false)
		})
	})

	it('survives the ancestors arriving, whenever they arrive', async () => {
		const wrapper = mountView()
		expect(wrapper.find('.social__wrapper').exists()).toBe(true)

		store.addToTimeline({ ancestors: [parent], descendants: [] })
		await nextTick()

		expect(wrapper.findComponent(TimelineEntryStub).exists()).toBe(true)
	})

	it('scrolls the post into view when its first ancestors are loaded', async () => {
		const scrollIntoView = vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {})
		const wrapper = mountView()
		expect(scrollIntoView).not.toHaveBeenCalled()

		store.addToTimeline({ ancestors: [parent], descendants: [] })
		await nextTick()
		await nextTick()

		expect(scrollIntoView).toHaveBeenCalledTimes(1)
		expect(scrollIntoView.mock.instances[0]).toBe(wrapper.findComponent(TimelineEntryStub).element)
		expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' })
	})

	it('does not scroll again when more ancestors follow', async () => {
		const scrollIntoView = vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {})
		mountView()
		store.addToTimeline({ ancestors: [parent], descendants: [] })
		await nextTick()
		await nextTick()

		store.addToTimeline({ ancestors: [parent, grandParent], descendants: [] })
		await nextTick()
		await nextTick()

		expect(scrollIntoView).toHaveBeenCalledTimes(1)
	})

	it('scrolls to the post being replied to and stops listening after unmount', async () => {
		const scrollIntoView = vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {})
		const wrapper = mountView()

		eventBus.emit('composer-reply', { id: 'reply-1' })
		await nextTick()
		expect(scrollIntoView).toHaveBeenCalledTimes(1)
		expect(scrollIntoView.mock.instances[0]).toBe(wrapper.find('[data-social-status="reply-1"]').element)
		expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' })

		wrapper.unmount()
		eventBus.emit('composer-reply', { id: 'reply-1' })
		await nextTick()
		expect(scrollIntoView).toHaveBeenCalledTimes(1)
	})

	it('takes the author from the route, not from the shape of the browser URL', async () => {
		// this used to split window.location.href and slice a '@' off the
		// second-to-last segment, which broke on any other URL shape
		window.history.replaceState({}, '', '/index.php/apps/social/some/other/path')
		store.addToStatuses({ ...status, id: '9' })
		mountView(reactive({ name: 'single-post', params: { account: '@carol@remote.example', id: '9' } }))
		await flushPromises()

		expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('carol@remote.example')
		expect(store.changeTimelineType).toHaveBeenCalledWith({
			type: 'single-post',
			params: { account: 'carol@remote.example', id: '9', type: 'single-post', singlePost: '9' },
		})
	})

	it('renders a page rather than throwing when the post is gone', () => {
		// loadState throws when the key is absent, which is what a deleted
		// post looks like — and it threw inside beforeMount, so the view
		// never rendered at all
		setState('item', undefined)
		window._nc_initial_state?.clear()
		document.getElementById('initial-state-social-item')?.remove()

		const wrapper = mountView()

		expect(wrapper.find('.social__wrapper').exists()).toBe(true)
		expect(wrapper.find('.empty-name').text()).toBe('This post is not available')
		expect(wrapper.findComponent(TimelineEntryStub).exists()).toBe(false)
	})

	it('reloads when the route moves to another post in the same view', async () => {
		store.addToStatuses(status)
		const route = reactive({ name: 'single-post', params: { account: 'bob', id: '123' } })
		mountView(route)
		store.changeTimelineType.mockClear()

		route.params.id = '456'
		await nextTick()

		expect(store.changeTimelineType).toHaveBeenCalledWith(expect.objectContaining({
			params: expect.objectContaining({ id: '456' }),
		}))
	})

	it('leaves other composer-reply listeners attached when it unmounts', () => {
		const other = vi.fn()
		eventBus.on('composer-reply', other)
		const wrapper = mountView()

		wrapper.unmount()
		eventBus.emit('composer-reply', { id: 'reply-1' })

		expect(other).toHaveBeenCalledTimes(1)
		eventBus.off('composer-reply', other)
	})

	describe('the spine', () => {
		/**
		 * The line behind the avatars says that what it runs through is one
		 * conversation. Beside a post with nothing above it and nothing below
		 * it there is no conversation to say anything about, and the line ran
		 * from nothing to nothing.
		 */
		it('is not drawn beside a post that is on its own', async () => {
			const wrapper = mountView()
			await flushPromises()

			expect(wrapper.find('.thread').classes()).not.toContain('thread--connected')
		})

		it('is drawn when the post has replies', async () => {
			const wrapper = mountView()
			await flushPromises()
			// the replies arrive after the view does: `load()` resets the
			// timeline on its way in
			store.addToTimeline({ ancestors: [], descendants: [status] })
			await nextTick()

			expect(wrapper.find('.thread').classes()).toContain('thread--connected')
		})

		it('is drawn when the post is itself a reply', async () => {
			const wrapper = mountView()
			await flushPromises()
			store.addToTimeline({ ancestors: [parent], descendants: [] })
			await nextTick()

			expect(wrapper.find('.thread').classes()).toContain('thread--connected')
		})
	})
})

describe('opening a post, for For you', () => {
	beforeEach(() => {
		window.history.replaceState({}, '', '/apps/social/@bob/123')
		setState('item', fromServer)
		signalNow.mockClear()
	})

	afterEach(() => {
		while (mounted.length > 0) {
			mounted.pop().unmount()
		}
	})

	it('is reported once, from the detail view, while learning is on', async () => {
		makeStore({ interests: { enabled: true, learning: true, paused: false } })
		mountView()
		await flushPromises()

		expect(signalNow).toHaveBeenCalledTimes(1)
		expect(signalNow).toHaveBeenCalledWith(expect.objectContaining({ id: '123' }), 'open', 'detail', expect.any(Function))
	})

	it('is not reported while learning is paused', async () => {
		makeStore({ interests: { enabled: true, learning: true, paused: true } })
		mountView()
		await flushPromises()

		expect(signalNow).not.toHaveBeenCalled()
	})
})
