/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'
import { listen } from '@nextcloud/notify_push'
import Dashboard from '../../../src/views/Dashboard.vue'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

vi.mock('../../../src/services/toast.js', async (importOriginal) => ({
	...await importOriginal(),
	showError: vi.fn(),
}))

vi.mock('@nextcloud/notify_push', () => ({ listen: vi.fn() }))

/** The tile keeps this many rows; see MAX_ITEMS in the component. */
const KEPT = 10

/** Without notify_push the component polls on this interval. */
const POLL_MS = 60 * 1000

/** The longest the component waits between two failing asks; see MAX_BACKOFF_MS. */
const MAX_BACKOFF_MS = 16 * POLL_MS

const NcDashboardWidgetStub = {
	name: 'NcDashboardWidget',
	props: ['items', 'showMoreUrl', 'loading'],
	template: '<div class="widget-stub"><slot v-if="!loading && items.length === 0" name="empty-content" /></div>',
}

const bob = { acct: 'bob@remote.example', display_name: 'Bob', avatar: 'https://remote.example/media/bob.png' }
const carol = { acct: 'carol', display_name: 'Carol', avatar: 'https://cloud.example.org/avatar/carol/64' }
const notifications = [
	{ id: 'n3', type: 'follow', created_at: '2026-03-01T10:00:00Z', account: bob },
	{ id: 'n2', type: 'favourite', created_at: '2026-03-01T09:00:00Z', account: carol },
	{ id: 'n1', type: 'mention', created_at: '2026-03-01T08:00:00Z', account: carol },
]

let get

function mountWidget() {
	return mount(Dashboard, {
		props: { title: 'Aloha Social notifications' },
		global: { stubs: { NcDashboardWidget: NcDashboardWidgetStub } },
	})
}

describe('Dashboard', () => {
	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
		get = vi.spyOn(axios, 'get')
		// no notify_push unless a test says otherwise, so the component polls
		vi.mocked(listen).mockReturnValue(false)
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.useRealTimers()
		vi.mocked(showError).mockClear()
	})

	it('loads the notifications on mount and shows the widget as loading meanwhile', async () => {
		get.mockReturnValue(new Promise(() => {}))
		const wrapper = mountWidget()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/notifications')
		expect(wrapper.findComponent(NcDashboardWidgetStub).props('loading')).toBe(true)
		expect(wrapper.findComponent(NcDashboardWidgetStub).props('showMoreUrl')).toBe('/index.php/apps/social/timeline/notifications')
	})

	it('maps the notifications to widget items', async () => {
		get.mockResolvedValue({ data: notifications })
		const wrapper = mountWidget()
		await flushPromises()

		const widget = wrapper.findComponent(NcDashboardWidgetStub)
		expect(widget.props('loading')).toBe(false)
		expect(widget.props('items')).toEqual([
			{
				id: 'n3',
				targetUrl: '/index.php/apps/social/@bob@remote.example/',
				avatarUrl: 'https://remote.example/media/bob.png',
				avatarUsername: 'Bob',
				overlayIconUrl: '/index.php/svg/social/add_user',
				mainText: 'bob@remote.example started to follow you',
				subText: 'bob@remote.example',
			},
			{
				id: 'n2',
				targetUrl: '/index.php/apps/social/timeline/notifications',
				avatarUrl: 'https://cloud.example.org/avatar/carol/64',
				avatarUsername: 'Carol',
				overlayIconUrl: '',
				mainText: 'carol liked your post',
				subText: 'carol',
			},
			{
				id: 'n1',
				targetUrl: '/index.php/apps/social/timeline/notifications',
				avatarUrl: 'https://cloud.example.org/avatar/carol/64',
				avatarUsername: 'Carol',
				overlayIconUrl: '',
				mainText: 'carol mentioned you',
				subText: '',
			},
		])
	})

	it('merges only unseen notifications on later polls, deduplicated by id', async () => {
		get.mockResolvedValueOnce({ data: [notifications[2]] }) // [n1]
		const wrapper = mountWidget()
		await flushPromises()
		expect(wrapper.findComponent(NcDashboardWidgetStub).props('items').map((i) => i.id)).toEqual(['n1'])

		// the next poll returns a brand new notification plus the one we already have
		get.mockResolvedValueOnce({ data: [notifications[0], notifications[2]] }) // [n3, n1]
		vi.advanceTimersByTime(POLL_MS)
		await flushPromises()

		expect(wrapper.findComponent(NcDashboardWidgetStub).props('items').map((i) => i.id)).toEqual(['n3', 'n1'])
	})

	it('renders an empty state without items and keeps polling', async () => {
		get.mockResolvedValue({ data: [] })
		const wrapper = mountWidget()
		await flushPromises()
		expect(wrapper.findComponent(NcDashboardWidgetStub).props('items')).toEqual([])
		expect(wrapper.find('.empty-content').exists()).toBe(true)
		expect(showError).not.toHaveBeenCalled()

		vi.advanceTimersByTime(POLL_MS)
		expect(get).toHaveBeenCalledTimes(2)
	})

	it('polls once a minute when notify_push is unavailable', async () => {
		get.mockResolvedValue({ data: notifications })
		mountWidget()
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(1)

		vi.advanceTimersByTime(POLL_MS - 1)
		expect(get).toHaveBeenCalledTimes(1)
		vi.advanceTimersByTime(1)
		expect(get).toHaveBeenCalledTimes(2)
		await vi.advanceTimersByTimeAsync(POLL_MS * 2)
		expect(get).toHaveBeenCalledTimes(4)
	})

	it('lets the server say when something arrived instead of polling', async () => {
		get.mockResolvedValue({ data: notifications })
		let notify
		vi.mocked(listen).mockImplementation((channel, callback) => {
			notify = callback
			return () => {}
		})

		mountWidget()
		await flushPromises()
		expect(listen).toHaveBeenCalledWith('social_timeline', expect.any(Function))
		expect(get).toHaveBeenCalledTimes(1)

		// with push there is no timer at all
		vi.advanceTimersByTime(POLL_MS * 5)
		expect(get).toHaveBeenCalledTimes(1)

		notify()
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(2)
	})

	it('keeps only the newest rows so a tab left open cannot grow forever', async () => {
		const many = Array.from({ length: KEPT + 4 }, (_, i) => ({
			id: `m${i}`,
			type: 'favourite',
			created_at: '2026-03-01T09:00:00Z',
			account: carol,
		}))
		get.mockResolvedValue({ data: many })
		const wrapper = mountWidget()
		await flushPromises()

		expect(wrapper.findComponent(NcDashboardWidgetStub).props('items')).toHaveLength(KEPT)

		// a later poll brings four more; the oldest fall off rather than pile up
		get.mockResolvedValue({
			data: [{ id: 'new1', type: 'favourite', created_at: '2026-03-01T10:00:00Z', account: carol }, ...many],
		})
		vi.advanceTimersByTime(POLL_MS)
		await flushPromises()

		const items = wrapper.findComponent(NcDashboardWidgetStub).props('items')
		expect(items).toHaveLength(KEPT)
		expect(items[0].id).toBe('new1')
	})

	it('stops polling and unsubscribes when the widget goes away', async () => {
		const stop = vi.fn()
		vi.mocked(listen).mockReturnValue(stop)
		get.mockResolvedValue({ data: notifications })
		const wrapper = mountWidget()
		await flushPromises()

		wrapper.unmount()

		expect(stop).toHaveBeenCalled()
		vi.advanceTimersByTime(POLL_MS * 3)
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('reports a server error once and shows the error state', async () => {
		get.mockRejectedValue({ response: { status: 500 } })
		const wrapper = mountWidget()
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Failed to get Aloha Social notifications')
		const widget = wrapper.findComponent(NcDashboardWidgetStub)
		expect(widget.props('loading')).toBe(false)
		expect(widget.props('items')).toEqual([])
		expect(wrapper.find('.empty-content').exists()).toBe(true)

		// the asks after it keep failing, and a toast each time would be noise
		await vi.advanceTimersByTimeAsync(POLL_MS * 6)
		expect(get).toHaveBeenCalledTimes(3)
		expect(showError).toHaveBeenCalledTimes(1)
	})

	it('does not leave the widget loading after a network failure, and does not bother the user', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		get.mockRejectedValue(new Error('network down'))
		const wrapper = mountWidget()
		await flushPromises()

		expect(showError).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcDashboardWidgetStub).props('loading')).toBe(false)
		expect(wrapper.find('.empty-content').exists()).toBe(true)
	})

	/** One bad minute used to stop the polling until the dashboard was reloaded (#2338). */
	it('recovers on a later ask after a failure, without being remounted', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		get.mockRejectedValueOnce({ response: { status: 502 } })
		const wrapper = mountWidget()
		await flushPromises()
		expect(wrapper.find('.empty-content').exists()).toBe(true)

		get.mockResolvedValue({ data: notifications })
		await vi.advanceTimersByTimeAsync(POLL_MS * 2)

		expect(get).toHaveBeenCalledTimes(2)
		const widget = wrapper.findComponent(NcDashboardWidgetStub)
		expect(widget.props('items').map((i) => i.id)).toEqual(['n3', 'n2', 'n1'])
	})

	it('waits longer after each failure in a row, up to a ceiling', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		get.mockRejectedValue(new Error('network down'))
		mountWidget()
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(1)

		// two, four, eight, sixteen minutes, then sixteen again
		for (const wait of [2, 4, 8, 16, 16]) {
			await vi.advanceTimersByTimeAsync(wait * POLL_MS - 1)
			const before = get.mock.calls.length
			await vi.advanceTimersByTimeAsync(1)
			expect(get).toHaveBeenCalledTimes(before + 1)
		}

		// never a tight loop: an hour of failures is a handful of asks
		const before = get.mock.calls.length
		await vi.advanceTimersByTimeAsync(60 * POLL_MS)
		expect(get.mock.calls.length - before).toBeLessThanOrEqual(60 * POLL_MS / MAX_BACKOFF_MS)
	})

	it('goes back to asking once a minute after a success', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		get.mockRejectedValueOnce(new Error('network down'))
		get.mockRejectedValueOnce(new Error('network down'))
		get.mockResolvedValue({ data: notifications })
		mountWidget()
		await flushPromises()

		// two minutes, then four, then the ask that succeeds
		await vi.advanceTimersByTimeAsync(2 * POLL_MS)
		await vi.advanceTimersByTimeAsync(4 * POLL_MS)
		expect(get).toHaveBeenCalledTimes(3)

		await vi.advanceTimersByTimeAsync(POLL_MS)
		expect(get).toHaveBeenCalledTimes(4)
		await vi.advanceTimersByTimeAsync(POLL_MS)
		expect(get).toHaveBeenCalledTimes(5)
	})

	it('toasts again for a failure that follows a recovery', async () => {
		get.mockRejectedValueOnce({ response: { status: 503 } })
		get.mockResolvedValueOnce({ data: notifications })
		get.mockRejectedValueOnce({ response: { status: 503 } })
		mountWidget()
		await flushPromises()
		expect(showError).toHaveBeenCalledTimes(1)

		await vi.advanceTimersByTimeAsync(2 * POLL_MS)
		await vi.advanceTimersByTimeAsync(POLL_MS)
		expect(get).toHaveBeenCalledTimes(3)
		expect(showError).toHaveBeenCalledTimes(2)
	})

	it('clears a pending retry when the widget goes away', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		get.mockRejectedValue(new Error('network down'))
		const wrapper = mountWidget()
		await flushPromises()

		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(MAX_BACKOFF_MS * 3)
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('does not start polling from an answer that arrives after the widget went away', async () => {
		let answer
		get.mockReturnValueOnce(new Promise((resolve) => {
			answer = resolve
		}))
		const wrapper = mountWidget()
		wrapper.unmount()

		answer({ data: notifications })
		await flushPromises()
		await vi.advanceTimersByTimeAsync(POLL_MS * 3)
		expect(get).toHaveBeenCalledTimes(1)
	})
})
