/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import VideoBrowse, { columnsFor, FILL_TARGET, MAX_FILL_PAGES } from '../../../src/components/VideoBrowse.vue'
import { loadUnfinished } from '../../../src/services/videoProgress.js'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

vi.mock('../../../src/services/videoProgress.js', async (original) => ({
	...(await original()),
	loadUnfinished: vi.fn(async () => []),
}))

/** a link the keyboard can reach, as the router's own is */
const RouterLinkStub = { name: 'RouterLink', props: ['to'], template: '<a href="#"><slot /></a>' }

const UploadStub = { name: 'MediaUploadButton', template: '<button class="upload-stub" />' }

/**
 * @param {string} id the post's id
 * @param {object} [card] what its video card says
 * @return {object} a video post
 */
function video(id, card = {}) {
	return {
		id,
		account: { id: 'a' + id, acct: 'films', username: 'films', display_name: 'Films' },
		created_at: '2026-10-15T12:00:00.000Z',
		media_attachments: [],
		video_card: { title: 'Video ' + id, duration: 60, thumbnail: null, views: null, live: false, category: null, published: '2026-10-15T12:00:00.000Z', ...card },
	}
}

/** @param {number} count how many @return {object[]} that many videos */
const videos = (count) => Array.from({ length: count }, (unused, index) => video(String(index + 1)))

let timelineStore

/**
 * @param {object} props the component's props
 * @param {object} [options] how the page is set up
 * @param {string} [options.scope] which circle of people
 * @param {boolean} [options.public] read without a session
 * @param {object} [options.shelves] what each shelf's request answers, by type
 * @return {object} the mounted page
 */
function mountBrowse(props = {}, { scope = 'home', public: isPublic = false, shelves = {} } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: isPublic })
	timelineStore = useTimelineStore()
	timelineStore.changeTimelineType({ type: 'videos', params: { scope } })
	vi.spyOn(timelineStore, 'fetchSideList').mockImplementation(({ type }) => Promise.resolve(shelves[type] ?? []))

	return mount(VideoBrowse, {
		props: { posts: [], ...props },
		attachTo: document.body,
		global: {
			plugins: [pinia],
			stubs: { RouterLink: RouterLinkStub, ActorAvatar: true, MediaUploadButton: UploadStub },
		},
	})
}

const chipNames = (wrapper) => wrapper.findAll('.video-browse__chip').map((chip) => chip.text())
const cardTitles = (wrapper) => wrapper.findAll('.video-browse__grid .video-card__title').map((title) => title.text())
const chip = (wrapper, name) => wrapper.findAll('.video-browse__chip').find((one) => one.text() === name)

describe('the Videos page', () => {
	let wrapper

	beforeEach(() => {
		vi.clearAllMocks()
	})

	afterEach(() => {
		wrapper?.unmount()
		vi.mocked(loadUnfinished).mockResolvedValue([])
	})

	it('fits one card to a row on a phone, two on a tablet, three or four on a desk', () => {
		expect(columnsFor(375)).toBe(1)
		expect(columnsFor(700)).toBe(2)
		expect(columnsFor(1000)).toBe(3)
		expect(columnsFor(1400)).toBe(4)
	})

	describe('the chips', () => {
		it('offers All, the categories and Recently uploaded, and Live only when something is', async () => {
			wrapper = mountBrowse({ posts: [video('1', { category: 'Music' }), video('2')] })
			expect(chipNames(wrapper)).toEqual(['All', 'Music', 'Recently uploaded'])

			await wrapper.setProps({ posts: [video('1', { category: 'Music' }), video('2', { live: true })] })
			expect(chipNames(wrapper)).toEqual(['All', 'Live', 'Music', 'Recently uploaded'])
		})

		it('narrows the grid to what a chip keeps, and says which is chosen', async () => {
			wrapper = mountBrowse({ posts: [video('1', { category: 'Music' }), video('2', { live: true }), video('3', { category: 'Music' })], allLoaded: true })
			expect(cardTitles(wrapper)).toEqual(['Video 1', 'Video 2', 'Video 3'])

			await chip(wrapper, 'Music').trigger('click')
			expect(cardTitles(wrapper)).toEqual(['Video 1', 'Video 3'])
			expect(chip(wrapper, 'Music').attributes('aria-pressed')).toBe('true')
			expect(chip(wrapper, 'All').attributes('aria-pressed')).toBe('false')

			await chip(wrapper, 'Live').trigger('click')
			expect(cardTitles(wrapper)).toEqual(['Video 2'])
		})

		it('goes back to All when the chosen chip has nothing left', async () => {
			wrapper = mountBrowse({ posts: [video('1', { live: true })], allLoaded: true })
			await chip(wrapper, 'Live').trigger('click')
			await wrapper.setProps({ posts: [] })

			expect(chip(wrapper, 'All').attributes('aria-pressed')).toBe('true')
		})

		it('asks for further pages while a chip leaves the grid short, and no more than a few', async () => {
			wrapper = mountBrowse({ posts: [video('1', { category: 'Music' }), ...videos(3)] })
			await chip(wrapper, 'Music').trigger('click')
			expect(wrapper.emitted('more')).toHaveLength(1)

			// each page that arrives without enough asks for the next
			for (let page = 1; page < MAX_FILL_PAGES + 3; page++) {
				await wrapper.setProps({ loading: true })
				await wrapper.setProps({ loading: false })
			}
			expect(wrapper.emitted('more')).toHaveLength(MAX_FILL_PAGES)

			// then the reader is asked
			const further = wrapper.findAll('button').find((button) => button.text() === 'Look further')
			expect(further).toBeDefined()
			await further.trigger('click')
			expect(wrapper.emitted('more')).toHaveLength(MAX_FILL_PAGES + 1)
		})

		it('asks for nothing under All, when the chip is full, or at the end', async () => {
			wrapper = mountBrowse({ posts: [video('1', { category: 'Music' })], allLoaded: true })
			await chip(wrapper, 'Music').trigger('click')
			expect(wrapper.emitted('more')).toBeUndefined()
			wrapper.unmount()

			wrapper = mountBrowse({ posts: Array.from({ length: FILL_TARGET }, (unused, index) => video(String(index), { category: 'Music' })) })
			await chip(wrapper, 'Music').trigger('click')
			await chip(wrapper, 'All').trigger('click')
			expect(wrapper.emitted('more')).toBeUndefined()
		})

		it('says so when a chip keeps nothing and there is nothing more to look through', async () => {
			wrapper = mountBrowse({ posts: [{ ...video('1'), created_at: '2020-01-01T00:00:00.000Z' }], allLoaded: true })
			await chip(wrapper, 'Recently uploaded').trigger('click')

			expect(cardTitles(wrapper)).toEqual([])
			expect(wrapper.text()).toContain('No videos of this kind in what has been loaded.')
		})

		it('asks for nothing after a page failed', async () => {
			wrapper = mountBrowse({ posts: [video('1', { category: 'Music' })], failed: true })
			await chip(wrapper, 'Music').trigger('click')
			expect(wrapper.emitted('more')).toBeUndefined()
		})
	})

	describe('the shelves', () => {
		it('asks for the Shorts and the followed channels once, beside the timeline', async () => {
			wrapper = mountBrowse({ posts: videos(2) }, { scope: 'federated' })
			await flushPromises()
			await wrapper.setProps({ posts: videos(6) })
			await flushPromises()

			expect(timelineStore.fetchSideList).toHaveBeenCalledTimes(2)
			expect(timelineStore.fetchSideList).toHaveBeenCalledWith({ type: 'shorts', params: { scope: 'federated' } }, 12)
			expect(timelineStore.fetchSideList).toHaveBeenCalledWith({ type: 'videos', params: { scope: 'home' } }, 12)
		})

		it('does not ask for the followed channels on My Feed, which is them, or without a session', async () => {
			wrapper = mountBrowse({}, { scope: 'home' })
			await flushPromises()
			expect(timelineStore.fetchSideList).toHaveBeenCalledTimes(1)
			wrapper.unmount()

			wrapper = mountBrowse({}, { scope: 'timeline', public: true })
			await flushPromises()
			expect(timelineStore.fetchSideList).toHaveBeenCalledTimes(1)
			expect(timelineStore.fetchSideList).toHaveBeenCalledWith({ type: 'shorts', params: { scope: 'timeline' } }, 12)
		})

		it('stands the Shorts after two rows, each opening the Shorts player at it', async () => {
			wrapper = mountBrowse({ posts: videos(10) }, { scope: 'federated', shelves: { shorts: [video('s1')] } })
			await flushPromises()

			const grids = wrapper.findAll('.video-browse__grid')
			expect(grids).toHaveLength(2)
			expect(grids[0].findAll('li')).toHaveLength(8)
			expect(grids[1].findAll('li')).toHaveLength(2)

			const shelf = wrapper.find('.video-browse__shelf--short')
			expect(shelf.find('h2').text()).toBe('Shorts')
			expect(shelf.findComponent({ name: 'RouterLink' }).props('to')).toEqual({ name: 'shorts', query: { scope: 'federated', start: 's1' } })
			// the shelf comes between the two runs of cards
			expect(grids[0].element.compareDocumentPosition(shelf.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
			expect(shelf.element.compareDocumentPosition(grids[1].element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
		})

		it('shows From channels you follow away from My Feed', async () => {
			wrapper = mountBrowse({ posts: videos(2) }, { scope: 'federated', shelves: { videos: [video('f1'), { id: 'text', video_card: null }] } })
			await flushPromises()

			const titles = wrapper.findAll('.video-browse__shelf h2').map((title) => title.text())
			expect(titles).toEqual(['Continue watching', 'From channels you follow'])
			expect(wrapper.findAll('.video-browse__shelf .video-card')).toHaveLength(1)
		})

		it('shows Continue watching only when the reader is in the middle of something', async () => {
			vi.mocked(loadUnfinished).mockResolvedValue([video('c1'), { id: 'text', video_card: null }])
			wrapper = mountBrowse({ posts: videos(3) })
			await flushPromises()
			const shelf = wrapper.findAll('.video-browse__shelf').find((one) => one.find('h2').text() === 'Continue watching')
			expect(shelf.findAll('.video-card__title').map((title) => title.text())).toEqual(['Video c1'])

			// once per page view, whatever else changes
			await wrapper.setProps({ posts: videos(5) })
			await flushPromises()
			expect(loadUnfinished).toHaveBeenCalledTimes(1)
		})

		it('says that nothing is waiting rather than hiding the shelf', async () => {
			wrapper = mountBrowse({ posts: videos(3) })
			await flushPromises()

			const shelf = wrapper.findAll('.video-browse__shelf').find((one) => one.find('h2').text() === 'Continue watching')
			expect(shelf.find('.video-browse__shelf-note').text())
				.toContain('Videos you start watching show up here')
			expect(shelf.find('.video-card').exists()).toBe(false)
		})

		it('says that it could not read the list, and asks again when told to', async () => {
			vi.mocked(loadUnfinished).mockRejectedValueOnce(new Error('down'))
			wrapper = mountBrowse({ posts: videos(3) })
			await flushPromises()

			const shelf = () => wrapper.findAll('.video-browse__shelf').find((one) => one.find('h2').text() === 'Continue watching')
			expect(shelf().find('.video-browse__shelf-note').text())
				.toContain('Could not read what you were watching')

			vi.mocked(loadUnfinished).mockResolvedValue([video('c1')])
			await shelf().findComponent({ name: 'NcButton' }).trigger('click')
			await flushPromises()

			expect(loadUnfinished).toHaveBeenCalledTimes(2)
			expect(shelf().findAll('.video-card__title').map((title) => title.text())).toEqual(['Video c1'])
		})

		it('says that the list is still loading', async () => {
			vi.mocked(loadUnfinished).mockReturnValue(new Promise(() => {}))
			wrapper = mountBrowse({ posts: videos(3) })

			const shelf = wrapper.findAll('.video-browse__shelf').find((one) => one.find('h2').text() === 'Continue watching')
			expect(shelf.find('.video-browse__shelf-note').text()).toContain('Loading')
		})

		it('asks nobody without a session what they were watching', async () => {
			wrapper = mountBrowse({}, { public: true })
			await flushPromises()
			expect(loadUnfinished).not.toHaveBeenCalled()
			expect(wrapper.text()).not.toContain('Continue watching')
		})

		it('leaves the shelves out under a chip', async () => {
			wrapper = mountBrowse({ posts: [video('1', { category: 'Music' })], allLoaded: true }, { scope: 'federated', shelves: { shorts: [video('s1')] } })
			await flushPromises()
			expect(wrapper.find('.video-browse__shelf').exists()).toBe(true)

			await chip(wrapper, 'Music').trigger('click')
			expect(wrapper.find('.video-browse__shelf').exists()).toBe(false)
		})
	})

	it('offers Upload a video to somebody signed in, and only to them', () => {
		wrapper = mountBrowse()
		expect(wrapper.find('.upload-stub').exists()).toBe(true)
		wrapper.unmount()

		wrapper = mountBrowse({}, { public: true })
		expect(wrapper.find('.upload-stub').exists()).toBe(false)
	})

	it('draws placeholder cards while the first page is on its way', () => {
		wrapper = mountBrowse({ loading: true })
		expect(wrapper.find('.video-browse__bone').exists()).toBe(true)
	})

	describe('the keyboard', () => {
		const link = (index) => wrapper.find(`[data-card-index="${index}"] .video-card__link`)

		it('moves along a row with the arrows, and a row at a time up and down', async () => {
			wrapper = mountBrowse({ posts: videos(10), allLoaded: true })

			link(0).element.focus()
			await link(0).trigger('keydown', { key: 'ArrowRight' })
			expect(document.activeElement).toBe(link(1).element)

			await link(1).trigger('keydown', { key: 'ArrowDown' })
			expect(document.activeElement).toBe(link(5).element)

			await link(5).trigger('keydown', { key: 'End' })
			expect(document.activeElement).toBe(link(9).element)

			await link(9).trigger('keydown', { key: 'Home' })
			expect(document.activeElement).toBe(link(0).element)

			// nothing before the first
			await link(0).trigger('keydown', { key: 'ArrowLeft' })
			expect(document.activeElement).toBe(link(0).element)
		})

		it('swaps left and right in a right-to-left language', async () => {
			wrapper = mountBrowse({ posts: videos(3), allLoaded: true })
			wrapper.element.style.direction = 'rtl'

			link(1).element.focus()
			await link(1).trigger('keydown', { key: 'ArrowLeft' })
			expect(document.activeElement).toBe(link(2).element)
		})
	})
})
