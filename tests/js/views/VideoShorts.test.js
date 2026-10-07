/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import VideoShorts from '../../../src/views/VideoShorts.vue'
import { useSettingsStore } from '../../../src/store/settings.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post, delete: del } }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
// signed out unless a test signs somebody in: 24-hour shorts are for a session
const session = vi.hoisted(() => ({ user: null }))
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getCurrentUser: () => session.user,
}))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))
const { feel } = vi.hoisted(() => ({ feel: vi.fn() }))
vi.mock('../../../src/services/senses.js', () => ({ feel, videoSoundEnabled: () => true }))
const { sendSignals } = vi.hoisted(() => ({ sendSignals: vi.fn(() => Promise.resolve()) }))
vi.mock('../../../src/services/interests.js', async (importOriginal) => ({
	...(await importOriginal()),
	sendSignals,
}))

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

async function mountShorts(statuses = [video('1'), video('2')], { props = {}, serverData = null } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	if (serverData !== null) {
		useSettingsStore().setServerData(serverData)
	}
	const store = useTimelineStore()
	store.fetchTimeline = vi.fn(async () => {
		// the first call fills the list, every later one says there is no more
		if (store.timeline.length === 0) {
			store.addToTimeline(statuses)

			return statuses
		}

		return []
	})

	const wrapper = mount(VideoShorts, {
		props,
		global: { plugins: [pinia], stubs: {
			NcButton: true,
			RouterLink: RouterLinkStub,
			ShortComposerDialog: {
				name: 'ShortComposerDialog',
				props: ['open'],
				emits: ['update:open', 'posted'],
				template: '<div class="short-stub" :data-open="String(open)" @click="$emit(\'posted\')" />',
			},
		} },
	})
	await flushPromises()

	return { wrapper, store }
}

describe('VideoShorts', () => {
	beforeEach(() => {
		observers = []
		// the mute choice is kept for the tab, and a test is a new tab
		window.sessionStorage.clear()
		sendSignals.mockClear()
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
		// jsdom has no media element; every short calls these
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

		mount(VideoShorts, {
			props: { scope: 'federated' },
			global: { plugins: [pinia], stubs: { NcButton: true, RouterLink: RouterLinkStub } },
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
		const { wrapper } = await mountShorts([
			video('1', {
				attachments: [
					{ id: 'a', type: 'video', url: 'https://cloud.example/a.mp4' },
					{ id: 'b', type: 'video', url: 'https://cloud.example/b.mp4' },
				],
			}),
		])

		expect(wrapper.findAll('.short')).toHaveLength(2)
	})

	it('keys each slide by its video, so a post with two keeps two', async () => {
		const { wrapper } = await mountShorts([
			video('1', {
				attachments: [
					{ id: 'a', type: 'video', url: 'https://cloud.example/a.mp4' },
					{ id: 'b', type: 'video', url: 'https://cloud.example/b.mp4' },
				],
			}),
		])

		// the key Vue diffs the slides by, as it sits on each rendered <article>
		const keys = wrapper.findAll('article.short').map((slide) => slide.element.__vnode.key)
		expect(keys).toEqual(['1:a', '1:b'])
	})

	it('leaves the pictures on a mixed post out of the stack', async () => {
		const { wrapper } = await mountShorts([
			video('1', {
				attachments: [
					{ id: 'a', type: 'image', url: 'https://cloud.example/a.jpg' },
					{ id: 'b', type: 'video', url: 'https://cloud.example/b.mp4' },
				],
			}),
		])

		expect(wrapper.findAll('.short')).toHaveLength(1)
		expect(wrapper.find('video').attributes('src')).toBe('https://cloud.example/b.mp4')
	})

	/** somebody who pressed Shorts to get here is asking to hear it */
	it('starts with the sound on', async () => {
		const { wrapper } = await mountShorts()

		expect(wrapper.vm.muted).toBe(false)
		expect(wrapper.find('.short__sound').attributes('aria-label')).toBe('Mute')
	})

	/**
	 * Opened cold, a browser refuses to start with sound. The stack plays
	 * muted rather than showing a still, and says where the sound is.
	 */
	it('falls back to muted when the browser refuses the sound, and says so', async () => {
		const { wrapper } = await mountShorts()
		const first = wrapper.findAll('video')[0].element
		first.play = vi.fn()
			.mockRejectedValueOnce(Object.assign(new Error('no'), { name: 'NotAllowedError' }))
			.mockResolvedValue(undefined)

		await wrapper.vm.play(0)
		await flushPromises()

		expect(first.muted).toBe(true)
		expect(first.play).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.short__sound').attributes('aria-label')).toBe('Unmute')
		expect(wrapper.find('.short__sound-hint').text()).toBe('Tap for sound')

		await wrapper.find('.short__sound').trigger('click')
		expect(first.muted).toBe(false)
		expect(wrapper.find('.short__sound').attributes('aria-label')).toBe('Mute')
		expect(wrapper.find('.short__sound-hint').exists()).toBe(false)
	})

	/**
	 * A refusal is the browser's, not the reader's: on an iPhone the next
	 * video after a scroll may be refused the sound too, and that must not
	 * silence the rest of the stack for somebody who wanted to hear it.
	 */
	it('asks with sound again on the next slide after a refusal', async () => {
		const { wrapper } = await mountShorts()
		const player = wrapper.find('video').element
		player.play = vi.fn()
			.mockRejectedValueOnce(Object.assign(new Error('no'), { name: 'NotAllowedError' }))
			.mockResolvedValue(undefined)

		await wrapper.vm.play(0)
		await flushPromises()
		expect(player.muted).toBe(true)

		wrapper.vm.playing = 1
		await wrapper.vm.play(1)
		await flushPromises()

		expect(player.muted).toBe(false)
		expect(wrapper.vm.muted).toBe(false)
		expect(wrapper.find('.short__sound-hint').exists()).toBe(false)
	})

	it('does not mute for a refusal that was not about the sound', async () => {
		const { wrapper } = await mountShorts()
		const first = wrapper.findAll('video')[0].element
		first.play = vi.fn().mockRejectedValue(Object.assign(new Error('gone'), { name: 'NotSupportedError' }))

		wrapper.vm.play(0)
		await flushPromises()

		expect(wrapper.vm.muted).toBe(false)
	})

	/**
	 * A dozen videos playing behind the one on screen is a phone getting hot
	 * for nothing.
	 */
	it('has one video element for the whole stack, and posters for the rest', async () => {
		const { wrapper } = await mountShorts([
			video('1', { attachments: [{ id: 'a', type: 'video', url: 'https://cloud.example/a.mp4', preview_url: 'https://cloud.example/a.jpg' }] }),
			video('2', { attachments: [{ id: 'b', type: 'video', url: 'https://cloud.example/b.mp4', preview_url: 'https://cloud.example/b.jpg' }] }),
			video('3'),
		])

		expect(wrapper.findAll('.short')).toHaveLength(3)
		expect(wrapper.findAll('video')).toHaveLength(1)
		expect(wrapper.findAll('.short__poster').map((img) => img.attributes('src')).sort())
			.toEqual(['https://cloud.example/a.jpg', 'https://cloud.example/b.jpg'])
	})

	/**
	 * The element a tap once allowed to play with sound is the one that
	 * plays the next video, so WebKit has no new element to refuse.
	 */
	it('moves the one player to the slide on screen and plays that slide', async () => {
		const { wrapper, store } = await mountShorts([video('1'), video('2'), video('3'), video('4'), video('5')])
		const player = wrapper.find('video').element
		expect(player.getAttribute('src')).toBe(wrapper.vm.shorts[0].video.url)
		store.fetchTimeline.mockClear()

		observers[0].callback([{ isIntersecting: true, target: wrapper.vm.slides[1] }])
		await flushPromises()

		const now = wrapper.find('video')
		expect(now.element).toBe(player)
		expect(now.attributes('src')).toBe(wrapper.vm.shorts[1].video.url)
		expect(now.attributes('src')).not.toBe(wrapper.vm.shorts[0].video.url)
		expect(now.attributes('style')).toContain('--at: 1')
		expect(player.play).toHaveBeenCalled()
		expect(wrapper.vm.playing).toBe(1)
		expect(store.fetchTimeline).not.toHaveBeenCalled()
	})

	it('keeps the sound as it was when the player moves on', async () => {
		const { wrapper } = await mountShorts()
		const player = wrapper.find('video').element

		await wrapper.find('.short__sound').trigger('click')
		expect(player.muted).toBe(true)

		observers[0].callback([{ isIntersecting: true, target: wrapper.vm.slides[1] }])
		await flushPromises()

		expect(player.muted).toBe(true)
		expect(wrapper.findAll('.short__sound')[1].attributes('aria-label')).toBe('Unmute')
	})

	/** The sound is a statement about the page, not about one video. */
	it('mutes and unmutes the whole stack at once', async () => {
		const { wrapper } = await mountShorts()

		await wrapper.find('.short__sound').trigger('click')
		expect(wrapper.vm.muted).toBe(true)
		expect(wrapper.findAll('video')[0].element.muted).toBe(true)

		await wrapper.find('.short__sound').trigger('click')
		expect(wrapper.vm.muted).toBe(false)
		expect(wrapper.findAll('video')[0].element.muted).toBe(false)
	})

	/** a new short: the + opens the dialog made for one, and a posted short refreshes the stack */
	it('opens the New short dialog, and reloads once a short is posted', async () => {
		const { wrapper, store } = await mountShorts()
		const stub = () => wrapper.find('.short-stub')
		expect(stub().attributes('data-open')).toBe('false')
		expect(wrapper.find('.shorts__create').attributes('aria-label')).toBe('New short')

		await wrapper.find('.shorts__create').trigger('click')
		expect(stub().attributes('data-open')).toBe('true')
		expect(HTMLMediaElement.prototype.pause).toHaveBeenCalled()

		const before = store.fetchTimeline.mock.calls.length
		await stub().trigger('click')
		await flushPromises()
		expect(store.fetchTimeline.mock.calls.length).toBeGreaterThan(before)
	})

	/** A stack is reachable from a keyboard or it is reachable by nobody using one. */
	it('moves with the arrow keys', async () => {
		const { wrapper } = await mountShorts()
		const scrolled = []
		wrapper.vm.slides.forEach((slide, at) => {
			slide.scrollIntoView = () => scrolled.push(at)
		})

		await wrapper.find('.shorts__track').trigger('keydown', { key: 'ArrowDown' })

		expect(scrolled).toEqual([1])
	})

	it('moves without the smooth scroll for a reader who asked for less motion', async () => {
		vi.stubGlobal('matchMedia', (query) => ({ matches: query.includes('reduce') }))
		const { wrapper } = await mountShorts()
		const behaviours = []
		wrapper.vm.slides.forEach((slide) => {
			slide.scrollIntoView = (options) => behaviours.push(options.behavior)
		})

		await wrapper.find('.shorts__track').trigger('keydown', { key: 'ArrowDown' })

		expect(behaviours).toEqual(['auto'])
	})

	it('is announced as a region under its name', async () => {
		const { wrapper } = await mountShorts()

		expect(wrapper.find('.shorts').attributes('role')).toBe('region')
		expect(wrapper.find('.shorts').attributes('aria-label')).toBe('Videos, one at a time')
	})

	/**
	 * The router keeps this view when only `?scope=` changes, so the prop
	 * changes under a mounted stack.
	 */
	it('switches the feed when the scope changes', async () => {
		const { wrapper, store } = await mountShorts()

		await wrapper.setProps({ scope: 'federated' })
		await flushPromises()

		expect(store.params.scope).toBe('federated')
		expect(store.fetchTimeline).toHaveBeenCalledTimes(2)
	})

	it('pauses and resumes with the space bar', async () => {
		const { wrapper } = await mountShorts()
		const first = wrapper.findAll('video')[0].element
		Object.defineProperty(first, 'paused', { value: false, configurable: true })

		await wrapper.find('.shorts__track').trigger('keydown', { key: ' ' })

		expect(first.pause).toHaveBeenCalled()
	})

	/**
	 * The next page is asked for before the reader reaches the end, or the
	 * stack stops dead while it loads.
	 */
	it('asks for more before the end of what it holds', async () => {
		const { wrapper, store } = await mountShorts()
		store.fetchTimeline.mockClear()

		wrapper.vm.play(wrapper.vm.shorts.length - 1)
		await flushPromises()

		expect(store.fetchTimeline).toHaveBeenCalled()
	})

	/** A cursor rounded through a Number skips rows or loops on one. */
	it('pages on the id as a string', async () => {
		const { wrapper, store } = await mountShorts([
			video('114500000000000001'),
			video('114500000000000002'),
		])
		store.fetchTimeline.mockClear()

		await wrapper.vm.load()

		expect(store.fetchTimeline).toHaveBeenCalledWith({ max_id: '114500000000000001' })
	})

	it('stops asking once a page comes back empty', async () => {
		const { wrapper, store } = await mountShorts()

		await wrapper.vm.load()
		store.fetchTimeline.mockClear()
		await wrapper.vm.load()

		expect(store.fetchTimeline).not.toHaveBeenCalled()
	})

	/** Leaving the page must not leave a video playing behind it. */
	it('pauses everything when it goes away', async () => {
		const { wrapper } = await mountShorts()
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
				{ path: '/shorts', name: 'shorts', component: empty },
			],
		})
		await router.push('/')

		const wrapper = mount(VideoShorts, {
			global: { plugins: [pinia, router], stubs: { NcButton: true } },
		})
		await flushPromises()

		expect(wrapper.find('a.short__open').attributes('href')).toBe('/index.php/apps/social/@bob@remote.example/1')
	})

	it('says it is loading while the first page is on its way', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const store = useTimelineStore()
		store.fetchTimeline = vi.fn(() => new Promise(() => {}))

		const wrapper = mount(VideoShorts, {
			global: { plugins: [pinia], stubs: { NcButton: true, RouterLink: RouterLinkStub } },
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

		const wrapper = mount(VideoShorts, {
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
		expect(wrapper.findAll('.short')).toHaveLength(1)
	})

	it('says so when there is nothing to watch', async () => {
		const { wrapper } = await mountShorts([])

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
			const { wrapper, store } = await mountShorts()
			likeable(store)
			feel.mockClear()

			const button = wrapper.findAll('.short__like')[0]
			expect(button.attributes('aria-pressed')).toBe('false')
			await button.trigger('click')
			await flushPromises()

			expect(store.postLike).toHaveBeenCalledWith({ status: expect.objectContaining({ id: wrapper.vm.shorts[0].status.id }) })
			expect(wrapper.findAll('.short')[0].findAll('.short__heart--float')).toHaveLength(3)
			expect(wrapper.findAll('.short__like')[0].attributes('aria-pressed')).toBe('true')
			expect(feel).toHaveBeenCalledWith('like')
		})

		it('takes the like back quietly, without hearts', async () => {
			const { wrapper, store } = await mountShorts([{ ...video('1'), favourited: true, favourites_count: 4 }])
			likeable(store)

			expect(wrapper.find('.short__like-count').text()).toBe('4')
			await wrapper.find('.short__like').trigger('click')
			await flushPromises()

			expect(store.postUnlike).toHaveBeenCalled()
			expect(wrapper.findAll('.short__heart')).toHaveLength(0)
		})

		/** a double tap is "I love this", and doing it again must not undo it */
		it('likes on a double tap, puts a heart where the finger was, and never unlikes', async () => {
			vi.useFakeTimers()
			const { wrapper, store } = await mountShorts([{ ...video('1'), favourited: true }])
			likeable(store)
			const clip = wrapper.find('video')

			await clip.trigger('click', { clientX: 40, clientY: 60 })
			await clip.trigger('click', { clientX: 40, clientY: 60 })

			expect(wrapper.findAll('.short__heart--burst')).toHaveLength(1)
			expect(store.postUnlike).not.toHaveBeenCalled()
			expect(HTMLMediaElement.prototype.pause).not.toHaveBeenCalled()

			vi.advanceTimersByTime(2000)
			await flushPromises()
			expect(wrapper.findAll('.short__heart')).toHaveLength(0)
			vi.useRealTimers()
		})

		it('still pauses on a single tap, once it is sure no second one is coming', async () => {
			vi.useFakeTimers()
			const { wrapper } = await mountShorts()
			const first = wrapper.findAll('video')[0]
			Object.defineProperty(first.element, 'paused', { value: false, configurable: true })

			await first.trigger('click')
			expect(first.element.pause).not.toHaveBeenCalled()

			vi.advanceTimersByTime(300)
			expect(first.element.pause).toHaveBeenCalled()
			vi.useRealTimers()
		})

		it('likes the playing video with the l key', async () => {
			const { wrapper, store } = await mountShorts()
			likeable(store)

			await wrapper.find('.shorts__track').trigger('keydown', { key: 'l' })
			await flushPromises()

			expect(store.postLike).toHaveBeenCalledWith({ status: expect.objectContaining({ id: wrapper.vm.shorts[0].status.id }) })
		})
	})

	/**
	 * Shorts is its own entry in the sidebar, so the circle of people whose
	 * videos these are is chosen here rather than carried over from the grid.
	 */
	describe('whose videos', () => {
		const scopeLinks = (wrapper) => wrapper.findAllComponents(RouterLinkStub)
			.filter((link) => link.classes().includes('shorts__scope'))

		it('offers the three circles, and marks the one on screen', async () => {
			const { wrapper } = await mountShorts()
			await wrapper.setProps({ scope: 'federated' })

			const links = wrapper.findAll('.shorts__scope')
			expect(links.map((link) => link.text())).toEqual(['My Feed', 'Local', 'Global'])
			expect(links[2].classes()).toContain('shorts__scope--current')
			expect(links[2].attributes('aria-current')).toBe('page')
		})

		it('offers a reader without a session only the two they can read', async () => {
			const { wrapper } = await mountShorts()
			useSettingsStore().setServerData({ public: true })
			await wrapper.vm.$nextTick()

			expect(wrapper.findAll('.shorts__scope').map((link) => link.text())).toEqual(['Local', 'Global'])
		})

		it('links each circle to this page at that scope', async () => {
			const { wrapper } = await mountShorts()

			expect(scopeLinks(wrapper).map((link) => link.props('to'))).toEqual([
				{ name: 'shorts', query: { scope: 'home' } },
				{ name: 'shorts', query: { scope: 'timeline' } },
				{ name: 'shorts', query: { scope: 'federated' } },
			])
			// the corner used to hold a way back to the Videos grid
			expect(wrapper.find('.shorts__close').exists()).toBe(false)
		})
	})
	/**
	 * For you narrowed to videos: offered to a signed-in reader who has it,
	 * and where the stack opens once reading has taught it something.
	 */
	describe('For you', () => {
		const ON = { enabled: true, learning: true, paused: false, noticeAcknowledged: true }
		const labels = (wrapper) => wrapper.findAll('.shorts__scope').map((link) => link.text())

		it('is offered beside My Feed while the reader has it', async () => {
			const { wrapper } = await mountShorts(undefined, { props: { scope: 'home' }, serverData: { public: false, interests: ON } })

			expect(labels(wrapper)).toEqual(['My Feed', 'For you', 'Local', 'Global'])
			expect(wrapper.findAllComponents(RouterLinkStub).filter((link) => link.classes().includes('shorts__scope'))[1].props('to'))
				.toEqual({ name: 'shorts', query: { scope: 'interests' } })
		})

		it.each([
			['the feature is off', { public: false, interests: { ...ON, enabled: false } }],
			['the reader opted out', { public: false, interests: { ...ON, learning: false } }],
			['nobody is signed in', { public: true, interests: ON }],
		])('is not offered when %s', async (_, serverData) => {
			const { wrapper } = await mountShorts(undefined, { serverData })

			expect(labels(wrapper)).not.toContain('For you')
		})

		it('is where the stack opens once reading has taught it something', async () => {
			const { store } = await mountShorts(undefined, { serverData: { public: false, interests: { ...ON, profile: true } } })

			expect(store.type).toBe('videos')
			expect(store.params.scope).toBe('interests')
		})

		it('is not the default before that, nor asked for by one who does not have it', async () => {
			const fresh = await mountShorts(undefined, { serverData: { public: false, interests: ON } })
			expect(fresh.store.params.scope).toBe('home')

			const without = await mountShorts(undefined, { props: { scope: 'interests' }, serverData: { public: false, interests: { ...ON, enabled: false, profile: true } } })
			expect(without.store.params.scope).toBe('home')
		})

		it('pages a ranking from where it ended, not from its oldest post', async () => {
			const { wrapper, store } = await mountShorts([video('1'), video('2')], {
				props: { scope: 'interests' },
				serverData: { public: false, interests: ON },
			})

			observers[0].callback([{ isIntersecting: true, target: wrapper.findAll('.short')[0].element }])
			await flushPromises()

			expect(store.fetchTimeline).toHaveBeenLastCalledWith({ max_id: '2' })
		})

		it('says why a video is there, as the chip over a post does', async () => {
			const { wrapper } = await mountShorts([video('1'), { ...video('2'), interest: { tags: [], reason: 'popular' } }], {
				props: { scope: 'interests' },
				serverData: { public: false, interests: ON },
			})

			const reasons = wrapper.findAll('.short__reason')
			expect(reasons).toHaveLength(1)
			expect(reasons[0].text()).toBe('Popular right now')
		})

		it('reports what the reader watched when they move on, under shorts', async () => {
			const tagged = (id) => ({ ...video(id), tags: [{ name: 'skate' }] })
			const { wrapper } = await mountShorts([tagged('1'), tagged('2')], { serverData: { public: false, interests: ON } })
			// one player, which follows the slide being watched
			observers[0].callback([{ isIntersecting: true, target: wrapper.findAll('.short')[0].element }])
			await flushPromises()
			const player = wrapper.find('video')
			Object.defineProperty(player.element, 'duration', { value: 10, configurable: true })
			player.element.currentTime = 6
			await player.trigger('timeupdate')
			await player.trigger('ended')
			observers[0].callback([{ isIntersecting: true, target: wrapper.findAll('.short')[1].element }])
			await flushPromises()
			await wrapper.find('video').trigger('timeupdate')
			wrapper.unmount()

			// newest first: the first slide is the second post
			expect(sendSignals.mock.calls.map(([events]) => events[0])).toEqual([
				{ status_id: '2', kind: 'dwell', ms: 10000, context: 'shorts' },
				{ status_id: '1', kind: 'skip', context: 'shorts' },
			])
		})

		it('reports nothing while learning is paused', async () => {
			const tagged = (id) => ({ ...video(id), tags: [{ name: 'skate' }] })
			const { wrapper } = await mountShorts([tagged('1'), tagged('2')], { serverData: { public: false, interests: { ...ON, paused: true } } })

			observers[0].callback([{ isIntersecting: true, target: wrapper.findAll('.short')[1].element }])
			wrapper.unmount()

			expect(sendSignals).not.toHaveBeenCalled()
		})
	})

	describe('24-hour shorts', () => {
		const alice = { id: '7', acct: 'alice', username: 'alice', display_name: 'Alice', avatar: '' }
		const bob = { id: '9', acct: 'bob@remote.example', username: 'bob', display_name: 'Bob', avatar: '' }
		const carol = { id: '11', acct: 'carol@remote.example', username: 'carol', display_name: 'Carol', avatar: '' }

		function day(id, account, overrides = {}) {
			return {
				id,
				account,
				seen: false,
				duration: 5,
				caption: '',
				view_count: 0,
				created_at: new Date().toISOString(),
				expires_at: new Date(Date.now() + 5.5 * 3600 * 1000).toISOString(),
				media: { type: 'image', url: `https://cloud.example/${id}.jpg` },
				...overrides,
			}
		}

		const clip = (id, account, overrides = {}) => day(id, account, { media: { type: 'video', url: `https://cloud.example/${id}.mp4`, preview_url: '' }, ...overrides })

		/**
		 * @param {object[]} carousel what the stories carousel answers
		 * @param {object} [options] passed on to mountShorts
		 */
		async function mountDay(carousel, options = {}) {
			get.mockResolvedValue({ data: carousel })

			return mountShorts(options.statuses ?? [video('1')], options)
		}

		beforeEach(() => {
			session.user = { uid: 'alice', displayName: 'Alice', isAdmin: false }
			get.mockReset()
			post.mockReset().mockResolvedValue({ data: {} })
			del.mockReset().mockResolvedValue({ data: {} })
			showError.mockReset()
			showSuccess.mockReset()
		})

		afterEach(() => {
			session.user = null
			vi.useRealTimers()
		})

		const keys = (wrapper) => wrapper.findAll('article.short').map((slide) => slide.element.__vnode.key)

		it('comes before the kept shorts, one person after another, unseen first and the reader\'s own last', async () => {
			const { wrapper } = await mountDay([day('1', alice), day('2', bob, { seen: true }), day('3', carol), day('4', carol)])

			expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/stories/carousel')
			expect(keys(wrapper)).toEqual(['day:3', 'day:4', 'day:2', 'day:1', '1:1-1'])
		})

		it('opens at the person asked for', async () => {
			const { wrapper } = await mountDay([day('1', alice), day('2', bob), day('3', carol)], { props: { account: 'carol@remote.example' } })

			expect(keys(wrapper)[0]).toBe('day:3')
			expect(wrapper.vm.playing).toBe(0)
		})

		it('moves to a person asked for while the stack is open', async () => {
			const { wrapper } = await mountDay([day('2', bob), day('3', carol)])
			expect(keys(wrapper)[0]).toBe('day:2')

			await wrapper.setProps({ account: 'carol@remote.example' })
			await flushPromises()

			expect(keys(wrapper)[0]).toBe('day:3')
		})

		it('waits for them before showing anything, so nothing arrives above the slide being watched', async () => {
			let answer
			get.mockImplementation(() => new Promise((resolve) => {
				answer = resolve
			}))
			const { wrapper } = await mountShorts([video('1')])

			expect(wrapper.findAll('article.short')).toHaveLength(0)
			answer({ data: [day('2', bob)] })
			await flushPromises()

			expect(keys(wrapper)).toEqual(['day:2', '1:1-1'])
		})

		it('is not asked for by a reader without a session, or where the admin turned it off', async () => {
			session.user = null
			await mountDay([day('2', bob)])
			expect(get).not.toHaveBeenCalled()

			session.user = { uid: 'alice' }
			await mountDay([day('2', bob)], { serverData: { sections: { stories: false } } })
			expect(get).not.toHaveBeenCalled()
		})

		it('still shows the kept shorts when the 24-hour ones could not be loaded', async () => {
			get.mockRejectedValue(new Error('nope'))
			const { wrapper } = await mountShorts([video('1')])

			expect(keys(wrapper)).toEqual(['1:1-1'])
		})

		it('carries the 24h mark and the hours left', async () => {
			const { wrapper } = await mountDay([day('2', bob)])

			const slide = wrapper.find('article.short')
			expect(slide.classes()).toContain('short--day')
			expect(slide.find('.day-short__mark').text()).toBe('24h')
			expect(slide.find('.day-short__left').text()).toBe('6h left')
			// a kept short has neither
			expect(wrapper.findAll('article.short')[1].find('.day-short__mark').exists()).toBe(false)
		})

		it('marks the one on screen seen, once', async () => {
			const { wrapper } = await mountDay([day('2', bob), day('3', bob, { seen: true })])

			expect(post).toHaveBeenCalledTimes(1)
			expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/stories/2/seen')
			expect(wrapper.vm.dayShorts[0].seen).toBe(true)
		})

		it('gives the poster who has seen it, what was said, and a delete; no reactions', async () => {
			get.mockImplementation(async (url) => url.includes('reactions')
				? { data: { reactions: [{ id: 'r1', type: 'reply', content: 'lovely light', account: bob }] } }
				: { data: [day('1', alice, { view_count: 12, seen: true })] })
			const { wrapper } = await mountShorts([video('1')])
			await flushPromises()

			const slide = wrapper.find('article.short')
			expect(slide.find('.day-short__views').attributes('title')).toBe('Who has seen it')
			expect(slide.find('.day-short__views').text()).toContain('12')
			expect(slide.find('.day-short__delete').attributes('aria-label')).toBe('Delete this short')
			expect(slide.find('.day-short__answers').text()).toContain('lovely light')
			expect(slide.find('.day-short__reaction').exists()).toBe(false)
			// the poster's own is not marked seen: the server would refuse
			expect(post).not.toHaveBeenCalled()
		})

		it('deletes the poster\'s own through the API, and the next slide takes its place', async () => {
			const { wrapper } = await mountDay([day('1', alice, { seen: true })], { props: { account: 'alice' } })

			await wrapper.findComponent({ name: 'DayShortPanel' }).vm.remove()
			await flushPromises()

			expect(del).toHaveBeenCalledWith('/index.php/apps/social/api/v1/stories/1')
			expect(showSuccess).toHaveBeenCalledWith('Short deleted')
			expect(keys(wrapper)).toEqual(['1:1-1'])
			expect(wrapper.vm.playing).toBe(0)
		})

		it('lets everybody else react and reply, and has no like for it', async () => {
			const { wrapper } = await mountDay([day('2', bob)])
			post.mockClear()
			const slide = wrapper.find('article.short')
			expect(slide.find('.short__like').exists()).toBe(false)
			expect(slide.find('.day-short__delete').exists()).toBe(false)

			await slide.find('.day-short__reaction').trigger('click')
			await flushPromises()
			expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1.2/stories/react', { sid: '2', reaction: '❤️' })

			const field = slide.find('.day-short__reply-field')
			expect(field.attributes('placeholder')).toBe('Reply to this short…')
			await field.setValue('  lovely light  ')
			await slide.find('.day-short__reply').trigger('submit')
			await flushPromises()
			expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1.2/stories/comment', { sid: '2', caption: 'lovely light' })
			expect(field.element.value).toBe('')
		})

		it('does not page while a reply is being written', async () => {
			const { wrapper } = await mountDay([day('2', bob)])
			const scrolled = []
			wrapper.vm.slides.forEach((slide, at) => {
				slide.scrollIntoView = () => scrolled.push(at)
			})

			await wrapper.find('.day-short__reply-field').trigger('keydown', { key: 'ArrowDown' })
			await wrapper.find('.day-short__reply-field').trigger('keydown', { key: ' ' })

			expect(scrolled).toEqual([])
			expect(wrapper.vm.held).toBe(false)
		})

		it('moves a picture on when its seconds are up, and holds it while held', async () => {
			vi.useFakeTimers()
			const { wrapper } = await mountDay([day('2', bob, { duration: 3 }), day('3', bob)])
			const scrolled = []
			wrapper.vm.slides.forEach((slide, at) => {
				slide.scrollIntoView = () => scrolled.push(at)
			})

			await wrapper.find('.short__picture').trigger('click')
			expect(wrapper.vm.held).toBe(true)
			vi.advanceTimersByTime(5000)
			expect(scrolled).toEqual([])

			await wrapper.find('.short__picture').trigger('click')
			vi.advanceTimersByTime(3200)
			expect(scrolled).toEqual([1])
		})

		it('plays a picture with the one player hidden and stopped, not a second element', async () => {
			const { wrapper } = await mountDay([day('2', bob)])

			expect(wrapper.findAll('video')).toHaveLength(1)
			expect(wrapper.find('video').attributes('style')).toContain('display: none')
			expect(wrapper.find('video').attributes('src')).toBeUndefined()
			expect(wrapper.find('.short__picture').attributes('src')).toBe('https://cloud.example/2.jpg')
			expect(wrapper.find('article.short').find('.short__sound').exists()).toBe(false)
		})

		it('plays a 24-hour video in the one player, once, and moves on when it ends', async () => {
			const { wrapper } = await mountDay([clip('2', bob), day('3', bob)])
			const player = wrapper.find('video')
			expect(player.attributes('src')).toBe('https://cloud.example/2.mp4')
			expect(player.element.loop).toBe(false)
			const scrolled = []
			wrapper.vm.slides.forEach((slide, at) => {
				slide.scrollIntoView = () => scrolled.push(at)
			})

			await player.trigger('ended')

			expect(scrolled).toEqual([1])
		})

		it('stops a 24-hour video while a reply is written, and lets it run again after', async () => {
			const { wrapper } = await mountDay([clip('2', bob)])
			const element = wrapper.find('video').element
			const pause = vi.fn(() => Object.defineProperty(element, 'paused', { value: true, configurable: true }))
			const play = vi.fn(() => Promise.resolve())
			Object.defineProperty(element, 'pause', { value: pause, configurable: true })
			Object.defineProperty(element, 'play', { value: play, configurable: true })

			await wrapper.find('.day-short__reply-field').trigger('focus')
			expect(pause).toHaveBeenCalled()

			await wrapper.find('.day-short__reply-field').trigger('blur')
			await flushPromises()
			expect(play).toHaveBeenCalled()
		})

		it('shows a 24-hour short the reader just posted from here first', async () => {
			const { wrapper } = await mountDay([day('2', bob)])
			get.mockResolvedValue({ data: [day('2', bob), day('5', alice)] })

			wrapper.findComponent({ name: 'ShortComposerDialog' }).vm.$emit('posted', { id: '5' }, 'day')
			await flushPromises()

			expect(keys(wrapper)[0]).toBe('day:5')
		})
	})
})
