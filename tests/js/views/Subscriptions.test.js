/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import Subscriptions from '../../../src/views/Subscriptions.vue'
import { showError } from '../../../src/services/toast.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
	showInfo: vi.fn(),
}))

const FEED = {
	id: '3',
	title: 'A channel',
	site_url: 'https://example.org/',
	kind: 'youtube',
	items: 12,
	error: '',
	read: true,
}

const ITEM = {
	id: '91',
	feed_id: '3',
	feed_title: 'A channel',
	title: 'The tide coming in',
	link: 'https://example.org/watch/abc',
	summary: 'What happens in it.',
	thumbnail: 'https://example.org/thumb.jpg',
	cursor: '2026-01-02 10:00:00',
	published: '2026-01-02T10:00:00Z',
}

function serve({ feeds = [FEED], items = [ITEM] } = {}) {
	axios.get.mockImplementation((url) => {
		if (url.includes('/subscriptions/timeline')) {
			return Promise.resolve({ data: { items } })
		}

		return Promise.resolve({ data: { feeds } })
	})
}

async function mountPage(options) {
	serve(options)
	const wrapper = mount(Subscriptions, { global: { stubs: { NcLoadingIcon: true } } })
	await flushPromises()

	return wrapper
}

describe('Subscriptions', () => {
	afterEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.delete.mockReset()
	})

	it('lists what the account follows and what came out of it', async () => {
		const wrapper = await mountPage()

		expect(wrapper.find('.feeds__name').text()).toBe('A channel')
		expect(wrapper.find('.feeds__meta').text()).toContain('12 entries')
		expect(wrapper.find('.entries__title').text()).toBe('The tide coming in')
	})

	/**
	 * Nothing here is a post: this server has no right to publish somebody
	 * else's video, so everything anybody does with an entry happens on the
	 * site it came from.
	 */
	it('sends every entry out to where it actually is', async () => {
		const wrapper = await mountPage()
		const link = wrapper.find('.entries__title')

		expect(link.attributes('href')).toBe('https://example.org/watch/abc')
		expect(link.attributes('rel')).toContain('noopener')
		expect(link.attributes('target')).toBe('_blank')
	})

	/**
	 * The thumbnail is a URL on the feed's own host (i.ytimg.com for YouTube),
	 * which Nextcloud's img-src refuses: an <img> for it is an empty box and a
	 * CSP violation, never a picture.
	 */
	it('does not load a video player until its preview is clicked', async () => {
		const video = {
			...ITEM,
			id: '92',
			link: 'https://www.youtube.com/shorts/AbCdEfGhI_1',
			video_id: 'AbCdEfGhI_1',
			thumbnail: 'https://i1.ytimg.com/vi/AbCdEfGhI_1/hqdefault.jpg',
		}
		const wrapper = await mountPage({ items: [video] })

		expect(wrapper.find('.entries__poster img').attributes('src')).toBe(video.thumbnail)
		expect(wrapper.find('iframe').exists()).toBe(false)

		await wrapper.find('.entries__poster').trigger('click')

		expect(wrapper.find('iframe').attributes('src')).toBe('https://www.youtube-nocookie.com/embed/AbCdEfGhI_1?autoplay=1')
		expect(wrapper.find('iframe').attributes('referrerpolicy')).toBe('strict-origin-when-cross-origin')
		expect(wrapper.find('.entries__source').attributes('href')).toBe(video.link)

		await wrapper.find('.entries__close').trigger('click')
		expect(wrapper.find('iframe').exists()).toBe(false)
	})

	it('does not render video thumbnails for ordinary feed links', async () => {
		const wrapper = await mountPage()

		expect(wrapper.find('.entries__title').exists()).toBe(true)
		expect(wrapper.find('img').exists()).toBe(false)
	})

	it('offers no way to boost, reply to or favourite one', async () => {
		const wrapper = await mountPage()

		expect(wrapper.text()).not.toContain('Boost')
		expect(wrapper.text()).not.toContain('Reply')
	})

	it('follows what was typed and reloads both lists', async () => {
		const wrapper = await mountPage({ feeds: [], items: [] })
		axios.post.mockResolvedValue({ data: { feed: FEED } })
		serve()

		wrapper.vm.url = 'UCXuqSBlHAE6Xw-yeJA0Tunw'
		await wrapper.find('form.subs__add').trigger('submit')
		await flushPromises()

		expect(axios.post.mock.calls[0][0]).toContain('/subscriptions')
		expect(axios.post.mock.calls[0][1]).toEqual({ url: 'UCXuqSBlHAE6Xw-yeJA0Tunw' })
		expect(wrapper.find('.feeds__name').text()).toBe('A channel')
	})

	/**
	 * An add, a remove and an import each hold `busy` for their own spinner
	 * while they reload, and the reload must not be turned away by it.
	 */
	it('lists the entries again after following something', async () => {
		const wrapper = await mountPage({ feeds: [], items: [] })
		axios.post.mockResolvedValue({ data: { feed: FEED } })
		serve()

		wrapper.vm.url = 'https://example.org/feed'
		await wrapper.vm.add()
		await flushPromises()

		expect(wrapper.vm.items).toHaveLength(1)
		expect(wrapper.find('.entries__title').text()).toBe('The tide coming in')
		expect(wrapper.text()).not.toContain('Nothing yet')
	})

	it('lists the entries again after unfollowing something', async () => {
		const wrapper = await mountPage({ feeds: [FEED, { ...FEED, id: '4', title: 'Another' }] })
		axios.delete.mockResolvedValue({ data: { unsubscribed: true } })

		await wrapper.vm.remove({ ...FEED, id: '4' })
		await flushPromises()

		expect(wrapper.find('.entries__title').text()).toBe('The tide coming in')
		expect(wrapper.vm.busy).toBe('')
	})

	it('lists the entries again after a Takeout import', async () => {
		const wrapper = await mountPage({ feeds: [], items: [] })
		axios.post.mockResolvedValue({ data: { subscribed: 1, already: 0, failed: {} } })
		serve()

		const input = wrapper.find('input[type="file"]')
		Object.defineProperty(input.element, 'files', {
			value: [new File(['Channel Id,Channel Url,Channel Title'], 'subscriptions.csv')],
			configurable: true,
		})
		await input.trigger('change')
		await flushPromises()

		expect(wrapper.find('.entries__title').text()).toBe('The tide coming in')
	})

	it('unfollows one by its id', async () => {
		const wrapper = await mountPage()
		axios.delete.mockResolvedValue({ data: { unsubscribed: true } })

		await wrapper.vm.remove(FEED)

		expect(axios.delete.mock.calls[0][0]).toContain('/subscriptions/3')
	})

	it('sends a Takeout export to the importer', async () => {
		const wrapper = await mountPage()
		axios.post.mockResolvedValue({ data: { subscribed: 14, already: 0, failed: {} } })

		const input = wrapper.find('input[type="file"]')
		Object.defineProperty(input.element, 'files', {
			value: [new File(['Channel Id,Channel Url,Channel Title'], 'subscriptions.csv')],
			configurable: true,
		})
		await input.trigger('change')
		await flushPromises()

		expect(axios.post.mock.calls[0][0]).toContain('/subscriptions/takeout')
	})

	it('explains when a Takeout file exceeds the upload limit', async () => {
		const wrapper = await mountPage()
		axios.post.mockRejectedValueOnce({
			response: { data: { error: 'that file is larger than 5 MB' } },
		})

		const input = wrapper.find('input[type="file"]')
		Object.defineProperty(input.element, 'files', {
			value: [new File(['subscriptions'], 'subscriptions.csv')],
			configurable: true,
		})
		await input.trigger('change')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('That file is larger than 5 MB')
	})

	/**
	 * Two feeds polled in the same minute give a dozen entries the same date to
	 * the second, so a cursor on the date either loops or steps over the rest.
	 */
	it('pages on publication date and row id', async () => {
		const wrapper = await mountPage()
		axios.get.mockClear()
		serve({ items: [] })

		await wrapper.vm.load()

		const [, options] = axios.get.mock.calls.at(-1)
		expect(options.params.before).toBe('2026-01-02 10:00:00')
		expect(options.params.before_id).toBe('91')
	})

	it('stops asking once a page comes back empty', async () => {
		const wrapper = await mountPage({ items: [] })
		axios.get.mockClear()

		await wrapper.vm.load()

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('says so when a feed has stopped answering', async () => {
		const wrapper = await mountPage({
			feeds: [{ ...FEED, error: 'could not connect' }],
		})

		expect(wrapper.find('.feeds__error').text()).toBe('could not connect')
	})

	/**
	 * "0 entries" against a feed that answered perfectly well reads as a
	 * failure nobody reported. It is worth saying which of the two it is.
	 */
	it('separates a feed that holds nothing from one not read yet', async () => {
		const empty = await mountPage({ feeds: [{ ...FEED, items: 0 }] })
		expect(empty.find('.feeds__quiet').text()).toBe('Read fine, but it lists nothing')

		const unread = await mountPage({ feeds: [{ ...FEED, items: 0, read: false }] })
		expect(unread.find('.feeds__quiet').text()).toBe('Not read yet')

		const held = await mountPage({ feeds: [FEED] })
		expect(held.find('.feeds__quiet').exists()).toBe(false)
	})

	/**
	 * A 500 or a dropped connection is not an empty list, and "Nothing yet"
	 * over it tells the reader their subscriptions are gone.
	 */
	it('says the entries could not be loaded, and asks again on request', async () => {
		axios.get.mockImplementation((url) => url.includes('/subscriptions/timeline')
			? Promise.reject(new Error('500'))
			: Promise.resolve({ data: { feeds: [FEED] } }))
		const wrapper = mount(Subscriptions, { global: { stubs: { NcLoadingIcon: true } } })
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('could not be loaded')
		expect(wrapper.text()).not.toContain('Nothing yet')

		serve()
		await wrapper.find('[role="alert"] button').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(wrapper.find('.entries__title').text()).toBe('The tide coming in')
	})

	it('says so when the list of subscriptions cannot be fetched', async () => {
		axios.get.mockImplementation((url) => url.includes('/subscriptions/timeline')
			? Promise.resolve({ data: { items: [] } })
			: Promise.reject(new Error('network')))
		const wrapper = mount(Subscriptions, { global: { stubs: { NcLoadingIcon: true } } })
		await flushPromises()

		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
		expect(wrapper.text()).not.toContain('Nothing yet')
	})

	it('retries the feed list instead of skipping to an older page', async () => {
		axios.get.mockImplementation((url) => url.includes('/subscriptions/timeline')
			? Promise.resolve({ data: { items: [ITEM] } })
			: Promise.reject(new Error('network')))
		const wrapper = mount(Subscriptions, { global: { stubs: { NcLoadingIcon: true } } })
		await flushPromises()

		serve()
		await wrapper.find('[role="alert"] button').trigger('click')
		await flushPromises()

		const feedRequests = axios.get.mock.calls.filter(([url]) => !url.includes('/subscriptions/timeline'))
		const timelineRequests = axios.get.mock.calls.filter(([url]) => url.includes('/subscriptions/timeline'))
		expect(feedRequests).toHaveLength(2)
		expect(timelineRequests).toHaveLength(2)
		expect(timelineRequests[1][1].params).toEqual({ limit: 40 })
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('keeps what is listed when an older page fails', async () => {
		const wrapper = await mountPage()
		axios.get.mockRejectedValue(new Error('500'))

		await wrapper.vm.load()
		await flushPromises()

		expect(wrapper.find('.entries__title').text()).toBe('The tide coming in')
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
	})

	it('says what the page is for when nothing is followed yet', async () => {
		const wrapper = await mountPage({ feeds: [], items: [] })

		expect(wrapper.text()).toContain('Nothing yet')
	})
})
