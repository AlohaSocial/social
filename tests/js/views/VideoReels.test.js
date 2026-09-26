/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import VideoReels from '../../../src/views/VideoReels.vue'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))
const { feel } = vi.hoisted(() => ({ feel: vi.fn() }))
vi.mock('../../../src/services/senses.js', () => ({ feel }))

/** The observers each mount made, so a test can fire one by hand. */
let observers = []

function video(id, { attachments, content = '<p>a clip</p>', acct = 'alice@cloud.example' } = {}) {
	return {
		id,
		content,
		created_at: '2026-01-0' + id + 'T10:00:00Z',
		account: { acct, username: 'alice', display_name: 'Alice', avatar: 'https://cloud.example/a.png' },
		media_attachments: attachments ?? [
			{ id: id + '-1', type: 'video', url: 'https://cloud.example/v' + id + '.mp4', preview_url: '', description: '' },
		],
	}
}

async function mountReels(statuses = [video('1'), video('2')]) {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useTimelineStore()
	store.fetchTimeline = vi.fn(async () => {
		// the first call fills the list, every later one says there is no more
		if (store.timeline.length === 0) {
			store.addToTimeline(statuses)

			return statuses
		}

		return []
	})

	const wrapper = mount(VideoReels, {
		global: { plugins: [pinia], stubs: { NcButton: true, RouterLink: true } },
	})
	await flushPromises()

	return { wrapper, store }
}

describe('VideoReels', () => {
	beforeEach(() => {
		observers = []
		vi.stubGlobal('IntersectionObserver', class {
			constructor(callback) {
				this.callback = callback
				this.observed = []
				observers.push(this)
			}

			observe(el) {
				this.observed.push(el)
			}

			disconnect() {}
		})
		// jsdom has no media element; every reel calls these
		HTMLMediaElement.prototype.play = vi.fn(() => Promise.resolve())
		HTMLMediaElement.prototype.pause = vi.fn()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('asks for the videos timeline at the scope it was given', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const store = useTimelineStore()
		store.fetchTimeline = vi.fn(async () => [])

		mount(VideoReels, {
			props: { scope: 'federated' },
			global: { plugins: [pinia], stubs: { NcButton: true, RouterLink: true } },
		})
		await flushPromises()

		expect(store.type).toBe('videos')
		expect(store.params.scope).toBe('federated')
	})

	/**
	 * A post with three videos on it is three things to watch, and a stack
	 * that showed only the first would hide two of them.
	 */
	it('makes one slide per video, not per post', async () => {
		const { wrapper } = await mountReels([
			video('1', {
				attachments: [
					{ id: 'a', type: 'video', url: 'https://cloud.example/a.mp4' },
					{ id: 'b', type: 'video', url: 'https://cloud.example/b.mp4' },
				],
			}),
		])

		expect(wrapper.findAll('.reel')).toHaveLength(2)
	})

	it('keys each slide by its video, so a post with two keeps two', async () => {
		const { wrapper } = await mountReels([
			video('1', {
				attachments: [
					{ id: 'a', type: 'video', url: 'https://cloud.example/a.mp4' },
					{ id: 'b', type: 'video', url: 'https://cloud.example/b.mp4' },
				],
			}),
		])

		// the key Vue diffs the slides by, as it sits on each rendered <article>
		const keys = wrapper.findAll('article.reel').map((slide) => slide.element.__vnode.key)
		expect(keys).toEqual(['1:a', '1:b'])
	})

	it('leaves the pictures on a mixed post out of the stack', async () => {
		const { wrapper } = await mountReels([
			video('1', {
				attachments: [
					{ id: 'a', type: 'image', url: 'https://cloud.example/a.jpg' },
					{ id: 'b', type: 'video', url: 'https://cloud.example/b.mp4' },
				],
			}),
		])

		expect(wrapper.findAll('.reel')).toHaveLength(1)
		expect(wrapper.find('video').attributes('src')).toBe('https://cloud.example/b.mp4')
	})

	/** No browser autoplays with sound, and a page that made noise on open is one people close. */
	it('starts muted', async () => {
		const { wrapper } = await mountReels()

		expect(wrapper.vm.muted).toBe(true)
		expect(wrapper.find('video').element.muted).toBe(true)
	})

	/**
	 * A dozen videos playing behind the one on screen is a phone getting hot
	 * for nothing.
	 */
	it('plays the slide that is on screen and pauses every other one', async () => {
		const { wrapper } = await mountReels()
		const videos = wrapper.findAll('video').map((one) => one.element)

		wrapper.vm.play(1)

		expect(videos[1].play).toHaveBeenCalled()
		expect(videos[0].pause).toHaveBeenCalled()
	})

	it('rewinds a video it scrolled past, so coming back is the video again', async () => {
		const { wrapper } = await mountReels()
		const first = wrapper.findAll('video')[0].element
		Object.defineProperty(first, 'currentTime', { value: 12, writable: true })

		wrapper.vm.play(1)

		expect(first.currentTime).toBe(0)
	})

	/** The sound is a statement about the page, not about one video. */
	it('unmutes the whole stack at once', async () => {
		const { wrapper } = await mountReels()

		await wrapper.vm.toggleSound()

		expect(wrapper.vm.muted).toBe(false)
		expect(wrapper.findAll('video')[0].element.muted).toBe(false)
	})

	/** A stack is reachable from a keyboard or it is reachable by nobody using one. */
	it('moves with the arrow keys', async () => {
		const { wrapper } = await mountReels()
		const scrolled = []
		wrapper.vm.slides.forEach((slide, at) => {
			slide.scrollIntoView = () => scrolled.push(at)
		})

		await wrapper.find('.reels__track').trigger('keydown', { key: 'ArrowDown' })

		expect(scrolled).toEqual([1])
	})

	it('moves without the smooth scroll for a reader who asked for less motion', async () => {
		vi.stubGlobal('matchMedia', (query) => ({ matches: query.includes('reduce') }))
		const { wrapper } = await mountReels()
		const behaviours = []
		wrapper.vm.slides.forEach((slide) => {
			slide.scrollIntoView = (options) => behaviours.push(options.behavior)
		})

		await wrapper.find('.reels__track').trigger('keydown', { key: 'ArrowDown' })

		expect(behaviours).toEqual(['auto'])
	})

	it('is announced as a region under its name', async () => {
		const { wrapper } = await mountReels()

		expect(wrapper.find('.reels').attributes('role')).toBe('region')
		expect(wrapper.find('.reels').attributes('aria-label')).toBe('Videos, one at a time')
	})

	/**
	 * The router keeps this view when only `?scope=` changes, so the prop
	 * changes under a mounted stack.
	 */
	it('switches the feed when the scope changes', async () => {
		const { wrapper, store } = await mountReels()

		await wrapper.setProps({ scope: 'federated' })
		await flushPromises()

		expect(store.params.scope).toBe('federated')
		expect(store.fetchTimeline).toHaveBeenCalledTimes(2)
	})

	it('pauses and resumes with the space bar', async () => {
		const { wrapper } = await mountReels()
		const first = wrapper.findAll('video')[0].element
		Object.defineProperty(first, 'paused', { value: false, configurable: true })

		await wrapper.find('.reels__track').trigger('keydown', { key: ' ' })

		expect(first.pause).toHaveBeenCalled()
	})

	/**
	 * The next page is asked for before the reader reaches the end, or the
	 * stack stops dead while it loads.
	 */
	it('asks for more before the end of what it holds', async () => {
		const { wrapper, store } = await mountReels()
		store.fetchTimeline.mockClear()

		wrapper.vm.play(wrapper.vm.reels.length - 1)
		await flushPromises()

		expect(store.fetchTimeline).toHaveBeenCalled()
	})

	/** A cursor rounded through a Number skips rows or loops on one. */
	it('pages on the id as a string', async () => {
		const { wrapper, store } = await mountReels([
			video('114500000000000001'),
			video('114500000000000002'),
		])
		store.fetchTimeline.mockClear()

		await wrapper.vm.load()

		expect(store.fetchTimeline).toHaveBeenCalledWith({ max_id: '114500000000000001' })
	})

	it('stops asking once a page comes back empty', async () => {
		const { wrapper, store } = await mountReels()

		await wrapper.vm.load()
		store.fetchTimeline.mockClear()
		await wrapper.vm.load()

		expect(store.fetchTimeline).not.toHaveBeenCalled()
	})

	/** Leaving the page must not leave a video playing behind it. */
	it('pauses everything when it goes away', async () => {
		const { wrapper } = await mountReels()
		const videos = wrapper.findAll('video').map((one) => one.element)

		wrapper.unmount()

		expect(videos[0].pause).toHaveBeenCalled()
	})

	/**
	 * The single-post route is `/@:account/:id`: a link that names only the
	 * id throws while it renders, and Vue drops the component whose render
	 * threw, so the link was never on the page at all.
	 */
	it('links each slide to its post, by account and id', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const store = useTimelineStore()
		const statuses = [video('1', { acct: 'bob@remote.example' })]
		store.fetchTimeline = vi.fn(async () => {
			store.addToTimeline(statuses)

			return []
		})
		const empty = { render: () => null }
		const router = createRouter({
			history: createMemoryHistory('/index.php/apps/social'),
			routes: [
				{ path: '/', component: empty },
				{ path: '/timeline/:type?', name: 'timeline', component: empty },
				{ path: '/@:account', name: 'profile', component: empty },
				{ path: '/@:account/:id', name: 'single-post', component: empty },
			],
		})
		await router.push('/')

		const wrapper = mount(VideoReels, {
			global: { plugins: [pinia, router], stubs: { NcButton: true } },
		})
		await flushPromises()

		expect(wrapper.find('a.reel__open').attributes('href')).toBe('/index.php/apps/social/@bob@remote.example/1')
	})

	it('says it is loading while the first page is on its way', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const store = useTimelineStore()
		store.fetchTimeline = vi.fn(() => new Promise(() => {}))

		const wrapper = mount(VideoReels, {
			global: { plugins: [pinia], stubs: { NcButton: true, RouterLink: true } },
		})
		await flushPromises()

		expect(wrapper.text()).toContain('Loading videos')
		expect(wrapper.text()).not.toContain('No videos here yet.')
	})

	/**
	 * A failed request is not an empty feed, and "No videos here yet" over a
	 * 500 tells the reader something that is not true.
	 */
	it('says the videos could not be loaded, and asks again on request', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const store = useTimelineStore()
		store.fetchTimeline = vi.fn().mockRejectedValueOnce(new Error('500'))

		const wrapper = mount(VideoReels, {
			global: { plugins: [pinia], stubs: { RouterLink: true } },
		})
		await flushPromises()

		const alert = wrapper.find('[role="alert"]')
		expect(alert.text()).toContain('could not be loaded')
		expect(wrapper.text()).not.toContain('No videos here yet.')

		store.fetchTimeline.mockImplementationOnce(async () => {
			store.addToTimeline([video('1')])

			return [video('1')]
		})
		await alert.find('button').trigger('click')
		await flushPromises()

		expect(store.fetchTimeline).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(wrapper.findAll('.reel')).toHaveLength(1)
	})

	it('says so when there is nothing to watch', async () => {
		const { wrapper } = await mountReels([])

		expect(wrapper.text()).toContain('No videos here yet.')
	})

	describe('hearts', () => {
		const likeable = (store) => {
			store.postLike = vi.fn(async ({ status }) => {
				store.likeStatus({ status })
				return {}
			})
			store.postUnlike = vi.fn(async () => ({}))
		}

		it('likes from the heart button and sends hearts up the edge', async () => {
			const { wrapper, store } = await mountReels()
			likeable(store)
			feel.mockClear()

			const button = wrapper.findAll('.reel__like')[0]
			expect(button.attributes('aria-pressed')).toBe('false')
			await button.trigger('click')
			await flushPromises()

			expect(store.postLike).toHaveBeenCalledWith({ status: expect.objectContaining({ id: wrapper.vm.reels[0].status.id }) })
			expect(wrapper.findAll('.reel')[0].findAll('.reel__heart--float')).toHaveLength(3)
			expect(wrapper.findAll('.reel__like')[0].attributes('aria-pressed')).toBe('true')
			expect(feel).toHaveBeenCalledWith('like')
		})

		it('takes the like back quietly, without hearts', async () => {
			const { wrapper, store } = await mountReels([{ ...video('1'), favourited: true, favourites_count: 4 }])
			likeable(store)

			expect(wrapper.find('.reel__like-count').text()).toBe('4')
			await wrapper.find('.reel__like').trigger('click')
			await flushPromises()

			expect(store.postUnlike).toHaveBeenCalled()
			expect(wrapper.findAll('.reel__heart')).toHaveLength(0)
		})

		/** a double tap is "I love this", and doing it again must not undo it */
		it('likes on a double tap, puts a heart where the finger was, and never unlikes', async () => {
			vi.useFakeTimers()
			const { wrapper, store } = await mountReels([{ ...video('1'), favourited: true }])
			likeable(store)
			const clip = wrapper.find('video')

			await clip.trigger('click', { clientX: 40, clientY: 60 })
			await clip.trigger('click', { clientX: 40, clientY: 60 })

			expect(wrapper.findAll('.reel__heart--burst')).toHaveLength(1)
			expect(store.postUnlike).not.toHaveBeenCalled()
			expect(HTMLMediaElement.prototype.pause).not.toHaveBeenCalled()

			vi.advanceTimersByTime(2000)
			await flushPromises()
			expect(wrapper.findAll('.reel__heart')).toHaveLength(0)
			vi.useRealTimers()
		})

		it('still pauses on a single tap, once it is sure no second one is coming', async () => {
			vi.useFakeTimers()
			const { wrapper } = await mountReels()
			const first = wrapper.findAll('video')[0]
			Object.defineProperty(first.element, 'paused', { value: false, configurable: true })

			await first.trigger('click')
			expect(first.element.pause).not.toHaveBeenCalled()

			vi.advanceTimersByTime(300)
			expect(first.element.pause).toHaveBeenCalled()
			vi.useRealTimers()
		})

		it('likes the playing video with the l key', async () => {
			const { wrapper, store } = await mountReels()
			likeable(store)

			await wrapper.find('.reels__track').trigger('keydown', { key: 'l' })
			await flushPromises()

			expect(store.postLike).toHaveBeenCalledWith({ status: expect.objectContaining({ id: wrapper.vm.reels[0].status.id }) })
		})
	})
})
