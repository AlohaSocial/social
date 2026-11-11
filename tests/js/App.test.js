/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global setInitialState */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, shallowRef } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import App from '../../src/App.vue'
import ShortcutHelp from '../../src/components/ShortcutHelp.vue'
import ShortcutList from '../../src/components/ShortcutList.vue'
import eventBus, { REACTION_PICK } from '../../src/services/eventBus.js'
import { closeMiniPlayer, handOff } from '../../src/services/miniPlayer.js'
import { useAccountStore } from '../../src/store/account.js'
import { useSettingsStore } from '../../src/store/settings.js'
import { useTimelineStore } from '../../src/store/timeline.js'

// counts how often the emoji picker's chunk is fetched: the factory runs on
// the module's first import, which for App is its async component loading
const pickerChunk = vi.hoisted(() => ({ loads: 0 }))
vi.mock('../../src/components/ReactionPicker.vue', async () => {
	pickerChunk.loads++
	const { h } = await import('vue')

	return { __esModule: true, default: { name: 'ReactionPicker', props: ['firstAsk'], render: () => h('div', { class: 'reaction-picker-chunk' }) } }
})

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

// Nothing here is testing the network, and a request that is allowed to be made
// is a request that can still be failing — and logging about it — after the test
// that started it has gone. The stores App mounts fetch on their own.
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: [] }),
		post: vi.fn().mockResolvedValue({ data: {} }),
		put: vi.fn().mockResolvedValue({ data: {} }),
		patch: vi.fn().mockResolvedValue({ data: {} }),
		delete: vi.fn().mockResolvedValue({ data: {} }),
	},
}))

const stubs = {
	NcContent: { props: ['appName'], template: '<div class="content-stub" :data-app-name="appName"><slot /></div>' },
	NcAppContent: { template: '<main class="app-content-stub"><slot /></main>' },
	Navigation: { emits: ['search'], template: '<nav class="navigation-stub" />' },
	// the real one is an async chunk; the stub stands in synchronously
	AccountSetup: { name: 'AccountSetup', emits: ['created'], template: '<section class="account-setup-stub" />' },
	// so is the emoji picker, and it is the larger of the two. Left to itself it
	// resolves after the file that mounted it has finished, and logging from a
	// worker whose rpc is already closing fails the whole run with an
	// EnvironmentTeardownError that names no test.
	ReactionPicker: { name: 'ReactionPicker', template: '<div class="reaction-picker-stub" />' },
	RouterView: { template: '<div class="router-view-stub" />' },
	MiniPlayer: { name: 'MiniPlayer', template: '<aside class="mini-player-stub" />' },
}

const baseServerData = {
	public: false,
	setup: false,
	needsAccount: false,
	isAdmin: false,
	firstrun: false,
	cloudAddress: 'https://cloud.example.org',
	cliUrl: 'https://cloud.example.org',
	checks: { success: true, checks: { wellknown: true } },
	viewer: { username: 'alice', address: 'alice@cloud.example.org' },
}

let pinia
let accountStore
let settingsStore
let timelineStore
let route
let router

function setServerData(overrides = {}) {
	setInitialState('social', 'serverData', { ...baseServerData, ...overrides })
	window._nc_initial_state?.clear()
}

function makeStore() {
	pinia = createPinia()
	setActivePinia(pinia)
	accountStore = useAccountStore()
	settingsStore = useSettingsStore()
	timelineStore = useTimelineStore()
	// the network calls the App would make on mount, and the two actions the
	// assertions below watch for
	vi.spyOn(accountStore, 'fetchAccountInfo').mockResolvedValue(undefined)
	// watched, not replaced: what they do to the store is part of the assertions
	vi.spyOn(accountStore, 'fetchCurrentAccountInfo')
	vi.spyOn(timelineStore, 'addToTimeline')
	vi.spyOn(timelineStore, 'refreshTimeline').mockResolvedValue(undefined)
	vi.spyOn(timelineStore, 'fetchTimeline').mockResolvedValue(undefined)

	return pinia
}

// $route is exposed through a getter so swapping the ref triggers the
// component's $route watcher like vue-router would.
const routePlugin = {
	install(app) {
		Object.defineProperty(app.config.globalProperties, '$route', { get: () => route.value, configurable: true })
	},
}

/**
 * Every App this file mounts, so that teardown can take them all down again.
 *
 * A component left mounted keeps its watchers, its timers and whatever it has
 * in flight alive past the test that made it. When one of those finally logs,
 * the worker's rpc is already closing and the whole run fails with an
 * `EnvironmentTeardownError` that names no test — which is exactly as hard to
 * find as it sounds.
 *
 * @type {object[]}
 */
const mounted = []

/**
 * @return {object} a mounted App, registered for teardown
 */
function mountApp() {
	const wrapper = mount(App, {
		global: { plugins: [pinia, routePlugin], mocks: { $router: router }, stubs },
	})
	mounted.push(wrapper)

	return wrapper
}

describe('App', () => {
	beforeEach(() => {
		makeStore()
		route = shallowRef({ name: 'timeline', params: {}, fullPath: '/timeline' })
		router = { push: vi.fn() }
		setServerData()
	})

	afterEach(async () => {
		while (mounted.length > 0) {
			mounted.pop().unmount()
		}
		// let anything the unmount kicked off finish while the worker is still
		// listening, rather than after it has gone
		await flushPromises()
		vi.restoreAllMocks()
		delete globalThis.OCA.Push
	})

	describe('before there is an account', () => {
		it('asks, and mounts none of the app that would create one behind the reader', async () => {
			setServerData({ needsAccount: true, suggestedHandle: 'alice' })
			makeStore()
			const wrapper = mountApp()
			await flushPromises()

			expect(wrapper.findComponent({ name: 'AccountSetup' }).exists()).toBe(true)
			expect(wrapper.findComponent({ name: 'Navigation' }).exists()).toBe(false)
			expect(wrapper.find('.timeline-list-stub, .router-view-stub').exists()).toBe(false)
		})

		it('reloads into the introduction once the account exists', async () => {
			setServerData({ needsAccount: true, suggestedHandle: 'alice' })
			makeStore()
			const wrapper = mountApp()
			const reload = vi.spyOn(wrapper.vm, 'reloadTo').mockImplementation(() => {})

			wrapper.findComponent({ name: 'AccountSetup' }).vm.$emit('created', { id: '1' })

			expect(reload).toHaveBeenCalledWith('/index.php/apps/social/?welcome=1')
		})
	})

	describe('keyboard shortcuts', () => {
		afterEach(() => {
			eventBus.all.clear()
		})

		it('opens and closes the shortcut sheet on ?', async () => {
			const wrapper = mountApp()
			expect(wrapper.findComponent(ShortcutHelp).props('open')).toBe(false)

			eventBus.emit('shortcut:help')
			await wrapper.vm.$nextTick()
			expect(wrapper.findComponent(ShortcutHelp).props('open')).toBe(true)

			eventBus.emit('shortcut:help')
			await wrapper.vm.$nextTick()
			expect(wrapper.findComponent(ShortcutHelp).props('open')).toBe(false)
		})

		it('listens for no key once the reader has turned the shortcuts off', async () => {
			const wrapper = mountApp()
			const heard = vi.fn()
			eventBus.on('shortcut:next', heard)

			settingsStore.setServerDataEntry({ key: 'keyboardShortcuts', value: false })
			window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j' }))
			window.dispatchEvent(new KeyboardEvent('keydown', { key: '?' }))
			await wrapper.vm.$nextTick()

			expect(heard).not.toHaveBeenCalled()
			expect(wrapper.findComponent(ShortcutHelp).props('open')).toBe(false)

			settingsStore.setServerDataEntry({ key: 'keyboardShortcuts', value: true })
			window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j' }))
			expect(heard).toHaveBeenCalledTimes(1)
		})

		/**
		 * Asserted through `ShortcutList`, which is where the list lives now:
		 * the Settings page shows the same one, and a shortcut is a promise
		 * about a keystroke that two places must not be able to disagree on.
		 */
		it('lists every shortcut with its keys and what it does', async () => {
			const wrapper = mountApp()
			// the sheet renders nothing until it is open
			eventBus.emit('shortcut:help')
			await wrapper.vm.$nextTick()
			const shortcuts = wrapper.findComponent(ShortcutList).vm.shortcuts

			expect(shortcuts.length).toBeGreaterThan(5)
			for (const shortcut of shortcuts) {
				expect(shortcut.keys.length).toBeGreaterThan(0)
				expect(shortcut.label).toBeTruthy()
			}
		})

		it('lists the video player\'s keys as well', async () => {
			const wrapper = mountApp()
			eventBus.emit('shortcut:help')
			await wrapper.vm.$nextTick()
			const list = wrapper.findComponent(ShortcutList)

			expect(list.text()).toContain('Watching a video')
			expect(list.vm.playerShortcuts.map((shortcut) => shortcut.keys[0])).toEqual(['k', 'j', '←', '↑', 'm', 'f', 'c', '<', '0'])
		})

		it('stops listening once the app is gone', () => {
			const wrapper = mountApp()
			wrapper.unmount()

			expect(() => eventBus.emit('shortcut:help')).not.toThrow()
		})
	})

	/**
	 * Rendered at all, the picker is a 935 KB chunk of emoji data and the
	 * colour picker, fetched on every page by every visitor -- most of whom
	 * never add a reaction.
	 */
	it('fetches the emoji picker only once somebody asks for a reaction', async () => {
		const withoutPicker = { ...stubs }
		delete withoutPicker.ReactionPicker
		const wrapper = mount(App, {
			global: { plugins: [pinia, routePlugin], mocks: { $router: router }, stubs: withoutPicker },
		})
		mounted.push(wrapper)
		await flushPromises()

		expect(pickerChunk.loads).toBe(0)
		expect(wrapper.find('.reaction-picker-chunk').exists()).toBe(false)

		const ask = { react: vi.fn() }
		eventBus.emit(REACTION_PICK, ask)
		await flushPromises()

		expect(pickerChunk.loads).toBe(1)
		expect(wrapper.findComponent({ name: 'ReactionPicker' }).props('firstAsk').react).toBe(ask.react)
	})

	it('imports the server data and loads the current account', () => {
		mountApp()
		expect(settingsStore.serverData).toEqual(baseServerData)
		expect(accountStore.fetchCurrentAccountInfo).toHaveBeenCalledWith('alice@cloud.example.org')
		expect(accountStore.currentAccountHandle).toBe('alice@cloud.example.org')
		// the action asks the account store for the lookup itself
		expect(accountStore.fetchAccountInfo).toHaveBeenCalledTimes(1)
		expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('alice@cloud.example.org')
	})

	describe('when the username is not the user id', () => {
		const chosen = { id: '1', acct: 'aloha_al', username: 'aloha_al', display_name: 'Al', url: 'https://cloud.example.org/@aloha_al' }

		afterEach(() => {
			document.getElementById('initial-state-social-currentAccount')?.remove()
			window._nc_initial_state?.clear()
		})

		/**
		 * The account the page carries was made current under the user id
		 * (`alice@…`) while the store filed it under its own name, so there
		 * was no current account at all: no profile link, no "this is mine".
		 */
		it('makes the account the page carries current under its own name', () => {
			setServerData({ viewer: { username: 'aloha_al', address: 'aloha_al@cloud.example.org' } })
			setInitialState('social', 'currentAccount', chosen)
			window._nc_initial_state?.clear()
			mountApp()

			expect(accountStore.currentAccountHandle).toBe('aloha_al@cloud.example.org')
			expect(accountStore.currentAccount).toEqual(chosen)
			expect(accountStore.fetchCurrentAccountInfo).not.toHaveBeenCalled()
		})

		it('asks for the account by its own address where the page carries none', () => {
			setServerData({ viewer: { username: 'aloha_al', address: 'aloha_al@cloud.example.org' } })
			mountApp()

			expect(accountStore.fetchCurrentAccountInfo).toHaveBeenCalledWith('aloha_al@cloud.example.org')
			expect(accountStore.fetchAccountInfo).toHaveBeenCalledWith('aloha_al@cloud.example.org')
		})
	})

	it('asks for nothing while the setup screen is still asking them', () => {
		// the lookup is what created the account: the API makes one for
		// whoever asks about themselves, so the handle the screen was offering
		// had already been taken -- by them, a moment earlier -- and pressing
		// the button answered "that handle is taken"
		setServerData({ needsAccount: true, suggestedHandle: 'alice' })
		mountApp()

		expect(accountStore.fetchCurrentAccountInfo).not.toHaveBeenCalled()
	})

	/** A video the reader left the page of while it played goes on in the corner, whatever the page. */
	describe('the small player', () => {
		afterEach(() => {
			closeMiniPlayer()
		})

		it('is drawn while it holds a video, keyed by it, and goes when it is closed', async () => {
			const wrapper = mountApp()
			expect(wrapper.findComponent({ name: 'MiniPlayer' }).exists()).toBe(false)

			handOff({ id: '42', account: { acct: 'alice' } }, 12)
			await flushPromises()
			const player = wrapper.findComponent({ name: 'MiniPlayer' })
			expect(player.exists()).toBe(true)
			expect(player.vm.$.vnode.key).toBe('42')

			route.value = { name: 'profile', params: {}, fullPath: '/@bob' }
			await flushPromises()
			expect(wrapper.findComponent({ name: 'MiniPlayer' }).exists()).toBe(true)

			closeMiniPlayer()
			await flushPromises()
			expect(wrapper.findComponent({ name: 'MiniPlayer' }).exists()).toBe(false)
		})
	})

	it('renders the navigation and the routed view inside the app content', () => {
		const wrapper = mountApp()
		expect(wrapper.find('.content-stub').attributes('data-app-name')).toBe('social')
		expect(wrapper.find('.content-stub').classes()).not.toContain('public')
		expect(wrapper.find('.navigation-stub').exists()).toBe(true)
		expect(wrapper.find('.app-content-stub .router-view-stub').exists()).toBe(true)
		expect(wrapper.find('.setup').exists()).toBe(false)
	})

	it('drops the navigation and skips the account lookup on the public page', () => {
		setServerData({ public: true })
		const wrapper = mountApp()
		expect(wrapper.find('.content-stub').classes()).toContain('public')
		expect(wrapper.find('.navigation-stub').exists()).toBe(false)
		expect(wrapper.find('.router-view-stub').exists()).toBe(true)
		expect(accountStore.fetchCurrentAccountInfo).not.toHaveBeenCalled()
	})

	it('warns administrators about a broken .well-known setup', () => {
		setServerData({ isAdmin: true, checks: { success: false, checks: { wellknown: false } } })
		const wrapper = mountApp()
		expect(wrapper.find('.setup h3').text()).toBe('.well-known/webfinger isn\'t properly set up!')
		expect(wrapper.find('.setup a.external_link').attributes('href')).toContain('admin-setup-well-known-URL')
		expect(wrapper.find('.router-view-stub').exists()).toBe(true)
	})

	it('does not show the .well-known warning to regular users', () => {
		setServerData({ isAdmin: false, checks: { success: false, checks: { wellknown: false } } })
		expect(mountApp().find('.setup').exists()).toBe(false)
	})

	describe('searching', () => {
		it('takes the term to the search route rather than filtering what is loaded', () => {
			const wrapper = mountApp()
			wrapper.findComponent(stubs.Navigation).vm.$emit('search', 'fediverse')

			expect(timelineStore.searchQuery).toBe('fediverse')
			expect(router.push).toHaveBeenCalledWith({ name: 'search', params: { term: 'fediverse' } })
		})

		it('marks a submitted search, which may look somebody up elsewhere', () => {
			const wrapper = mountApp()
			wrapper.findComponent(stubs.Navigation).vm.$emit('search', '@bob@remote.example', true)

			expect(router.push).toHaveBeenCalledWith({ name: 'search', params: { term: '@bob@remote.example' }, query: { lookup: '1' } })
		})

		it('refines the term in place, so Back does not walk out through the keystrokes', () => {
			route = shallowRef({ name: 'search', params: { term: 'fedi' }, fullPath: '/search/fedi' })
			router.replace = vi.fn()
			const wrapper = mountApp()

			wrapper.findComponent(stubs.Navigation).vm.$emit('search', 'fediverse')

			expect(router.replace).toHaveBeenCalledWith({ name: 'search', params: { term: 'fediverse' } })
			expect(router.push).not.toHaveBeenCalled()
		})

		it('leaves the search route when the box is emptied', () => {
			route = shallowRef({ name: 'search', params: { term: 'fedi' }, fullPath: '/search/fedi' })
			const wrapper = mountApp()

			wrapper.findComponent(stubs.Navigation).vm.$emit('search', '   ')

			expect(timelineStore.searchQuery).toBe('')
			expect(router.push).toHaveBeenCalledWith({ name: 'timeline' })
		})

		it('goes nowhere when the box is emptied off the search route', () => {
			const wrapper = mountApp()
			wrapper.findComponent(stubs.Navigation).vm.$emit('search', '')

			expect(router.push).not.toHaveBeenCalled()
		})

		it('keeps the stored term in step with the route', async () => {
			mountApp()

			route.value = { name: 'search', params: { term: 'fediverse' }, fullPath: '/search/fediverse' }
			await nextTick()
			expect(timelineStore.searchQuery).toBe('fediverse')

			route.value = { name: 'timeline', params: { type: 'federated' }, fullPath: '/timeline/federated' }
			await nextTick()
			expect(timelineStore.searchQuery).toBe('')
		})
	})

	describe('while a page is being waited for', () => {
		let hooks

		beforeEach(() => {
			vi.useFakeTimers()
			hooks = {}
			router = {
				push: vi.fn(),
				beforeEach: vi.fn((hook) => {
					hooks.before = hook
					return () => {}
				}),
				beforeResolve: vi.fn((hook) => {
					hooks.resolve = hook
					return () => {}
				}),
			}
		})

		afterEach(() => {
			vi.useRealTimers()
		})

		const skeleton = (wrapper) => wrapper.findComponent({ name: 'TimelineSkeleton' })

		async function startNavigation(wrapper, to) {
			hooks.before(to, route.value, () => {})
			vi.advanceTimersByTime(200)
			await nextTick()
		}

		it('draws the placeholder the route being opened names, as a whole page', async () => {
			const wrapper = mountApp()
			await startNavigation(wrapper, { name: 'timeline', params: { type: 'videos' }, meta: { skeleton: { variant: 'grid', shape: 'video' } } })

			expect(skeleton(wrapper).props()).toMatchObject({ variant: 'grid', shape: 'video', standalone: true })
			expect(skeleton(wrapper).classes()).toContain('social__pending')
		})

		// Discover opened behind three post cards before its map appeared
		it('draws the neutral page for a route that names none', async () => {
			const wrapper = mountApp()
			await startNavigation(wrapper, { name: 'discover', params: {}, meta: {} })

			expect(skeleton(wrapper).props('variant')).toBe('page')
			expect(skeleton(wrapper).find('.skeleton__row').exists()).toBe(false)
		})

		it('draws nothing for a page that arrives quickly, and goes when it lands', async () => {
			const wrapper = mountApp()
			hooks.before({ name: 'discover', params: {}, meta: {} }, route.value, () => {})
			vi.advanceTimersByTime(50)
			await nextTick()
			expect(skeleton(wrapper).exists()).toBe(false)

			vi.advanceTimersByTime(200)
			await nextTick()
			expect(skeleton(wrapper).exists()).toBe(true)

			hooks.resolve({ name: 'discover', params: {}, fullPath: '/discover' }, route.value)
			await nextTick()
			expect(skeleton(wrapper).exists()).toBe(false)
		})
	})

	it('does not remount the whole view on every route change', () => {
		// :key="$route.fullPath" refetched page one of the timeline and
		// landed at the top for every navigation, Back included
		const source = readFileSync(resolve(process.cwd(), 'src/App.vue'), 'utf8')
		expect(source).not.toMatch(/<router-view[^>]*:key=/)
	})

	/**
	 * Moving between the pages the sidebar lists is animated, and the two
	 * halves of that have to stay together: `out-in`, so the incoming page is
	 * not pushed down the height of the outgoing one for a frame, and no key,
	 * so the same view handed new params is left alone rather than faded.
	 */
	/**
	 * The two pages share one grid cell and overlap, so the incoming one does
	 * not wait for the outgoing one: `out-in` added the whole leave to every
	 * change, which on a page already waiting for its chunk is the wrong place
	 * to spend a tenth of a second. Still no key on the router view, which is
	 * what keeps the same view being handed new params from being a fade.
	 */
	it('animates the change from one page to another, the two overlapping', () => {
		const source = readFileSync(resolve(process.cwd(), 'src/App.vue'), 'utf8')

		expect(source).toMatch(/<transition :name="transitionName">/)
		expect(source).not.toMatch(/mode="out-in"/)
		expect(source).toMatch(/\.social__pages \{[^}]*display: grid/)
		expect(source).toMatch(/grid-area: 1 \/ 1/)
	})

	/**
	 * The grid is at least as tall as the window, and with the default
	 * `normal` its single row takes the leftover height and sits in the middle
	 * of it -- so a page with little on it, which in practice means an empty
	 * one, floated halfway down the window with a screenful of nothing above
	 * it, and the composer went with it. `align-self` on the page does not
	 * help: what is centred is the row, not the item in it.
	 */
	it('lays a page out from the top, however little is on it', () => {
		const source = readFileSync(resolve(process.cwd(), 'src/App.vue'), 'utf8')

		expect(source).toMatch(/\.social__pages \{[^}]*align-content: start/s)
	})

	/** Down the sidebar one way, back up it the other. */
	it('gives the change a direction', () => {
		const source = readFileSync(resolve(process.cwd(), 'src/App.vue'), 'utf8')

		expect(source).toMatch(/\.page-forward-enter-from/)
		expect(source).toMatch(/\.page-back-enter-from/)
	})

	/**
	 * Where the browser can animate the change itself it does, and the Vue
	 * transition stands down rather than playing the same move twice.
	 */
	/**
	 * Scoped to the page, not to the window. `root` is a picture of the whole
	 * thing, so animating it slid the sidebar, the header and the search box
	 * across with the content -- which is what a reader saw and reported.
	 */
	it('lets the browser animate the change where it can, and only the page', () => {
		const source = readFileSync(resolve(process.cwd(), 'src/App.vue'), 'utf8')

		expect(source).toMatch(/view-transition-name: social-page/)
		expect(source).toMatch(/::view-transition-old\(social-page\)/)
		expect(source).toMatch(/::view-transition-new\(social-page\)/)
		expect(source).toMatch(/canViewTransition\(\)/)
	})

	it('holds the rest of the window still while the page changes', () => {
		const source = readFileSync(resolve(process.cwd(), 'src/App.vue'), 'utf8')
		const root = source.slice(source.indexOf('::view-transition-old(root)'))

		expect(root).toMatch(/::view-transition-old\(root\),\s*\n::view-transition-new\(root\) \{\s*\n\tanimation: none;/)
	})

	it('leaves all of it alone for a reader who asked their system for less', () => {
		const source = readFileSync(resolve(process.cwd(), 'src/App.vue'), 'utf8')

		expect(source).toMatch(/prefers-reduced-motion: reduce/)
	})

	// two blocks saying the same thing drift apart the first time one is edited
	it('says how the page change looks with less motion in one place', () => {
		const source = readFileSync(resolve(process.cwd(), 'src/App.vue'), 'utf8')
		const blocks = source.split('@media (prefers-reduced-motion: reduce)').slice(1)

		expect(blocks.filter((block) => block.slice(0, block.indexOf('\n}')).includes('.page-enter-active'))).toHaveLength(1)
	})

	describe('push notifications', () => {
		const status = { id: 's1', content: '<p>live</p>', created_at: '2026-03-01T10:00:00Z' }
		let addCallback

		beforeEach(() => {
			addCallback = vi.fn()
			globalThis.OCA.Push = { isEnabled: () => true, addCallback }
		})

		it('registers for the social push channel when the push app is enabled', () => {
			mountApp()
			expect(addCallback).toHaveBeenCalledTimes(1)
			expect(addCallback.mock.calls[0][1]).toBe('social')
		})

		it('adds pushed home posts only while the home timeline is shown', () => {
			mountApp()
			const [callback] = addCallback.mock.calls[0]
			callback({ source: 'timeline.home', payload: status })
			expect(timelineStore.addToTimeline).toHaveBeenCalledWith([status])
			expect(timelineStore.timeline).toEqual(['s1'])

			timelineStore.addToTimeline.mockClear()
			callback({ source: 'timeline.direct', payload: { ...status, id: 's2' } })
			expect(timelineStore.addToTimeline).not.toHaveBeenCalled()
		})

		it('adds pushed direct messages on the direct timeline', () => {
			route.value = { name: 'timeline', params: { type: 'direct' }, fullPath: '/timeline/direct' }
			mountApp()
			const [callback] = addCallback.mock.calls[0]
			callback({ source: 'timeline.direct', payload: status })
			expect(timelineStore.addToTimeline).toHaveBeenCalledWith([status])
			timelineStore.addToTimeline.mockClear()
			callback({ source: 'timeline.home', payload: status })
			expect(timelineStore.addToTimeline).not.toHaveBeenCalled()
		})

		it('does not register when the push app is disabled', () => {
			globalThis.OCA.Push = { isEnabled: () => false, addCallback }
			mountApp()
			expect(addCallback).not.toHaveBeenCalled()
		})
	})

	describe('before the setup is finished', () => {
		/**
		 * What `NavigationController::navigate()` sent an administrator before
		 * any address was set: no `checks` and no `cloudAddress`. The setup form
		 * read `checks.success` and threw, and the page stayed blank.
		 */
		const setupPayload = {
			public: false,
			firstrun: false,
			needsAccount: false,
			setup: true,
			isAdmin: true,
			cliUrl: 'https://cloud.example.org',
			nsfwPolicy: 'default',
			nsfwChoice: '',
			sections: { stories: true, section_photos: true, section_videos: true, section_news: true },
		}

		it('draws the setup form from the payload the server sends', () => {
			setInitialState('social', 'serverData', {
				...setupPayload,
				proposedCloudAddress: 'https://cloud.example.org',
				proposedFrom: 'overwrite.cli.url',
				proposedProblems: [],
			})
			window._nc_initial_state?.clear()
			const wrapper = mountApp()

			expect(wrapper.find('.setup h2').text()).toBe('Confirm the address of this server')
			expect(wrapper.find('input.setup-input').element.value).toBe('https://cloud.example.org')
			expect(wrapper.find('.navigation-stub').exists()).toBe(false)
			expect(wrapper.find('.router-view-stub').exists()).toBe(false)
		})

		it('proposes the address of the page when the server has nothing better', () => {
			setServerData({ setup: true, isAdmin: true })
			const wrapper = mountApp()

			expect(wrapper.find('input.setup-input').element.value).toBe('https://cloud.example.org')
		})

		it('reloads the app once the address is stored', async () => {
			setServerData({ setup: true, isAdmin: true, proposedCloudAddress: 'https://social.example.org' })
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { cloudAddress: 'https://social.example.org' } })
			const wrapper = mountApp()
			const reload = vi.spyOn(wrapper.vm, 'reloadTo').mockImplementation(() => {})

			await wrapper.find('form').trigger('submit')
			await flushPromises()

			expect(post).toHaveBeenCalledWith('/index.php/apps/social/api/v1/config/cloudAddress', { cloudAddress: 'https://social.example.org', allowUnsafe: false })
			expect(reload).toHaveBeenCalledWith('/index.php/apps/social/')
		})

		it('also shows the .well-known warning on the setup form', () => {
			setServerData({ setup: true, isAdmin: true, checks: { success: false, checks: { wellknown: false } } })
			expect(mountApp().find('form h3').text()).toBe('.well-known/webfinger isn\'t properly set up!')
		})

		it('tells regular users to wait for the administrator', () => {
			setServerData({ setup: true, isAdmin: false })
			const wrapper = mountApp()
			expect(wrapper.find('.setup').text()).toBe('The Aloha Social app needs to be set up by the server administrator.')
			expect(wrapper.find('form').exists()).toBe(false)
			expect(wrapper.find('.router-view-stub').exists()).toBe(false)
		})
	})

	describe('a visitor reading a public page', () => {
		/**
		 * Open since 2019 (#784). Somebody who follows a link to a public post
		 * has no sidebar and therefore no way back into their own Nextcloud --
		 * they had to know to go to the front page and find the app there.
		 */
		it('is offered a way in, naming the server they are on', async () => {
			setServerData({ public: true })
			const wrapper = mountApp()
			await flushPromises()

			const bar = wrapper.find('.social__visitor')
			expect(bar.exists()).toBe(true)
			expect(bar.text()).toContain('You are reading this as a visitor.')
			expect(bar.text()).toContain('Log in to')
		})

		/** They come back to the page they were reading, not to a dashboard. */
		it('carries the page being read through the login', async () => {
			setServerData({ public: true })
			const wrapper = mountApp()
			await flushPromises()

			const href = wrapper.find('.social__visitor a').attributes('href')
				?? wrapper.find('.social__visitor').html()
			expect(href).toContain('/login')
			expect(href).toContain('redirect_url')
		})

		it('says none of this to somebody who is signed in', async () => {
			setServerData({ public: false })
			const wrapper = mountApp()
			await flushPromises()

			expect(wrapper.find('.social__visitor').exists()).toBe(false)
		})
	})

	// a scope of the feed is a filter on the page, not another page: the
	// whole column sliding and fading moved the switcher out from under the
	// pointer that had just used it
	describe('animating a page change', () => {
		let resolve
		let startViewTransition

		beforeEach(() => {
			router = {
				push: vi.fn(),
				beforeEach: vi.fn(() => () => {}),
				beforeResolve: vi.fn((hook) => {
					resolve = hook

					return () => {}
				}),
			}
			startViewTransition = vi.fn((update) => {
				update()
			})
			document.startViewTransition = startViewTransition
		})

		afterEach(() => {
			delete document.startViewTransition
		})

		const feed = (type) => ({ name: 'timeline', params: type === undefined ? {} : { type } })

		it.each([
			[undefined, 'interests'],
			['interests', 'timeline'],
			['timeline', 'federated'],
			['federated', undefined],
		])('does not animate a switch of scope, %s to %s', (from, to) => {
			mountApp()

			expect(resolve(feed(to), feed(from))).toBe(true)
			expect(startViewTransition).not.toHaveBeenCalled()
		})

		it('still animates a move to another sidebar entry', async () => {
			mountApp()

			await resolve(feed('photos'), feed('federated'))

			expect(startViewTransition).toHaveBeenCalledTimes(1)
		})
	})
})
