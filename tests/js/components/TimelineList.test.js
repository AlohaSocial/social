/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { showError } from '../../../src/services/toast.js'
import TimelineList from '../../../src/components/TimelineList.vue'
import eventBus, { NOTIFICATIONS_READ } from '../../../src/services/eventBus.js'
import { offTimelinePush, onTimelinePush } from '../../../src/services/timelinePush.js'
import EmptyContent from '../../../src/components/EmptyContent.vue'
import { SCENES } from '../../../src/components/illustrations/AlohaScene.vue'
import TimelineSkeleton from '../../../src/components/TimelineSkeleton.vue'
import { createPinia, setActivePinia } from 'pinia'
import { useNotificationsStore } from '../../../src/store/notifications.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/timelinePush.js', () => ({
	onTimelinePush: vi.fn(() => false),
	offTimelinePush: vi.fn(),
}))

// @nextcloud/auth reads the user from <head>, which the harness does not set
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getCurrentUser: () => ({ uid: 'alice', displayName: 'Alice', isAdmin: false }),
}))

class FakeIntersectionObserver {
	static instances = []

	constructor(callback, options) {
		this.callback = callback
		this.options = options
		this.observe = vi.fn()
		this.unobserve = vi.fn()
		this.disconnect = vi.fn()
		FakeIntersectionObserver.instances.push(this)
	}
}

const observer = () => FakeIntersectionObserver.instances.at(-1)

async function intersect(isIntersecting = true) {
	observer().callback([{ isIntersecting }])
	await flushPromises()
}

function status(id) {
	return {
		id,
		created_at: `2026-09-0${id.length}T10:00:00Z`,
		content: `<p>Status ${id}</p>`,
		reblog: null,
		account: { id: '1', acct: 'alice', username: 'alice', display_name: 'Alice' },
	}
}

const TimelineEntryStub = {
	name: 'TimelineEntry',
	props: ['item', 'type', 'depth', 'unread', 'immediate'],
	// carries the real component's class and tabindex, because the list finds
	// entries by that class and sends focus to them
	template: '<li class="timeline-entry timeline-entry-stub" tabindex="-1" :data-id="item.id" :data-depth="depth" :data-unread="unread ? \'yes\' : \'no\'" />',
}
const entryDepths = (wrapper) => wrapper.findAll('.timeline-entry-stub').map((entry) => [entry.attributes('data-id'), Number(entry.attributes('data-depth'))])

const entryIds = (wrapper) => wrapper.findAll('.timeline-entry-stub').map((entry) => entry.attributes('data-id'))

// what `getTimelineIdentity` is built from, so a test can swap the list the
// store is holding the way a navigation does
function showing(identity) {
	const [type, account, params] = JSON.parse(identity)

	return { type, account, params }
}

// `responses` are the successive results of `fetchTimeline`; an Error rejects
function mountList({
	timeline = [],
	parents = [],
	identity = '["home","",{}]',
	route = { name: 'timeline', params: { type: 'home' } },
	serverData = { public: false, cloudAddress: 'https://cloud.example.org' },
	responses = [[]],
	props = {},
	restored = false,
	attachTo = undefined,
	lastRead = 0,
	homeMarker = null,
} = {}) {
	const dispatch = vi.fn()
	for (const response of responses) {
		if (response instanceof Error) {
			dispatch.mockRejectedValueOnce(response)
		} else {
			dispatch.mockResolvedValueOnce(response)
		}
	}
	dispatch.mockResolvedValue([])

	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData(serverData)
	const store = useTimelineStore()
	const notificationsStore = useNotificationsStore()
	vi.spyOn(store, 'fetchTimeline').mockImplementation(dispatch)
	vi.spyOn(notificationsStore, 'markNotificationsRead').mockResolvedValue(undefined)
	vi.spyOn(notificationsStore, 'fetchLastRead').mockResolvedValue(lastRead)
	vi.spyOn(store, 'fetchHomeMarker').mockResolvedValue(homeMarker)
	vi.spyOn(store, 'markHomeRead').mockResolvedValue(undefined)
	store.$patch({
		statuses: Object.fromEntries([...timeline, ...parents].map((entry) => [entry.id, entry])),
		timeline: timeline.map((entry) => entry.id),
		parentsTimeline: parents.map((entry) => entry.id),
		restored,
		...showing(identity),
	})
	const wrapper = mount(TimelineList, {
		attachTo,
		props: { type: 'home', ...props },
		global: {
			plugins: [pinia],
			mocks: { $route: route },
			stubs: { TimelineEntry: TimelineEntryStub },
		},
	})
	return { wrapper, dispatch, store, notificationsStore }
}

const emptyTitle = (wrapper) => wrapper.findComponent(EmptyContent).props('item').title

describe('TimelineList', () => {
	beforeEach(() => {
		FakeIntersectionObserver.instances = []
		onTimelinePush.mockClear()
		onTimelinePush.mockReturnValue(false)
		offTimelinePush.mockClear()
		vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver)
		vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
		showError.mockClear()
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.unstubAllGlobals()
	})

	describe('the replies under a post', () => {
		// the post being read is 1; replies carry the id of what they answer
		const reply = (id, inReplyTo, day) => ({ ...status(id), created_at: `2026-09-${day}T10:00:00Z`, in_reply_to_id: inReplyTo })
		const thread = (timeline) => mountList({
			timeline,
			identity: '["single-post","",{"id":"1","singlePost":"1"}]',
			route: { name: 'single-post', params: { type: 'single-post', id: '1' } },
			props: { type: 'single-post' },
		})

		it('reads as a conversation: each reply followed by the replies to it, oldest first', async () => {
			// stored newest first, as every timeline is; a reply to a reply
			// used to land above the post it answered
			const { wrapper } = thread([
				reply('40', '20', '14'),
				reply('30', '1', '13'),
				reply('21', '20', '12'),
				reply('20', '1', '11'),
			])
			await flushPromises()

			expect(entryDepths(wrapper)).toEqual([['20', 0], ['21', 1], ['40', 1], ['30', 0]])
		})

		it('keeps a reply whose parent is not on the page, at the top level with its own replies under it', async () => {
			// the parent was deleted, or is a branch this instance never got
			const { wrapper } = thread([reply('50', '99', '12'), reply('51', '50', '13'), reply('20', '1', '11')])
			await flushPromises()

			expect(entryDepths(wrapper)).toEqual([['20', 0], ['50', 0], ['51', 1]])
		})

		it('indents nothing in an ordinary timeline', async () => {
			const { wrapper } = mountList({ timeline: [{ ...status('20'), in_reply_to_id: '1' }, status('10')] })
			await flushPromises()

			expect(entryDepths(wrapper)).toEqual([['20', 0], ['10', 0]])
		})
	})

	describe('entries', () => {
		it('renders one entry per status of the timeline', () => {
			const { wrapper } = mountList({ timeline: [status('30'), status('20')] })
			expect(entryIds(wrapper)).toEqual(['30', '20'])
			expect(wrapper.findAllComponents(TimelineEntryStub).map((entry) => entry.props('type'))).toEqual(['home', 'home'])
		})

		it('shows the ancestors instead when asked for the parents', () => {
			const { wrapper } = mountList({ timeline: [status('30')], parents: [status('5')], props: { showParents: true } })
			expect(entryIds(wrapper)).toEqual(['5'])
		})

		it('reverses the order on request', () => {
			const { wrapper } = mountList({ timeline: [status('30'), status('20')], props: { reverseOrder: true } })
			expect(entryIds(wrapper)).toEqual(['20', '30'])
		})
	})

	describe('posts arriving while reading', () => {
		// what the app renders into: NcAppContent's column scrolls, the window
		// does not, so `window.scrollY` is 0 wherever the reader is
		let column = null

		beforeEach(() => {
			column = document.createElement('div')
			column.id = 'app-content-vue'
			column.scrollTo = vi.fn()
			document.body.appendChild(column)
		})

		afterEach(() => {
			column.remove()
			column = null
			window.scrollY = 0
		})

		/** @param {number} y how far down the column the reader is */
		const scrolledTo = (y) => {
			column.scrollTop = y
		}

		it('offers to jump to posts that arrived out of sight', async () => {
			scrolledTo(800)
			const { wrapper } = mountList({ responses: [[], [status('9'), status('8')]] })
			await flushPromises()

			await wrapper.vm.fetchNewStatuses()
			await flushPromises()

			const pill = wrapper.find('.new-posts-pill')
			expect(pill.exists()).toBe(true)
			expect(pill.text()).toContain('2')
		})

		it('says nothing when the top of the list is already on screen', async () => {
			scrolledTo(0)
			const { wrapper } = mountList({ responses: [[], [status('9')]] })
			await flushPromises()

			await wrapper.vm.fetchNewStatuses()
			await flushPromises()

			// up there the posts simply appear; a pill would be noise
			expect(wrapper.find('.new-posts-pill').exists()).toBe(false)
		})

		it('puts the pill below the composer it used to be painted behind', async () => {
			// both are sticky and the composer is the taller of the two, in the
			// same stacking context and above it: the pill only shows once the
			// reader has scrolled, which is exactly when the composer is stuck
			// to the top of the page
			scrolledTo(800)
			const { wrapper } = mountList({ responses: [[], [status('9')]], attachTo: document.body })
			await flushPromises()

			// where the view puts it: a sibling above the list, not a parent
			const composer = document.createElement('div')
			composer.className = 'new-post'
			Object.defineProperty(composer, 'offsetHeight', { value: 72 })
			wrapper.element.parentElement.insertBefore(composer, wrapper.element)

			await wrapper.vm.fetchNewStatuses()
			await flushPromises()

			expect(wrapper.find('.new-posts-pill').attributes('style')).toContain('top: 80px')

			wrapper.unmount()
			composer.remove()
		})

		it('leaves the pill where it is on a page with no composer', async () => {
			scrolledTo(800)
			const { wrapper } = mountList({ responses: [[], [status('9')]], attachTo: document.body })
			await flushPromises()

			await wrapper.vm.fetchNewStatuses()
			await flushPromises()

			expect(wrapper.find('.new-posts-pill').attributes('style')).toBeUndefined()

			wrapper.unmount()
		})

		it('scrolls the column back to the top and forgets the count when asked', async () => {
			scrolledTo(800)
			const { wrapper } = mountList({ responses: [[], [status('9')]] })
			await flushPromises()
			await wrapper.vm.fetchNewStatuses()
			await flushPromises()

			await wrapper.find('.new-posts-pill').trigger('click')

			expect(column.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' })
			expect(wrapper.find('.new-posts-pill').exists()).toBe(false)
		})

		it('does not read the window, which never scrolls in this app', async () => {
			// the pill was dead code: `window.scrollY` is 0 however far down
			// the column the reader is, so posts arriving from a poll or a
			// push were prepended under somebody reading
			Object.defineProperty(window, 'scrollY', { value: 0, configurable: true, writable: true })
			scrolledTo(800)
			const { wrapper } = mountList({ responses: [[], [status('9')]] })
			await flushPromises()

			await wrapper.vm.fetchNewStatuses()
			await flushPromises()

			expect(wrapper.find('.new-posts-pill').exists()).toBe(true)
		})

		it('falls back to the window where there is no content column', async () => {
			// the dashboard widget and the public pages render outside it
			column.remove()
			Object.defineProperty(window, 'scrollY', { value: 800, configurable: true, writable: true })
			const scrollTo = vi.fn()
			window.scrollTo = scrollTo
			const { wrapper } = mountList({ responses: [[], [status('9')]] })
			await flushPromises()
			await wrapper.vm.fetchNewStatuses()
			await flushPromises()

			await wrapper.find('.new-posts-pill').trigger('click')

			expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' })
		})
	})

	describe('polling', () => {
		const setVisibility = (state) => {
			Object.defineProperty(document, 'visibilityState', { value: state, configurable: true })
			document.dispatchEvent(new Event('visibilitychange'))
		}

		afterEach(() => {
			setVisibility('visible')
		})

		it('keeps polling while the tab is watched', async () => {
			const { dispatch } = mountList()
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(31000)
			await flushPromises()

			expect(dispatch).toHaveBeenCalled()
		})

		it('does not ask while the tab is hidden', async () => {
			const { wrapper, dispatch } = mountList()
			await flushPromises()
			dispatch.mockClear()

			setVisibility('hidden')
			vi.advanceTimersByTime(31000)
			await flushPromises()

			// traffic nobody is waiting for is most of the traffic there is
			expect(dispatch).not.toHaveBeenCalled()
			expect(wrapper.vm.hiddenSince).toBeGreaterThan(0)
		})

		it('catches up on the way back, when it was away longer than a tick', async () => {
			const { wrapper, dispatch } = mountList()
			await flushPromises()
			setVisibility('hidden')
			vi.advanceTimersByTime(31000)
			await flushPromises()
			dispatch.mockClear()
			wrapper.vm.hiddenSince = Date.now() - 60000

			setVisibility('visible')
			await flushPromises()

			expect(dispatch).toHaveBeenCalled()
			expect(wrapper.vm.hiddenSince).toBe(0)
		})

		it('does not catch up after a glance away', async () => {
			const { wrapper, dispatch } = mountList()
			await flushPromises()
			setVisibility('hidden')
			vi.advanceTimersByTime(31000)
			await flushPromises()
			dispatch.mockClear()
			wrapper.vm.hiddenSince = Date.now()

			setVisibility('visible')
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
		})

		it('stops listening for the tab once it is gone', async () => {
			const { wrapper } = mountList()
			await flushPromises()
			wrapper.unmount()

			expect(() => setVisibility('hidden')).not.toThrow()
		})
	})

	describe('reading with the keyboard', () => {
		afterEach(() => {
			eventBus.all.clear()
		})

		const focusedIds = (wrapper) => wrapper.findAll('.timeline-entry--focused')
			.map((entry) => entry.attributes('data-id'))

		it('walks the list with j and k, and stops at both ends', async () => {
			const { wrapper } = mountList({ timeline: [status('1'), status('22'), status('333')] })
			await flushPromises()
			// whatever order the list settled on is the order j walks
			const order = entryIds(wrapper)

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()
			expect(focusedIds(wrapper)).toEqual([order[0]])

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()
			expect(focusedIds(wrapper)).toEqual([order[1]])

			// k walks back, and the top is the top
			eventBus.emit('shortcut:previous')
			eventBus.emit('shortcut:previous')
			eventBus.emit('shortcut:previous')
			await wrapper.vm.$nextTick()
			expect(focusedIds(wrapper)).toEqual([order[0]])
		})

		it('announces the focused post, so the post itself can act on l/b/r', async () => {
			const heard = vi.fn()
			eventBus.on('timeline:focused', heard)
			const { wrapper } = mountList({ timeline: [status('1')] })
			await flushPromises()

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()

			expect(heard).toHaveBeenCalledWith(expect.objectContaining({ id: '1' }))
		})

		it('does nothing on an empty timeline', async () => {
			const { wrapper } = mountList({ timeline: [] })
			await flushPromises()

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()

			expect(focusedIds(wrapper)).toEqual([])
		})

		it('stops listening once it is gone', async () => {
			const { wrapper } = mountList({ timeline: [status('1')] })
			await flushPromises()
			wrapper.unmount()

			expect(() => eventBus.emit('shortcut:next')).not.toThrow()
		})

		it('leaves the ancestors list alone, so one press moves one focus', async () => {
			// both lists in the single-post view listened, so j walked the
			// ancestors and the replies at the same time
			const { wrapper } = mountList({ parents: [status('5')], props: { showParents: true } })
			await flushPromises()

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()

			expect(focusedIds(wrapper)).toEqual([])
		})
	})

	describe('keyboard reading', () => {
		const posts = [
			{ id: '1', account: { id: 'a' }, content: 'one' },
			{ id: '2', account: { id: 'b' }, content: 'two' },
		]

		it('moves the keyboard, not only a highlight', async () => {
			const { wrapper } = mountList({ timeline: posts })
			const entries = wrapper.findAll('.timeline-entry')
			entries.forEach((entry) => {
				entry.element.focus = vi.fn()
				entry.element.scrollIntoView = vi.fn()
			})

			eventBus.emit('shortcut:next')
			await wrapper.vm.$nextTick()

			// scrolling alone leaves the keyboard where it was, which is the
			// one thing a reader using j/k cannot afford
			expect(entries[0].element.focus).toHaveBeenCalledWith({ preventScroll: true })
		})

		it('gives an entry somewhere for focus to land', () => {
			const { wrapper } = mountList({ timeline: posts })

			expect(wrapper.find('.timeline-entry').attributes('tabindex')).toBe('-1')
		})
	})

	describe('marking notifications read', () => {
		// the shape the server actually sends: the row id, as a string, in `id`
		const notifications = [
			{ id: '1788875057712399', type: 'mention', account: { id: 'a' } },
			{ id: '1788875057712412', type: 'favourite', account: { id: 'b' } },
		]

		const onNotifications = (over = {}) => mountList({
			timeline: notifications,
			props: { type: 'notifications' },
			route: { name: 'timeline', params: { type: 'notifications' } },
			...over,
		})

		beforeEach(() => {
			// the dwell is a timeout, and these tests are about when it fires
			vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'] })
		})

		/**
		 * @param {number} ms how long the reader looks at the page
		 */
		async function look(ms) {
			await vi.advanceTimersByTimeAsync(ms)
		}

		it('marks nothing read merely by rendering', async () => {
			// the badge is shared with every other client, so a page that
			// rendered in a background tab used to clear the reader's phone
			const { notificationsStore } = onNotifications()
			await flushPromises()

			expect(notificationsStore.markNotificationsRead).not.toHaveBeenCalled()
		})

		it('records the newest one seen once the page has been looked at', async () => {
			const { notificationsStore } = onNotifications()
			await flushPromises()

			await look(2000)

			// the highest id on screen, not the first or the last in the array
			expect(notificationsStore.markNotificationsRead).toHaveBeenCalledWith('1788875057712412')
		})

		it('covers every id a grouped card stands for', async () => {
			// nine favourites drawn as one card are still nine rows, and the
			// marker has to reach the newest of them
			const { notificationsStore } = onNotifications({
				timeline: [
					{ id: '40', type: 'favourite', account: { id: 'a' }, status: { id: '7' } },
					{ id: '41', type: 'favourite', account: { id: 'b' }, status: { id: '7' } },
				],
			})
			await flushPromises()

			await look(2000)

			expect(notificationsStore.markNotificationsRead).toHaveBeenCalledWith('41')
		})

		it('does not count a tab nobody is looking at as looking', async () => {
			const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
			const { notificationsStore } = onNotifications()
			await flushPromises()

			await look(5000)
			expect(notificationsStore.markNotificationsRead).not.toHaveBeenCalled()

			// and the moment the reader comes back to it, the dwell starts
			visibility.mockReturnValue('visible')
			document.dispatchEvent(new Event('visibilitychange'))
			await look(2000)

			expect(notificationsStore.markNotificationsRead).toHaveBeenCalledWith('1788875057712412')
		})

		it('says it once for the same newest notification', async () => {
			const { wrapper, notificationsStore } = onNotifications()
			await flushPromises()
			await look(2000)

			// nothing new arrived; there is nothing more to report
			wrapper.vm.armSeenTimer()
			await look(2000)

			expect(notificationsStore.markNotificationsRead).toHaveBeenCalledTimes(1)
		})

		it('leaves the marker alone on any other timeline', async () => {
			const { notificationsStore } = mountList({ timeline: notifications, props: { type: 'home' } })
			await flushPromises()
			await look(5000)

			expect(notificationsStore.markNotificationsRead).not.toHaveBeenCalled()
		})

		it('records nothing when there is nothing to show', async () => {
			const { notificationsStore } = onNotifications({ timeline: [] })
			await flushPromises()
			await look(5000)

			expect(notificationsStore.markNotificationsRead).not.toHaveBeenCalled()
		})
	})

	describe('what is new since the reader last looked', () => {
		const notifications = [
			{ id: '40', type: 'mention', account: { id: 'a' } },
			{ id: '30', type: 'mention', account: { id: 'b' } },
			{ id: '20', type: 'mention', account: { id: 'c' } },
		]

		const onNotifications = (lastRead) => mountList({
			timeline: notifications,
			props: { type: 'notifications' },
			route: { name: 'timeline', params: { type: 'notifications' } },
			lastRead,
		})

		const headings = (wrapper) => wrapper.findAll('.timeline-divider').map((line) => line.text())
		const unread = (wrapper) => wrapper.findAll('.timeline-entry-stub').map((entry) => entry.attributes('data-unread'))

		it('draws the line at the marker the server keeps', async () => {
			const { wrapper } = onNotifications(30)
			await flushPromises()

			expect(headings(wrapper)).toEqual(['New', 'Earlier'])
			expect(unread(wrapper)).toEqual(['yes', 'no', 'no'])
		})

		it('draws no line on a page that is new all the way down', async () => {
			const { wrapper } = onNotifications(5)
			await flushPromises()

			expect(headings(wrapper)).toEqual([])
			// but every card on it is still marked: a heading over the whole
			// page separates nothing, which is not the same as there being
			// nothing new on it. The marks used to be read off the heading's
			// index, so this -- the case the badge is loudest about -- showed
			// the reader nothing at all
			expect(unread(wrapper)).toEqual(['yes', 'yes', 'yes'])
		})

		it('marks the lot for an account that has never read anything', async () => {
			// a marker of 0 is "nothing read yet", which is also what the badge
			// counts against; it used to be taken for "all read" and left the
			// page bare under a badge saying there were four
			const { wrapper } = onNotifications(0)
			await flushPromises()

			expect(unread(wrapper)).toEqual(['yes', 'yes', 'yes'])
			expect(headings(wrapper)).toEqual([])
		})

		it('marks nothing until the server has said where the marker is', async () => {
			const { wrapper } = onNotifications(30)

			// the answer has not arrived: a page that lit up and then settled
			// would read as a fault
			expect(unread(wrapper)).toEqual(['no', 'no', 'no'])

			await flushPromises()
			expect(unread(wrapper)).toEqual(['yes', 'no', 'no'])
		})

		it('takes the marks down when the page above says the lot was read', async () => {
			const { wrapper } = onNotifications(30)
			await flushPromises()
			expect(unread(wrapper)).toEqual(['yes', 'no', 'no'])

			eventBus.emit(NOTIFICATIONS_READ, 40)
			await wrapper.vm.$nextTick()

			expect(headings(wrapper)).toEqual([])
			expect(unread(wrapper)).toEqual(['no', 'no', 'no'])
		})

		it('never takes the line backwards when told of an older marker', async () => {
			const { wrapper } = onNotifications(30)
			await flushPromises()

			eventBus.emit(NOTIFICATIONS_READ, 10)
			await wrapper.vm.$nextTick()

			expect(unread(wrapper)).toEqual(['yes', 'no', 'no'])
		})

		it('draws no line when nothing has arrived since', async () => {
			const { wrapper } = onNotifications(40)
			await flushPromises()

			expect(headings(wrapper)).toEqual([])
			expect(unread(wrapper)).toEqual(['no', 'no', 'no'])
		})

		it('leaves the line where the reader found it after the page is read', async () => {
			// reading the page moves the marker; a line that followed it would
			// rub out the very boundary it is there to show
			const { wrapper } = onNotifications(30)
			await flushPromises()
			await vi.advanceTimersByTimeAsync(2000)

			expect(headings(wrapper)).toEqual(['New', 'Earlier'])
		})

		it('reads the marker for a notifications page arrived at rather than landed on', async () => {
			// the router-view is reused across timelines, so mounting is not
			// what tells this page it is the notifications page
			const { wrapper, store, notificationsStore } = mountList({ timeline: [] })
			await flushPromises()
			expect(notificationsStore.fetchLastRead).not.toHaveBeenCalled()

			await wrapper.setProps({ type: 'notifications' })
			store.$patch({ ...showing('["notifications","",{}]'), statuses: Object.fromEntries(notifications.map((entry) => [entry.id, entry])), timeline: notifications.map((entry) => entry.id) })
			await flushPromises()

			expect(notificationsStore.fetchLastRead).toHaveBeenCalledTimes(1)
		})

		it('folds a run of favourites of one post into one card', async () => {
			const { wrapper } = mountList({
				timeline: [
					{ id: '41', type: 'favourite', account: { id: 'a' }, status: { id: '7' } },
					{ id: '40', type: 'favourite', account: { id: 'b' }, status: { id: '7' } },
				],
				props: { type: 'notifications' },
				route: { name: 'timeline', params: { type: 'notifications' } },
			})
			await flushPromises()

			expect(entryIds(wrapper)).toEqual(['41'])
		})

		it('leaves every other timeline ungrouped', async () => {
			const { wrapper } = mountList({ timeline: [status('30'), status('20')] })
			await flushPromises()

			expect(entryIds(wrapper)).toEqual(['30', '20'])
		})
	})

	describe('loading pages', () => {
		it('requests the first page on mount', async () => {
			const { dispatch } = mountList()
			await flushPromises()
			expect(dispatch).toHaveBeenCalledTimes(1)
			expect(dispatch).toHaveBeenCalledWith({})
		})

		it('asks for nothing when the list came back with the reader', async () => {
			// Back out of a post: the store still holds every page they had
			// read, and a request here would have appended the page after them
			// while the browser was putting their scroll offset back
			const { dispatch } = mountList({ timeline: [status('30'), status('20')], restored: true })
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
		})

		it('says when the list is on the page, so a restored offset can be applied', async () => {
			const rendered = vi.fn()
			eventBus.on('timeline:rendered', rendered)

			mountList()
			await flushPromises()

			expect(rendered).toHaveBeenCalled()
			eventBus.off('timeline:rendered', rendered)
		})

		it('requests the statuses older than the last one shown', async () => {
			const { dispatch } = mountList({ timeline: [status('30'), status('20')] })
			await flushPromises()
			expect(dispatch).toHaveBeenCalledWith({ max_id: '20' })
		})

		it('requests the statuses newer than the newest one shown in reverse order', async () => {
			// The newest loaded id, regardless of the created_at display order —
			// paging on any other entry refetches a page the store already has.
			const { dispatch } = mountList({ timeline: [status('30'), status('20')], props: { reverseOrder: true } })
			await flushPromises()
			expect(dispatch).toHaveBeenCalledWith({ min_id: '30' })
		})

		it('sends a twenty-digit cursor with every digit of it', async () => {
			// Number.parseInt rounds these two to the same double, and
			// Math.min then answers the *newer* of the two: a max_id above the
			// oldest post on screen refetches it for ever, so the list never
			// reaches its end.
			const { dispatch } = mountList({
				timeline: [status('1789553297940456473'), status('1789553297940456400')],
			})
			await flushPromises()

			expect(dispatch).toHaveBeenCalledWith({ max_id: '1789553297940456400' })
		})

		it('sends a twenty-digit reverse cursor with every digit of it', async () => {
			const { dispatch } = mountList({
				timeline: [status('1789553297940456400'), status('1789553297940456473')],
				props: { reverseOrder: true },
			})
			await flushPromises()

			expect(dispatch).toHaveBeenCalledWith({ min_id: '1789553297940456473' })
		})

		it('shows post-shaped placeholders while the first page loads, not a spinner', async () => {
			let finish
			const {
				wrapper,
			} = mountList({ responses: [new Promise((resolve) => { finish = resolve })] })
			await flushPromises()

			// an empty page with a spinner says nothing about what is coming
			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(true)
			expect(wrapper.find('.list-loading').exists()).toBe(false)
			expect(wrapper.find('.list-end').exists()).toBe(false)

			finish([status('1')])
			await flushPromises()

			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(false)
			expect(wrapper.find('.list-end').exists()).toBe(true)
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
		})

		it('shows a rolling wave for a later page, where the posts are already on screen', async () => {
			let finish
			const { wrapper } = mountList({
				timeline: [status('1')],
				responses: [[status('1')], new Promise((resolve) => { finish = resolve })],
			})
			await flushPromises()
			await intersect()

			expect(wrapper.find('.list-loading').exists()).toBe(true)
			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(false)

			finish([status('22')])
			await flushPromises()

			expect(wrapper.find('.list-loading').exists()).toBe(false)
		})

		it('shows a failed first page as an error with a retry, not as an empty timeline', async () => {
			const { wrapper } = mountList({ responses: [new Error('network')] })
			await flushPromises()

			expect(wrapper.find('.list-loading').exists()).toBe(false)
			// "No posts found / Posts from people you follow will show up
			// here" for a server error was the old answer
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
			expect(wrapper.find('.timeline-error').text()).toContain('The posts could not be loaded.')
			expect(wrapper.find('.timeline-error').attributes('role')).toBe('alert')
		})

		it('keeps paging alive after a failure', async () => {
			const { dispatch } = mountList({ responses: [new Error('network'), new Error('network')] })
			await flushPromises()

			// allLoaded used to be set here, which stopped the observer from
			// ever firing again: infinite scroll was dead until a reload
			await intersect()
			await flushPromises()
			expect(dispatch).toHaveBeenCalledTimes(2)
		})

		it('clears the error and loads again when the retry is pressed', async () => {
			const { wrapper, dispatch } = mountList({ responses: [new Error('network'), [status('20')]] })
			await flushPromises()
			expect(wrapper.find('.timeline-error').exists()).toBe(true)

			await wrapper.find('.timeline-error button').trigger('click')
			await flushPromises()
			expect(dispatch).toHaveBeenCalledTimes(2)
			expect(wrapper.find('.timeline-error').exists()).toBe(false)
		})

		it('starts over when the store swaps the list underneath it', async () => {
			// the router-view is no longer keyed on the full path, so going
			// from Home to Global (or to another profile) reuses this
			// component: without this nothing would ask for the new list
			const { wrapper, dispatch, store } = mountList({ responses: [[]] })
			await flushPromises()
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(true)
			dispatch.mockClear()
			dispatch.mockResolvedValue([status('9')])

			store.$patch(showing('["federated","",{}]'))
			await flushPromises()

			expect(dispatch).toHaveBeenCalledWith({})
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
		})

		it('asks for the new list even while the previous one is still loading', async () => {
			// Clicking Global during the ~200 ms the home timeline is loading
			// left `loading` set, so infiniteHandler returned at once and
			// nothing was ever requested for the list now on screen.
			let finishHome
			const {
				dispatch,
				store,
			} = mountList({ responses: [new Promise((resolve) => { finishHome = resolve })] })
			await flushPromises()
			dispatch.mockClear()
			dispatch.mockResolvedValue([status('9')])

			store.$patch(showing('["federated","",{}]'))
			await flushPromises()

			expect(dispatch).toHaveBeenCalledWith({})
			finishHome([])
		})

		it('lets the previous list\'s answer decide nothing once it arrives', async () => {
			let finishHome
			const {
				wrapper,
				dispatch,
				store,
			} = mountList({ responses: [new Promise((resolve) => { finishHome = resolve })] })
			await flushPromises()

			let finishFederated
			dispatch.mockClear()
			dispatch.mockReturnValue(new Promise((resolve) => {
				finishFederated = resolve
			}))
			store.$patch(showing('["federated","",{}]'))
			await flushPromises()

			// home answers "nothing more", which used to end the new list
			// before its own first page had come back
			finishHome([])
			await flushPromises()
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(true)

			finishFederated([status('9')])
			await flushPromises()
			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(false)
		})

		it('does not start over for the ancestors list, which fetches nothing', async () => {
			const { dispatch, store } = mountList({ parents: [status('5')], props: { showParents: true } })
			await flushPromises()

			store.$patch(showing('["single-post","",{"id":"9"}]'))
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
		})

		it('says less when a later page fails and the reader already has posts', async () => {
			const { wrapper } = mountList({ timeline: [status('30')], responses: [new Error('network')] })
			await flushPromises()

			expect(wrapper.find('.timeline-error').text()).toContain('No more posts could be loaded.')
		})
	})

	describe('infinite scroll', () => {
		it('watches the sentinel below the list with a margin', async () => {
			const { wrapper } = mountList()
			await flushPromises()
			expect(observer().options).toEqual({ rootMargin: '200px' })
			expect(observer().observe).toHaveBeenCalledWith(wrapper.find('.list-sentinel').element)
		})

		it('loads the next page when the sentinel comes into view', async () => {
			const { dispatch } = mountList({ timeline: [status('30'), status('20')], responses: [[status('20')], [status('10')]] })
			await flushPromises()
			expect(dispatch).toHaveBeenCalledTimes(1)

			await intersect()

			expect(dispatch).toHaveBeenCalledTimes(2)
			expect(dispatch).toHaveBeenLastCalledWith({ max_id: '20' })
		})

		it('does nothing when the sentinel leaves the view', async () => {
			const { dispatch } = mountList({ responses: [[status('1')]] })
			await flushPromises()
			await intersect(false)
			expect(dispatch).toHaveBeenCalledTimes(1)
		})

		it('does not request a page while one is still loading', async () => {
			let finish
			const {
				dispatch,
			} = mountList({ responses: [new Promise((resolve) => { finish = resolve })] })

			await intersect()
			expect(dispatch).toHaveBeenCalledTimes(1)

			finish([status('1')])
			await flushPromises()
			await intersect()
			expect(dispatch).toHaveBeenCalledTimes(2)
		})

		it('stops requesting once the server returned an empty page', async () => {
			const { dispatch } = mountList({ responses: [[]] })
			await flushPromises()
			await intersect()
			expect(dispatch).toHaveBeenCalledTimes(1)
		})
	})

	describe('polling for new statuses', () => {
		it('registers a push listener and slows polling down when push is available', async () => {
			onTimelinePush.mockReturnValueOnce(true)
			const { dispatch } = mountList({ timeline: [status('30')] })
			await flushPromises()
			dispatch.mockClear()

			expect(onTimelinePush).toHaveBeenCalledWith(expect.any(Function))

			// a pushed event refreshes immediately
			onTimelinePush.mock.calls[onTimelinePush.mock.calls.length - 1][0]()
			await flushPromises()
			expect(dispatch).toHaveBeenCalledWith({ min_id: '30' })
			dispatch.mockClear()

			// the 30-second poll is off; the safety net runs every 5 minutes
			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()
			expect(dispatch).not.toHaveBeenCalled()

			vi.advanceTimersByTime(270 * 1000)
			await flushPromises()
			expect(dispatch).toHaveBeenCalledWith({ min_id: '30' })
		})

		it('stops listening for pushed events once it is gone', async () => {
			// `listen()` cannot be undone, so a list that subscribed to it
			// directly stayed subscribed for the life of the page: every
			// thread opened left another dead component behind, and one
			// pushed event then ran that many identical timeline requests
			const { wrapper } = mountList({ timeline: [status('30')] })
			await flushPromises()

			const handler = onTimelinePush.mock.calls[0][0]
			wrapper.unmount()

			expect(offTimelinePush).toHaveBeenCalledWith(handler)
		})

		it('asks for statuses newer than the first one every 30 seconds', async () => {
			const { dispatch } = mountList({ timeline: [status('30'), status('20')] })
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).toHaveBeenCalledTimes(1)
			expect(dispatch).toHaveBeenCalledWith({ min_id: '30' })
		})

		it('polls on the exact newest id, twenty digits and all', async () => {
			// rounded down through a Number, min_id names a post already on
			// screen: it comes back on every tick, `arrived` counts it again
			// and the catch-up recursion runs to its page cap every 30 seconds
			const { dispatch } = mountList({ timeline: [status('1789553297940456473')] })
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).toHaveBeenCalledWith({ min_id: '1789553297940456473' })
		})

		it('polls with the highest id even when a newer-dated status has a lower one', async () => {
			// A federated post can carry a high id with an old created_at. The
			// display order sorts on created_at, so timeline[0] is not the
			// newest id — polling on it would return the same page forever.
			const highIdOldDate = { ...status('50'), created_at: '2026-01-01T10:00:00Z' }
			const { dispatch } = mountList({ timeline: [status('30'), highIdOldDate] })
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).toHaveBeenCalledWith({ min_id: '50' })
		})

		it('does not poll for ancestors', async () => {
			const { dispatch } = mountList({ parents: [status('5')], props: { showParents: true } })
			await flushPromises()
			dispatch.mockClear()

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
		})

		it('does not fetch, observe or poll at all for the ancestors list', async () => {
			// the single-post view mounts two lists over one /context response:
			// the ancestors list used to make its own identical request, keep
			// its own observer and run its own 30-second interval
			const { dispatch } = mountList({ parents: [status('5')], props: { showParents: true } })
			await flushPromises()

			expect(dispatch).not.toHaveBeenCalled()
			expect(FakeIntersectionObserver.instances).toHaveLength(0)
		})

		it('says a polling failure once, not once per tick', async () => {
			const { dispatch } = mountList({ responses: [[]] })
			await flushPromises()
			dispatch.mockReset()
			dispatch.mockRejectedValue(new Error('down'))

			for (let tick = 0; tick < 4; tick++) {
				vi.advanceTimersByTime(30 * 1000)
				await flushPromises()
			}

			// a server that is down produced an endless stream of toasts
			expect(dispatch.mock.calls.length).toBeGreaterThanOrEqual(4)
			expect(showError).toHaveBeenCalledTimes(1)
			expect(showError).toHaveBeenCalledWith('Could not load the newest posts')
		})

		it('says it again once the server has answered in between', async () => {
			const { dispatch } = mountList({ responses: [[]] })
			await flushPromises()
			dispatch.mockReset()

			dispatch.mockRejectedValueOnce(new Error('down'))
			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()
			expect(showError).toHaveBeenCalledTimes(1)

			dispatch.mockResolvedValueOnce([])
			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			dispatch.mockRejectedValueOnce(new Error('down again'))
			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()
			expect(showError).toHaveBeenCalledTimes(2)
		})

		it('stops catching up after ten pages, however the server answers', async () => {
			const { dispatch } = mountList({ responses: [[]] })
			await flushPromises()
			dispatch.mockReset()
			// a server that ignores min_id used to keep this recursing for as
			// long as the tab was open
			dispatch.mockResolvedValue([status('9')])

			vi.advanceTimersByTime(30 * 1000)
			await flushPromises()

			expect(dispatch).toHaveBeenCalledTimes(10)
		})

		it('stops polling and observing when unmounted', async () => {
			const { wrapper, dispatch } = mountList()
			await flushPromises()
			dispatch.mockClear()

			wrapper.unmount()
			vi.advanceTimersByTime(60 * 1000)
			await flushPromises()

			expect(observer().disconnect).toHaveBeenCalled()
			expect(dispatch).not.toHaveBeenCalled()
		})
	})

	describe('empty state', () => {
		it('is not shown while more statuses may still arrive', async () => {
			const { wrapper } = mountList({ responses: [[status('1')]] })
			await flushPromises()
			expect(wrapper.findComponent(EmptyContent).exists()).toBe(false)
		})

		it('is shown with the home illustration once the timeline turns out empty', async () => {
			const { wrapper } = mountList()
			await flushPromises()
			expect(wrapper.findComponent(EmptyContent).props('item')).toEqual({
				illustration: 'quiet-timeline',
				title: 'Your timeline is quiet',
				description: 'Posts from the people you follow will show up here. Find a few to get started.',
				action: {
					label: 'Find people to follow',
					to: { name: 'discover' },
				},
			})
		})

		// an empty home timeline has exactly one answer, and leaving the reader
		// to find Discover on their own is what this state used to do
		it('offers somewhere to go from an empty home timeline', async () => {
			const { wrapper } = mountList()
			await flushPromises()
			expect(wrapper.findComponent(EmptyContent).props('item').action.to).toEqual({ name: 'discover' })
		})

		/**
		 * An empty page that only states a fact leaves the reader to work out
		 * what to do about it. The direct timeline is the one with an obvious
		 * answer -- somebody to write to -- and it was the one with no button.
		 */
		it('offers somewhere to go from an empty direct timeline', async () => {
			const { wrapper } = mountList({ route: { name: 'timeline', params: { type: 'direct' } } })
			await flushPromises()

			const item = wrapper.findComponent(EmptyContent).props('item')
			expect(item.action.to).toEqual({ name: 'discover' })
			// a message in a bottle, drawn rather than a stock picture
			expect(item.scene).toBe('bottle')
			expect(item.image).toBeUndefined()
		})

		/**
		 * The pictures are drawn by the app. The stock files that used to be
		 * here were licensed for this project by permission and nothing else,
		 * and they are gone.
		 */
		it('draws every empty page with a scene or a small drawing, never a file', async () => {
			const { wrapper } = mountList()
			await flushPromises()

			for (const [type, item] of Object.entries(wrapper.vm.emptyContent)) {
				expect(item.image, type).toBeUndefined()
				if (item.scene !== undefined) {
					expect(SCENES, type).toContain(item.scene)
				}
			}
		})

		it.each([
			['direct', 'Nothing private yet'],
			['timeline', 'No local posts found'],
			['federated', 'No global posts found'],
			['notifications', 'No notifications found'],
			['favourites', 'No liked posts found'],
			['tags', 'No posts found for this tag'],
		])('describes an empty %s timeline', async (type, title) => {
			const { wrapper } = mountList({ route: { name: 'timeline', params: { type } } })
			await flushPromises()
			expect(emptyTitle(wrapper)).toBe(title)
		})

		/**
		 * Bookmarks, Photos and a list reach the list as a `type` and none of
		 * them as a route of that name, so the lookup fell through all three
		 * to the home timeline's words.
		 */
		it.each([
			['bookmarks', 'No bookmarks yet'],
			['photos', 'No photos found'],
			['list', 'Nothing in this list yet'],
		])('describes an empty %s page rather than the home timeline', async (type, title) => {
			const { wrapper } = mountList({
				props: { type },
				route: { name: type === 'list' ? 'list' : 'timeline', params: { type } },
			})
			await flushPromises()

			expect(emptyTitle(wrapper)).toBe(title)
		})

		it('names the list when the server has said what it is called', async () => {
			const { wrapper } = mountList({
				props: { type: 'list', listTitle: 'Colleagues' },
				route: { name: 'list', params: { id: '3' } },
			})
			await flushPromises()

			expect(emptyTitle(wrapper)).toBe('Nothing in Colleagues yet')
		})

		it('addresses the viewer on their own empty profile', async () => {
			const { wrapper } = mountList({ route: { name: 'profile', params: { account: 'alice' } } })
			await flushPromises()
			expect(emptyTitle(wrapper)).toBe('You have not tooted yet')
		})

		it('names the account on somebody else\'s empty profile', async () => {
			const { wrapper } = mountList({ route: { name: 'profile', params: { account: 'bob' } } })
			await flushPromises()
			expect(emptyTitle(wrapper)).toBe('bob hasn\'t tooted yet')
		})

		it('names the account on the public profile page even for the owner', async () => {
			const { wrapper } = mountList({
				route: { name: 'profile', params: { account: 'alice' } },
				serverData: { public: true, cloudAddress: 'https://cloud.example.org' },
			})
			await flushPromises()
			expect(emptyTitle(wrapper)).toBe('alice hasn\'t tooted yet')
		})

		/**
		 * A Photos or Videos tab with nothing in it is not an account that has
		 * never posted: they may have posted plenty, just none of this.
		 */
		it.each([
			['image', 'No photos yet'],
			['video', 'No videos yet'],
		])('says what the %s tab of a profile is missing', async (media, title) => {
			const { wrapper } = mountList({
				route: { name: 'profile', params: { account: 'bob' }, query: { media } },
			})
			await flushPromises()

			expect(emptyTitle(wrapper)).toBe(title)
		})

		/**
		 * A request that has not answered yet, so the moment between the switch
		 * and the new page — 150 ms against a real server — can be looked at.
		 */
		function pending() {
			let land
			const promise = new Promise((resolve) => {
				land = resolve
			})

			return { promise, land }
		}

		it('keeps the posts of the list being left until the new ones arrive', async () => {
			// switching My Feed → Local empties the list and fills it again
			// about 150 ms later; a skeleton in that gap is the content
			// blinking out and back, which is the flicker people report
			const next = pending()
			const { wrapper, store } = mountList({
				timeline: [status('1'), status('2')],
				responses: [[], next.promise],
			})
			await flushPromises()

			store.$patch({ ...showing('["timeline","",{}]'), timeline: [], statuses: {}, restored: false })
			await nextTick()
			await nextTick()

			expect(wrapper.findAll('.timeline-entry-stub').length).toBe(2)
			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(false)
			// and it is plainly not the list you asked for, nor clickable
			expect(wrapper.find('.timeline-list--settling').exists()).toBe(true)

			next.land([])
			await flushPromises()
		})

		it('lets go of them as soon as the new list has something', async () => {
			const next = pending()
			const { wrapper, store } = mountList({
				timeline: [status('1'), status('2')],
				responses: [[], next.promise],
			})
			await flushPromises()

			store.$patch({ ...showing('["timeline","",{}]'), timeline: [], statuses: {}, restored: false })
			await nextTick()
			store.$patch({ statuses: { 9: status('9') }, timeline: ['9'] })
			next.land([status('9')])
			await flushPromises()

			expect(wrapper.findAll('.timeline-entry-stub').length).toBe(1)
			expect(wrapper.find('.timeline-list--settling').exists()).toBe(false)
		})

		it('does not hold the posts over for a feed opened for the first time', async () => {
			// For you may well be empty for a new reader: the posts of My Feed
			// appearing and then vanishing into an empty state read as the
			// feed throwing its content away. A first visit shows its own
			// loading state instead.
			const next = pending()
			const { wrapper, store } = mountList({
				timeline: [status('1'), status('2')],
				responses: [[], next.promise],
			})
			await flushPromises()

			store.$patch({ ...showing('["interests","",{}]'), timeline: [], statuses: {}, restored: false })
			await nextTick()
			await nextTick()

			expect(wrapper.findAll('.timeline-entry-stub').length).toBe(0)
			expect(wrapper.find('.timeline-list--settling').exists()).toBe(false)
			expect(wrapper.findComponent(TimelineSkeleton).exists()).toBe(true)

			next.land([])
			await flushPromises()
		})

		it('holds them over for a feed that has shown a post before', async () => {
			const next = pending()
			const { wrapper, store } = mountList({
				timeline: [status('1'), status('2')],
				responses: [[], next.promise],
			})
			await flushPromises()
			store.markFilled('["interests","",{}]')

			store.$patch({ ...showing('["interests","",{}]'), timeline: [], statuses: {}, restored: false })
			await nextTick()
			await nextTick()

			expect(wrapper.findAll('.timeline-entry-stub').length).toBe(2)
			expect(wrapper.find('.timeline-list--settling').exists()).toBe(true)

			next.land([])
			await flushPromises()
		})

		it('draws the replacement as a swap: no leave transition, no stagger', async () => {
			// twenty old entries fading out on top of twenty new ones rising in
			// one after another is the slow, wide flash people see on a
			// Local → Global switch; the swap draws both in one go
			const next = pending()
			const { wrapper, store } = mountList({
				timeline: [status('1'), status('2')],
				responses: [[], next.promise],
			})
			await flushPromises()

			store.$patch({ ...showing('["timeline","",{}]'), timeline: [], statuses: {}, restored: false })
			await nextTick()
			store.$patch({ statuses: { 9: status('9') }, timeline: ['9'] })
			next.land([status('9')])
			await flushPromises()

			expect(wrapper.find('.timeline-list--swapping').exists()).toBe(true)
			expect(wrapper.findComponent(TimelineEntryStub).props('immediate')).toBe(true)

			// and a post removed later still fades on its own
			await new Promise((resolve) => setTimeout(resolve, 400))
			expect(wrapper.find('.timeline-list--swapping').exists()).toBe(false)
		})

		it('draws a list put back, or fetched ahead, as a swap', async () => {
			// switching to a scope the store already holds replaces the posts
			// in one render; rising them in one by one was the page blinking
			const { wrapper, store } = mountList({ timeline: [status('1'), status('2')] })
			await flushPromises()

			store.$patch({ ...showing('["federated","",{}]'), statuses: { 9: status('9') }, timeline: ['9'], restored: true })
			await nextTick()
			await nextTick()

			expect(wrapper.find('.timeline-list--swapping').exists()).toBe(true)
			expect(wrapper.findComponent(TimelineEntryStub).props('immediate')).toBe(true)
		})

		it('does not call a list that was simply there a swap', async () => {
			const { wrapper } = mountList({ timeline: [status('1')] })
			await flushPromises()

			expect(wrapper.find('.timeline-list--swapping').exists()).toBe(false)
			expect(wrapper.findComponent(TimelineEntryStub).props('immediate')).toBe(false)
		})

		it('shows an empty list rather than the old one once the answer is in', async () => {
			// My Feed really is empty for somebody who follows nobody, and
			// that is the answer they are owed
			const { wrapper, store } = mountList({
				timeline: [status('1')],
				responses: [[], []],
			})
			await flushPromises()

			store.$patch({ ...showing('["timeline","",{}]'), timeline: [], statuses: {}, restored: false })
			await flushPromises()

			expect(wrapper.findAll('.timeline-entry-stub').length).toBe(0)
			expect(wrapper.find('.timeline-list--settling').exists()).toBe(false)
		})

		it('draws bones for a list that never had anything', async () => {
			// a cold list has nothing to hold over, and a skeleton is then the
			// honest thing to show
			const { wrapper } = mountList({ timeline: [], responses: [[]] })
			await nextTick()

			expect(wrapper.find('.timeline-list--settling').exists()).toBe(false)
		})

		it('does not rewrite the shared empty-content entry for a public profile', async () => {
			// the computed used to assign into this.emptyContent, so the
			// rewritten title stayed behind for every later route
			const first = mountList({
				route: { name: 'profile', params: { account: 'alice' } },
				serverData: { public: true, cloudAddress: 'https://cloud.example.org' },
			})
			await flushPromises()
			expect(emptyTitle(first.wrapper)).toBe('alice hasn\'t tooted yet')

			const second = mountList({ route: { name: 'profile', params: { account: 'alice' } } })
			await flushPromises()
			expect(emptyTitle(second.wrapper)).toBe('You have not tooted yet')
		})

		it('says a thread has no replies rather than leaving a blank area', async () => {
			// /context answers with {ancestors, descendants}, whose `.length`
			// is undefined: allLoaded was never set, so nothing was said and
			// the sentinel kept asking for a page that does not exist
			const { wrapper, dispatch } = mountList({
				route: { name: 'single-post', params: { type: 'single-post', id: '1' } },
				responses: [{ ancestors: [], descendants: [] }],
			})
			await flushPromises()

			expect(emptyTitle(wrapper)).toBe('No replies yet')

			await intersect()
			expect(dispatch).toHaveBeenCalledTimes(1)
		})

		it('mentions missing replies below a single post but stays silent for its ancestors', async () => {
			const route = { name: 'single-post', params: { type: 'single-post', id: '1' } }

			const replies = mountList({ route })
			await flushPromises()
			expect(emptyTitle(replies.wrapper)).toBe('No replies yet')

			const parents = mountList({ route, props: { showParents: true } })
			await flushPromises()
			expect(parents.wrapper.findComponent(EmptyContent).exists()).toBe(false)
		})
	})

	describe('where the reader had got to on the home timeline', () => {
		const posts = [{ id: '30' }, { id: '20' }, { id: '10' }].map((post) => ({ ...post, account: { id: 'a' } }))
		const PLACE = 'social:seen:["home","",{}]'
		const lineBefore = (wrapper) => wrapper.find('.timeline-caughtup + .timeline-entry-stub').attributes('data-id')

		beforeEach(() => {
			window.localStorage.clear()
			vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'] })
		})

		it('draws the line where the server says the reader got to, so a phone and this page agree', async () => {
			const { wrapper, store } = mountList({
				timeline: posts,
				homeMarker: { id: '20', at: Date.parse('2026-10-05T09:00:00Z') },
			})
			await flushPromises()

			expect(store.fetchHomeMarker).toHaveBeenCalled()
			expect(lineBefore(wrapper)).toBe('20')
			expect(wrapper.find('.timeline-caughtup__label').text()).toMatch(/Up to date since/)
		})

		it('falls back to what this browser remembers when the server has no marker yet', async () => {
			window.localStorage.setItem(PLACE, JSON.stringify({ id: '20', at: 1 }))
			const { wrapper } = mountList({ timeline: posts })
			await flushPromises()

			expect(lineBefore(wrapper)).toBe('20')
		})

		it('takes whichever of the two is further along', async () => {
			window.localStorage.setItem(PLACE, JSON.stringify({ id: '10', at: 1 }))
			const serverAhead = mountList({ timeline: posts, homeMarker: { id: '20', at: 2 } })
			await flushPromises()
			expect(lineBefore(serverAhead.wrapper)).toBe('20')

			window.localStorage.setItem(PLACE, JSON.stringify({ id: '20', at: 1 }))
			const browserAhead = mountList({ timeline: posts, homeMarker: { id: '10', at: 2 } })
			await flushPromises()
			expect(lineBefore(browserAhead.wrapper)).toBe('20')
		})

		it('draws no line when nothing is new', async () => {
			const { wrapper } = mountList({ timeline: posts, homeMarker: { id: '30', at: 2 } })
			await flushPromises()

			expect(wrapper.find('.timeline-caughtup').exists()).toBe(false)
		})

		it('tells the server once the page has been looked at, not merely by rendering', async () => {
			// a tab opened in the background must not move the phone's line
			const { store } = mountList({ timeline: posts })
			await flushPromises()
			expect(store.markHomeRead).not.toHaveBeenCalled()

			await vi.advanceTimersByTimeAsync(2000)

			expect(store.markHomeRead).toHaveBeenCalledWith('30')
		})

		it('asks the server nothing on the other timelines', async () => {
			const { store } = mountList({
				timeline: posts,
				identity: '["timeline","",{}]',
				props: { type: 'timeline' },
				route: { name: 'timeline', params: { type: 'timeline' } },
			})
			await flushPromises()
			await vi.advanceTimersByTimeAsync(2000)

			expect(store.fetchHomeMarker).not.toHaveBeenCalled()
			expect(store.markHomeRead).not.toHaveBeenCalled()
		})
	})
})
